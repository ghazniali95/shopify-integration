<?php

namespace ShopGPT\ShopifyIntegration\Jobs;

use Illuminate\Support\Facades\Log;

/**
 * A customer asked what data the app holds on them.
 *
 * This package stores no customer data. If your app does, override this in
 * config and deliver it to the merchant within 30 days.
 */
class HandleCustomersDataRequest extends WebhookJob
{
    public function handle(): void
    {
        Log::channel(config('shopifyIntegration.webhooks.log_channel'))->info(
            'shopifyIntegration: customers/data_request received',
            ['store_id' => $this->storeId, 'customer' => $this->payload['customer']['id'] ?? null],
        );
    }
}
