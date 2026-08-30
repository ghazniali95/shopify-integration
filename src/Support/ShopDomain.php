<?php

namespace ShopGPT\ShopifyIntegration\Support;

/**
 * Validation for `*.myshopify.com` hostnames.
 *
 * Every entry point takes a `shop` parameter from an untrusted request and
 * uses it to build a URL we then send credentials to. Validating it here is
 * what stops a crafted `shop` value pointing the OAuth redirect, or an API
 * call, at an attacker's host.
 */
class ShopDomain
{
    private const PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/';

    public static function isValid(?string $shop): bool
    {
        return is_string($shop) && $shop !== '' && (bool) preg_match(self::PATTERN, $shop);
    }

    /**
     * Normalise what a merchant or a Shopify redirect might send — a full URL,
     * a trailing slash, mixed case — into a bare lowercase hostname.
     * Returns null when the result is not a valid Shopify host.
     */
    public static function normalise(?string $shop): ?string
    {
        if (! is_string($shop) || trim($shop) === '') {
            return null;
        }

        $shop = trim(strtolower($shop));
        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = explode('/', $shop)[0];
        $shop = explode('?', $shop)[0];

        return self::isValid($shop) ? $shop : null;
    }
}
