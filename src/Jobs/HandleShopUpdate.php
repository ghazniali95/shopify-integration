<?php

namespace ShopGPT\ShopifyIntegration\Jobs;

use ShopGPT\ShopifyIntegration\Events\StoreProfileUpdated;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;

/**
 * The merchant changed something about the store.
 *
 * Keeps the promoted profile columns honest. `plan_name` is the one that
 * matters: a store moving off a development plan changes whether billing has
 * to be created in test mode, and nothing else would tell you.
 */
class HandleShopUpdate extends WebhookJob
{
    public function handle(ShopifyStoreRepository $stores): void
    {
        $store = $this->store();

        if (! $store || $this->payload === []) {
            return;
        }

        // The repository decides what its table keeps, and reports back what
        // it stored and what those fields held before. The full payload
        // reaches the listener on the event either way.
        ['changed' => $changed, 'previous' => $previous] = $stores->updateProfile($store, $this->payload);

        StoreProfileUpdated::dispatch(
            $store,
            $changed,
            $previous['plan_name'] ?? null,
            $this->payload['plan_name'] ?? null,
        );
    }
}
