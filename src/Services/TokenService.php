<?php

namespace ShopGPT\ShopifyIntegration\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;
use ShopGPT\ShopifyIntegration\Events\StoreTokenRefreshed;
use ShopGPT\ShopifyIntegration\Events\TokenRefreshFailed;
use ShopGPT\ShopifyIntegration\Exceptions\TokenRefreshException;
use ShopGPT\ShopifyIntegration\Support\StoreState;
use Throwable;

class TokenService
{
    /** Seconds a second caller waits for the refresh already in flight. */
    private const LOCK_WAIT = 10;

    public function __construct(
        private readonly ShopifyStoreRepository $stores,
    ) {
    }

    /**
     * Guarantee a usable access token before a Shopify call.
     *
     * Every path into the Admin API runs through here, so callers never think
     * about expiry. A store with no expiry holds a legacy permanent token and
     * is returned untouched.
     */
    public function ensureFresh(ShopifyStore $store): ShopifyStore
    {
        if ($store->shopifyTokenExpiresAt() === null || ! StoreState::tokenExpiresSoon($store)) {
            return $store;
        }

        return $this->refresh($store);
    }

    /**
     * Exchange the refresh token for a new access token.
     *
     * Serialised per store. Shopify issues a replacement refresh token with
     * every refresh and expects the newest one to be the only one in use, so
     * two workers refreshing the same store concurrently would leave whichever
     * finished second storing a pair that has already been superseded.
     *
     * @throws TokenRefreshException when the refresh fails and the token held
     *         has already expired, so the caller cannot proceed.
     */
    public function refresh(ShopifyStore $store): ShopifyStore
    {
        $lock = $this->lock($store);

        if ($lock === null) {
            return $this->performRefresh($store);
        }

        try {
            // Waiting rather than failing: the other worker is about to store
            // a token this caller can use.
            $lock->block(self::LOCK_WAIT);
        } catch (Throwable) {
            // Held too long to be worth waiting on. Refreshing anyway risks a
            // wasted round trip, which beats failing the merchant's request.
            return $this->performRefresh($store);
        }

        try {
            // The winner has already written a token; re-read rather than
            // spend this store's second refresh on the same expiry.
            $store = $this->stores->findByKey($store->getKey()) ?? $store;

            if (! StoreState::tokenExpiresSoon($store)) {
                return $store;
            }

            return $this->performRefresh($store);
        } finally {
            $lock->release();
        }
    }

    private function performRefresh(ShopifyStore $store): ShopifyStore
    {
        $refreshToken = $store->shopifyRefreshToken();

        if (empty($refreshToken)) {
            return $this->giveUp($store, 'no refresh token stored');
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://{$store->shopifyDomain()}/admin/oauth/access_token", [
                    'client_id'     => config('shopifyIntegration.client_id'),
                    'client_secret' => config('shopifyIntegration.client_secret'),
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);
        } catch (Throwable $e) {
            return $this->giveUp($store, $e->getMessage());
        }

        if ($response->failed() || ! $response->json('access_token')) {
            return $this->giveUp($store, "HTTP {$response->status()}");
        }

        // Written together: Shopify treats the returned refresh token as the
        // replacement for the one just used, so storing the access token
        // without it would strand the store on a spent credential.
        $store = $this->stores->updateTokens($store, [
            'access_token'  => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token') ?: $refreshToken,
            'expires_in'    => $response->json('expires_in'),
        ]);

        StoreTokenRefreshed::dispatch($store);

        return $store;
    }

    /**
     * The lock that serialises refreshes for one store.
     *
     * Null when the cache driver cannot do atomic locks — `null` and `array`
     * among the built-ins. Refreshing unserialised is worse than not
     * refreshing at all only in theory; failing the request outright because
     * of a cache driver choice is worse in practice.
     */
    private function lock(ShopifyStore $store): ?Lock
    {
        $cache = Cache::store();

        if (! $cache->getStore() instanceof LockProvider) {
            return null;
        }

        return $cache->lock("shopifyIntegration.refresh.{$store->getKey()}", 30);
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
    private function giveUp(ShopifyStore $store, string $reason): ShopifyStore
    {
        $expiresAt = $store->shopifyTokenExpiresAt();
        $stillGood = ! empty($store->shopifyAccessToken())
            && $expiresAt !== null
            && $expiresAt->getTimestamp() > time();

        if ($stillGood) {
            Log::warning('shopifyIntegration: token refresh failed, continuing with the current token', [
                'store'      => $store->shopifyDomain(),
                'reason'     => $reason,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ]);

            TokenRefreshFailed::dispatch($store, $reason, false);

            return $store;
        }

        Log::error('shopifyIntegration: token refresh failed and the stored token has expired', [
            'store'  => $store->shopifyDomain(),
            'reason' => $reason,
        ]);

        TokenRefreshFailed::dispatch($store, $reason, true);

        throw new TokenRefreshException($store, $reason);
    }
}
