<?php

namespace ShopGPT\ShopifyIntegration\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;

/**
 * The scopes granted to the app changed.
 *
 * Under managed installation Shopify approves scope changes without calling
 * the app at all, so this webhook is the only thing that says so at the time
 * it happens rather than at the next token exchange.
 *
 * @property-read list<string> $previous
 * @property-read list<string> $current
 */
class StoreScopesUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ShopifyStore $store,
        public readonly array $previous = [],
        public readonly array $current = [],
    ) {
    }

    /** Scopes the merchant has granted that the app did not have before. */
    public function gained(): array
    {
        return array_values(array_diff($this->current, $this->previous));
    }

    /**
     * Scopes the app no longer has. Anything depending on one of these will
     * start returning 403 from the Admin API.
     */
    public function lost(): array
    {
        return array_values(array_diff($this->previous, $this->current));
    }
}
