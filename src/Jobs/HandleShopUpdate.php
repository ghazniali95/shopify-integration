<?php

namespace ShopGPT\ShopifyIntegration\Jobs;

use ShopGPT\ShopifyIntegration\Events\StoreProfileUpdated;
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

        $previousPlan = $store->plan_name;

        $profile = $writer->profileFrom($this->payload);

        $store->forceFill($profile)->saveQuietly();

        StoreProfileUpdated::dispatch(
            $store,
            $this->changedFrom($profile),
            $previousPlan,
        );
    }

    /**
     * Which of the promoted columns this webhook actually carried a value
     * for. shop/update fires for changes the app does not care about, so a
     * listener needs to know what moved rather than re-diffing the row.
     */
    private function changedFrom(array $profile): array
    {
        unset($profile['integration_shop_data'], $profile['integration_shop_data_synced_at']);

        return array_keys(array_filter($profile, fn ($value) => $value !== null));
    }
}
