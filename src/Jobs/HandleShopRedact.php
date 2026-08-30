<?php

namespace ShopGPT\ShopifyIntegration\Jobs;

use Illuminate\Support\Facades\Log;

/**
 * Shopify asks for the store's data to be erased, 48 hours after uninstall.
 *
 * Clears the raw shop.json snapshot AND the promoted PII columns — the same
 * merchant details live in both places, and clearing only one is the mistake
 * that fails App Store review.
 *
 * Anything your own app copied onto a User row is yours to redact: listen for
 * this job's topic, or the StoreUninstalled event, and handle it there.
 */
class HandleShopRedact extends WebhookJob
{
    public function handle(): void
    {
        $store = $this->store();

        if (! $store) {
            return;
        }

        $store->redact();

        Log::channel(config('shopifyIntegration.webhooks.log_channel'))
            ->info('shopifyIntegration: shop redacted', ['store' => $store->store_domain]);
    }
}
