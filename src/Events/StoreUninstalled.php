<?php

namespace ShopGPT\ShopifyIntegration\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use ShopGPT\ShopifyIntegration\Models\Integration;
use ShopGPT\ShopifyIntegration\Support\InstallContext;

/** app/uninstalled arrived, or an API call returned 401. */
class StoreUninstalled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Integration $store,
        public readonly ?InstallContext $context = null,
    ) {
    }
}
