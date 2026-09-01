<?php

namespace ShopGPT\ShopifyIntegration\Exceptions;

use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;

/**
 * The store exists and the token is fine, but Shopify will not serve it right
 * now — a frozen store behind an unpaid bill (402), or one locked by Shopify
 * (423).
 *
 * Deliberately NOT a StoreUninstalledException. Both conditions are temporary
 * and resolve when the merchant pays or Shopify unlocks the store; treating
 * them as an uninstall would mark a paying customer's store dead and stop
 * every job for it, and nothing would ever mark it alive again.
 */
class StoreUnavailableException extends ShopifyApiException
{
    public function isFrozen(): bool
    {
        return $this->code === 402;
    }

    public function isLocked(): bool
    {
        return $this->code === 423;
    }
}
