<?php

namespace ShopGPT\ShopifyIntegration\Services;

use Illuminate\Support\Facades\Http;
use ShopGPT\ShopifyIntegration\Exceptions\OAuthException;
use ShopGPT\ShopifyIntegration\Support\OAuthState;
use Throwable;

class OAuthService
{
    /**
     * Build the authorisation URL and issue a single-use state nonce.
     */
    public function authorizeUrl(string $shop): string
    {
        return "https://{$shop}/admin/oauth/authorize?".http_build_query([
            'client_id'    => config('shopifyIntegration.client_id'),
            'scope'        => config('shopifyIntegration.scopes'),
            'redirect_uri' => $this->redirectUri(),
            'state'        => OAuthState::issue($shop),
        ]);
    }

    /**
     * Exchange the authorisation code for an expiring offline access token.
     *
     * `expiring=1` is what makes Shopify return a refresh_token and expires_in
     * instead of a permanent token. Permanent offline tokens are on their way
     * out for public apps, and a rotating token is the safer default anyway.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, scope: ?string}
     *
     * @throws OAuthException
     */
    public function exchangeCode(string $shop, string $code): array
    {
        try {
            $response = Http::asForm()
                ->timeout(15)
                ->retry(2, 1000, throw: false)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id'     => config('shopifyIntegration.client_id'),
                    'client_secret' => config('shopifyIntegration.client_secret'),
                    'code'          => $code,
                    'expiring'      => '1',
                ]);
        } catch (Throwable $e) {
            throw OAuthException::tokenExchangeFailed($e->getMessage());
        }

        if ($response->failed() || ! $response->json('access_token')) {
            throw OAuthException::tokenExchangeFailed("HTTP {$response->status()}");
        }

        return [
            'access_token'  => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in'    => $response->json('expires_in'),
            'scope'         => $response->json('scope'),
        ];
    }

    /**
     * Fetch the store profile. Never fatal: an install that cannot read
     * shop.json is still a valid install with a working token, and the
     * profile can be filled in later.
     */
    public function fetchShopData(string $shop, string $accessToken): array
    {
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
                ->acceptJson()
                ->timeout(15)
                ->retry(2, 1000, throw: false)
                ->get("https://{$shop}/admin/api/".config('shopifyIntegration.api_version').'/shop.json');

            return $response->successful() ? (array) $response->json('shop', []) : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function redirectUri(): string
    {
        $prefix = trim((string) config('shopifyIntegration.routes.prefix', 'shopify'), '/');

        return rtrim((string) config('app.url'), '/')."/{$prefix}/auth/callback";
    }
}
