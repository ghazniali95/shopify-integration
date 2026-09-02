<?php

namespace ShopGPT\ShopifyIntegration\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The OAuth state nonce, kept in the cache and keyed by store domain.
 *
 * The cache rather than the session, always. An embedded app runs in a
 * third-party iframe where Safari and Chrome's privacy modes drop the session
 * cookie without warning — the state would simply be missing on the callback,
 * and every install would fail with an error that reproduces on nobody's
 * machine. A standalone app is served correctly by the cache too, so there is
 * nothing to choose between.
 */
class OAuthState
{
    /** The shape a nonce this class issued always has. */
    private const FORMAT = '/^[A-Za-z0-9]{40}$/';

    public static function issue(string $shop): string
    {
        $state = Str::random(40);

        // Keyed by the nonce as well as the shop, so two installs started for
        // the same store at once do not overwrite each other. Keyed by shop
        // alone, opening the install in a second tab silently invalidated the
        // first, and that tab's callback failed on a state it had been given.
        Cache::put(self::key($shop, $state), $state, self::ttl());

        return $state;
    }

    /**
     * Read and immediately delete the stored nonce — it is single-use, so a
     * replayed callback finds nothing.
     */
    public static function consume(string $shop, string $state): ?string
    {
        return Cache::pull(self::key($shop, $state));
    }

    public static function matches(string $shop, ?string $provided): bool
    {
        // The nonce is part of the storage key, so it reaches the cache before
        // it has been compared to anything. Checking the shape first is what
        // keeps an attacker-controlled query parameter from being used to
        // probe or pollute keys outside this namespace.
        if (! is_string($provided) || ! preg_match(self::FORMAT, $provided)) {
            return false;
        }

        $expected = self::consume($shop, $provided);

        return is_string($expected)
            && $expected !== ''
            && hash_equals($expected, $provided);
    }

    private static function key(string $shop, string $state): string
    {
        return 'shopifyIntegration:oauth_state:'.$shop.':'.$state;
    }

    private static function ttl(): int
    {
        return (int) config('shopifyIntegration.oauth.state_ttl', 300);
    }
}
