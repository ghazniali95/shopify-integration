<?php

namespace ShopGPT\ShopifyIntegration\Jobs;

use ShopGPT\ShopifyIntegration\Events\StoreUninstalled;

/**
 * The merchant removed the app.
 *
 * The token is now dead and Shopify has already dropped every webhook
 * subscription for this store, so a reinstall has to register them again.
 */
class HandleAppUninstalled extends WebhookJob
{
    public function handle(): void
    {
        $store = $this->store();

        if (! $store || ! $store->isInstalled()) {
            return;
        }

        $store->markUninstalled();

        StoreUninstalled::dispatch($store);
    }
}
