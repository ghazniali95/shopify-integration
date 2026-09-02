<?php

namespace ShopGPT\ShopifyIntegration\Exceptions;

class OAuthException extends ShopifyIntegrationException
{
    public static function tokenExchangeFailed(string $reason): self
    {
        return new self('Could not obtain an access token: '.$reason);
    }
}
