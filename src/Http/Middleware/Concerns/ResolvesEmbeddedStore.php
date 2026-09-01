<?php

namespace ShopGPT\ShopifyIntegration\Http\Middleware\Concerns;

use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;
use ShopGPT\ShopifyIntegration\Services\TokenExchangeService;
use ShopGPT\ShopifyIntegration\Support\StoreState;

trait ResolvesEmbeddedStore
{
    /**
     * The store a verified session token speaks for, obtaining an access
     * token by exchange if the one on file is missing or unusable.
     *
     * Returns null only when Shopify itself declines the exchange, which
     * means the app really is not installed for that store.
     */
    protected function storeFor(string $shop, string $sessionToken): ?ShopifyStore
    {
        $store = app(ShopifyStoreRepository::class)->findByDomain($shop);

        if ($this->usable($store)) {
            return $store;
        }

        // Also the scope-upgrade path: a merchant who approved new scopes
        // through Shopify's managed install has granted them already, and the
        // exchange returns a token carrying them. Only if it comes back still
        // short of what the app asks for is a redirect through OAuth needed.
        return app(TokenExchangeService::class)->exchange($shop, $sessionToken);
    }

    /**
     * Whether the stored token can still be used or renewed without the
     * merchant being involved.
     */
    protected function usable(?ShopifyStore $store): bool
    {
        if (! $store || ! StoreState::hasValidToken($store) || ! StoreState::hasRequiredScopes($store)) {
            return false;
        }

        // Expired with nothing to refresh from. TokenService could not renew
        // this, so exchange it now rather than failing at the first API call.
        $expiresAt = $store->shopifyTokenExpiresAt();
        $expired   = $expiresAt !== null && $expiresAt->getTimestamp() <= time();

        return ! ($expired && empty($store->shopifyRefreshToken()));
    }
}
