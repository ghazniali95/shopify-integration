<?php

namespace ShopGPT\ShopifyIntegration\Exceptions;

use ShopGPT\ShopifyIntegration\Models\Integration;

class ShopifyApiException extends ShopifyIntegrationException
{
    public function __construct(
        public readonly Integration $store,
        string $message,
        int $code = 0,
        public readonly ?string $body = null,
    ) {
        parent::__construct($message, $code);
    }
}
