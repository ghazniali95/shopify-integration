<?php

namespace ShopGPT\ShopifyIntegration\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A merchant hit the install route and is about to be sent to Shopify. */
class OAuthStarted
{
    use Dispatchable;

    public function __construct(public readonly string $shop)
    {
    }
}
