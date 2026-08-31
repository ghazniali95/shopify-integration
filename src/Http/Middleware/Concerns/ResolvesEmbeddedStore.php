<?php

namespace ShopGPT\ShopifyIntegration\Http\Middleware\Concerns;

use ShopGPT\ShopifyIntegration\Models\Integration;
use ShopGPT\ShopifyIntegration\Services\TokenExchangeService;

trait ResolvesEmbeddedStore
{
    /**
     * The store a verified session token speaks for, obtaining an access
     * token by exchange if the one on file is missing or unusable.
     *
     * Returns null only when Shopify itself declines the exchange, which
     * means the app really is not installed for that store.
     */
    protected function storeFor(string $shop, string $sessionToken): ?Integration
    {
        $model = config('shopifyIntegration.model', Integration::class);

        $store = $model::forDomain($shop);

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
    protected function usable(?Integration $store): bool
    {
        if (! $store || ! $store->hasValidToken() || ! $store->hasRequiredScopes()) {
            return false;
        }

        // Expired with nothing to refresh from. TokenService could not renew
        // this, so exchange it now rather than failing at the first API call.
        return ! ($store->token_expires_at?->isPast() && empty($store->refresh_token));
    }
}
