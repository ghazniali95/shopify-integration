<?php

namespace ShopGPT\ShopifyIntegration\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use ShopGPT\ShopifyIntegration\Models\Integration;

/**
 * A token refresh failed.
 *
 * Fires whether or not the failure was fatal, so `$fatal` is the field to
 * branch on: false means the stored token is still valid and the next call
 * will retry, true means it has expired and the merchant must re-authorise.
 *
 * Without this the only trace of a store quietly losing its connection is a
 * line in the log.
 */
class TokenRefreshFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Integration $store,
        public readonly string $reason,
        public readonly bool $fatal = false,
    ) {
    }
}
