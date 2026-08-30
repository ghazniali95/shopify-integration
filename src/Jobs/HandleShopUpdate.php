<?php

namespace ShopGPT\ShopifyIntegration\Jobs;

use ShopGPT\ShopifyIntegration\Services\StoreWriter;

/**
 * The merchant changed something about the store.
 *
 * Keeps the promoted profile columns honest. `plan_name` is the one that
 * matters: a store moving off a development plan changes whether billing has
 * to be created in test mode, and nothing else would tell you.
 */
class HandleShopUpdate extends WebhookJob
{
    public function handle(StoreWriter $writer): void
    {
        $store = $this->store();

        if (! $store || $this->payload === []) {
            return;
        }

        $store->forceFill($writer->profileFrom($this->payload))->saveQuietly();
    }
}
