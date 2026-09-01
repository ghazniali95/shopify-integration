<?php

namespace ShopGPT\ShopifyIntegration\Exceptions;

use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;

class TokenRefreshException extends ShopifyIntegrationException
{
    public function __construct(public readonly ShopifyStore $store, string $reason)
    {
        parent::__construct("Shopify token refresh failed for store {$store->store_domain}: {$reason}");
    }
}
