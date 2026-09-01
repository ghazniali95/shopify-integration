<?php

namespace ShopGPT\ShopifyIntegration\Jobs;

use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;
use ShopGPT\ShopifyIntegration\Events\StoreScopesUpdated;

/**
 * The scopes granted to the app changed.
 *
 * Under Shopify managed installation the merchant approves a scope change
 * inside the admin and the app is never called, so without this webhook the
 * stored scopes drift: hasRequiredScopes() keeps reporting a shortfall the
 * merchant has already fixed, and the app sends them back through an
 * authorisation they do not need.
 */
class HandleScopesUpdate extends WebhookJob
{
    public function handle(ShopifyStoreRepository $stores): void
    {
        $store = $this->store();

        if (! $store) {
            return;
        }

        $previous = $this->scopeList($this->payload['previous'] ?? []);
        $current  = $this->scopeList($this->payload['current'] ?? []);

        // A payload with no current scopes is not a revocation — Shopify sends
        // that when the app is being removed, and app/uninstalled is what
        // handles it. Writing an empty scope string here would leave a store
        // that reinstalls looking permanently under-scoped.
        if ($current === []) {
            return;
        }

        $store = $stores->updateScopes($store, implode(',', $current));

        StoreScopesUpdated::dispatch($store, $previous, $current);
    }

    /**
     * Shopify sends arrays here, but a comma-separated string is what the
     * rest of the OAuth surface uses, so accept either rather than write a
     * malformed scope column if the shape ever differs.
     *
     * @return list<string>
     */
    private function scopeList(mixed $scopes): array
    {
        if (is_string($scopes)) {
            $scopes = explode(',', $scopes);
        }

        if (! is_array($scopes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($scope) => is_string($scope) ? trim($scope) : '',
            $scopes,
        )));
    }
}
