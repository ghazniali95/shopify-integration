<?php

namespace ShopGPT\ShopifyIntegration\Exceptions;

use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;

class ShopifyApiException extends ShopifyIntegrationException
{
    public function __construct(
        public readonly ShopifyStore $store,
        string $message,
        int $code = 0,
        public readonly ?string $body = null,
    ) {
        parent::__construct($message, $code);
    }
}
