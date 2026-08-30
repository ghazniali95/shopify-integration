<?php

namespace ShopGPT\ShopifyIntegration\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

/** HMAC, state or the token exchange failed. */
class OAuthFailed
{
    use Dispatchable;

    public function __construct(
        public readonly ?string $shop,
        public readonly string $reason,
        public readonly ?Throwable $exception = null,
    ) {
    }
}
