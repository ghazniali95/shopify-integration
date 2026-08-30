<?php

namespace ShopGPT\ShopifyIntegration\Exceptions;

class RateLimitedException extends ShopifyApiException
{
    public ?int $retryAfter = null;
}
