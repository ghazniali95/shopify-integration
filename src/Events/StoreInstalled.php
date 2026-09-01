<?php

namespace ShopGPT\ShopifyIntegration\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Support\InstallContext;

/** A store the app had never seen completed authorisation. */
class StoreInstalled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ShopifyStore $store,
        public readonly ?InstallContext $context = null,
    ) {
    }
}
