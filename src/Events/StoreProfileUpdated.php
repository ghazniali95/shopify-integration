<?php

namespace ShopGPT\ShopifyIntegration\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;

/**
 * A shop/update webhook refreshed the store's profile.
 *
 * `planChanged()` is the reason this event exists: a store moving off a
 * development plan decides whether a billing charge has to be created in test
 * mode, and nothing else in the lifecycle tells you it happened.
 */
class StoreProfileUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ShopifyStore $store,
        public readonly array $changed = [],
        public readonly ?string $previousPlan = null,
        public readonly ?string $currentPlan = null,
    ) {
    }

    public function planChanged(): bool
    {
        return $this->currentPlan !== null
            && $this->previousPlan !== $this->currentPlan;
    }
}
