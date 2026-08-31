<?php

namespace ShopGPT\ShopifyIntegration\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use ShopGPT\ShopifyIntegration\Models\Integration;

/**
 * An access token was obtained from a session token.
 *
 * Fires on every successful exchange: the silent first install of an embedded
 * app, and every later recovery after a token was revoked or expired beyond
 * refresh. StoreInstalled or StoreReinstalled fires alongside it when the
 * exchange was also the moment the app became installed.
 */
class StoreTokenExchanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Integration $store,
    ) {
    }
}
