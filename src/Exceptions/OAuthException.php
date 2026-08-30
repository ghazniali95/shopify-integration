<?php

namespace ShopGPT\ShopifyIntegration\Exceptions;

class OAuthException extends ShopifyIntegrationException
{
    public static function invalidShop(?string $shop): self
    {
        return new self('Invalid shop domain: '.($shop ?: '(none)'));
    }

    public static function invalidHmac(): self
    {
        return new self('HMAC verification failed.');
    }

    public static function invalidState(): self
    {
        return new self('OAuth state did not match.');
    }

    public static function tokenExchangeFailed(string $reason): self
    {
        return new self('Could not obtain an access token: '.$reason);
    }
}
