<?php

namespace ShopGPT\ShopifyIntegration\Support;

use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;

/**
 * Questions about a store that are logic rather than storage.
 *
 * These used to live on the package's model. They read only through the
 * ShopifyStore contract now, so they answer the same way whatever your app
 * keeps a store in.
 */
class StoreState
{
    /**
     * Refresh this many seconds before a token actually expires.
     *
     * Not configurable: the window only has to outlast one in-flight request,
     * and a value low enough to matter is a value low enough to break.
     */
    private const REFRESH_BUFFER = 300;

    public static function hasValidToken(ShopifyStore $store): bool
    {
        return ! empty($store->shopifyAccessToken()) && $store->shopifyIsInstalled();
    }

    /**
     * A null expiry is a legacy permanent token: valid, and never refreshed.
     */
    public static function tokenExpiresSoon(ShopifyStore $store, ?int $bufferSeconds = null): bool
    {
        $expiresAt = $store->shopifyTokenExpiresAt();

        if ($expiresAt === null) {
            return false;
        }

        return $expiresAt->getTimestamp() - ($bufferSeconds ?? self::REFRESH_BUFFER) <= time();
    }

    /**
     * Whether the granted scopes still cover what the app now asks for.
     *
     * Adding a scope to config does not change tokens already issued. Without
     * this check the merchant keeps a token missing the new scope and hits
     * 403s at whichever call site needed it, which reads as a random bug.
     */
    public static function hasRequiredScopes(ShopifyStore $store, ?string $required = null): bool
    {
        $required = $required ?? (string) config('shopifyIntegration.scopes', '');

        $granted = self::splitScopes($store->shopifyScopes());
        $needed  = self::splitScopes($required);

        return empty(array_diff($needed, $granted));
    }

    /**
     * Whether the merchant has to be sent back through OAuth — either the
     * token is gone, or the app now asks for scopes this token was never
     * granted.
     */
    public static function needsReauthorization(ShopifyStore $store): bool
    {
        return ! self::hasValidToken($store) || ! self::hasRequiredScopes($store);
    }

    /** @return array<int, string> */
    private static function splitScopes(?string $scopes): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $scopes))));
    }
}
