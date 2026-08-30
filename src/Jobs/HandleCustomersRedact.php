<?php

namespace ShopGPT\ShopifyIntegration\Jobs;

use Illuminate\Support\Facades\Log;

/**
 * A customer of the merchant asked for their data to be erased.
 *
 * This package stores no customer data, so the default is a logged no-op that
 * satisfies the mandatory subscription. If your app stores customer records,
 * override this in config and delete them.
 */
class HandleCustomersRedact extends WebhookJob
{
    public function handle(): void
    {
        Log::channel(config('shopifyIntegration.webhooks.log_channel'))->info(
            'shopifyIntegration: customers/redact received',
            ['store_id' => $this->storeId, 'customer' => $this->payload['customer']['id'] ?? null],
        );
    }
}
