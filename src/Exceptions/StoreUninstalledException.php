<?php

namespace ShopGPT\ShopifyIntegration\Exceptions;

use ShopGPT\ShopifyIntegration\Models\Integration;

/**
 * The merchant removed the app. Distinct from a store being frozen or locked,
 * which is temporary and must NOT flag the store as uninstalled.
 */
class StoreUninstalledException extends ShopifyIntegrationException
{
    public function __construct(public readonly Integration $store)
    {
        parent::__construct("Shopify app is no longer installed on {$store->store_domain}.", 401);
    }
}
