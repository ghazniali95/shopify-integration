<?php

namespace ShopGPT\ShopifyIntegration\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ShopGPT\ShopifyIntegration\Events\StoreTokenRefreshed;
use ShopGPT\ShopifyIntegration\Exceptions\TokenRefreshException;
use ShopGPT\ShopifyIntegration\Models\Integration;
use Throwable;

class TokenService
{
    /**
     * Guarantee a usable access token before a Shopify call.
     *
     * Every path into the Admin API runs through here, so callers never think
     * about expiry. A store with no expiry holds a legacy permanent token and
     * is returned untouched.
     */
    public function ensureFresh(Integration $store): Integration
    {
        if ($store->token_expires_at === null || ! $store->tokenExpiresSoon()) {
            return $store;
        }

        return $this->refresh($store);
    }

    /**
     * Exchange the refresh token for a new access token.
     *
     * @throws TokenRefreshException when the refresh fails and the token held
     *         has already expired, so the caller cannot proceed.
     */
    public function refresh(Integration $store): Integration
    {
        if (empty($store->refresh_token)) {
            return $this->giveUp($store, 'no refresh token stored');
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://{$store->store_domain}/admin/oauth/access_token", [
                    'client_id'     => config('shopifyIntegration.client_id'),
                    'client_secret' => config('shopifyIntegration.client_secret'),
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $store->refresh_token,
                ]);
        } catch (Throwable $e) {
            return $this->giveUp($store, $e->getMessage());
        }

        if ($response->failed() || ! $response->json('access_token')) {
            return $this->giveUp($store, "HTTP {$response->status()}");
        }

        $store->forceFill([
            'integration_access_token'     => $response->json('access_token'),
            'integration_refresh_token'    => $response->json('refresh_token') ?: $store->refresh_token,
            'integration_token_expires_at' => $response->json('expires_in')
                ? now()->addSeconds((int) $response->json('expires_in'))
                : null,
        ])->saveQuietly();

        StoreTokenRefreshed::dispatch($store);

        return $store;
    }

    /**
     * Decide what a failed refresh means for the caller.
     *
     * Refreshes start refresh_buffer seconds before expiry, so a failure often
     * leaves a token that is still perfectly valid — hand it back and try
     * again on the next call. Once it has actually expired there is nothing
     * usable to return: handing the stale token over would send the caller
     * into a request that cannot succeed and log a second, more confusing
     * error for the same root cause.
     *
     * @throws TokenRefreshException
     */
    private function giveUp(Integration $store, string $reason): Integration
    {
        if (! empty($store->access_token) && $store->token_expires_at?->isFuture()) {
            Log::warning('shopifyIntegration: token refresh failed, continuing with the current token', [
                'store'      => $store->store_domain,
                'reason'     => $reason,
                'expires_at' => $store->token_expires_at->toDateTimeString(),
            ]);

            return $store;
        }

        Log::error('shopifyIntegration: token refresh failed and the stored token has expired', [
            'store'  => $store->store_domain,
            'reason' => $reason,
        ]);

        throw new TokenRefreshException($store, $reason);
    }
}
