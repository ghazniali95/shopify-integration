<?php

namespace ShopGPT\ShopifyIntegration\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The OAuth state nonce, kept in the cache and keyed by store domain.
 *
 * Not the session, by default. An embedded app runs in a third-party iframe
 * where Safari and Chrome's privacy modes drop the session cookie without
 * warning — the state would simply be missing on the callback, and every
 * install would fail with an error that reproduces on nobody's machine.
 */
class OAuthState
{
    public static function issue(string $shop): string
    {
        $state = Str::random(40);

        if (self::driver() === 'session') {
            session()->put(self::key($shop), $state);
        } else {
            Cache::put(self::key($shop), $state, self::ttl());
        }

        return $state;
    }

    /**
     * Read and immediately delete the stored nonce — it is single-use, so a
     * replayed callback finds nothing.
     */
    public static function consume(string $shop): ?string
    {
        $key = self::key($shop);

        if (self::driver() === 'session') {
            return session()->pull($key);
        }

        return Cache::pull($key);
    }

    public static function matches(string $shop, ?string $provided): bool
    {
        $expected = self::consume($shop);

        return is_string($expected)
            && is_string($provided)
            && $expected !== ''
            && hash_equals($expected, $provided);
    }

    private static function key(string $shop): string
    {
        return 'shopifyIntegration:oauth_state:'.$shop;
    }

    private static function driver(): string
    {
        return config('shopifyIntegration.oauth.state_store', 'cache');
    }

    private static function ttl(): int
    {
        return (int) config('shopifyIntegration.oauth.state_ttl', 300);
    }
}
