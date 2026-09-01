<?php

namespace ShopGPT\ShopifyIntegration\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;

/**
 * A store's myshopify domain changed.
 *
 * The row is matched on the Shopify shop id, so the package follows the
 * rename without creating a second store. Anything of yours that cached the
 * old domain — a URL, a queue name, a log filter, a customer record — will
 * not follow it on its own.
 */
class StoreRenamed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ShopifyStore $store,
        public readonly string $previousDomain,
        public readonly string $currentDomain,
    ) {
    }
}
