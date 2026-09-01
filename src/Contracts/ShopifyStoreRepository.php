<?php

namespace ShopGPT\ShopifyIntegration\Contracts;

/**
 * Every read and write this package makes against your storage.
 *
 * Bind your own implementation to take full control of the table — most
 * importantly of the INSERT, which is the one write that may need data the
 * package has no business knowing about (an owning user id, a tenant, a plan).
 *
 * The shipped EloquentStoreRepository covers this for an ordinary Eloquent
 * model; extend it and override one method rather than writing all nine.
 */
interface ShopifyStoreRepository
{
    public function findByKey(int|string $key): ?ShopifyStore;

    public function findByDomain(string $domain): ?ShopifyStore;

    public function findByExternalId(string $externalId): ?ShopifyStore;

    /**
     * Create or update the store an OAuth install or token exchange produced.
     *
     * Called with a store that already exists on a reinstall, and with null on
     * a first install — so an implementation that needs to create an owning
     * record has everything it needs, before anything is written.
     *
     * @param  array{access_token: string, refresh_token: ?string, expires_in: ?int, scope: ?string}  $token
     * @param  array<string, mixed>  $shopData  Raw shop.json, or [] if unavailable.
     */
    public function persistInstall(
        ?ShopifyStore $existing,
        string $shop,
        array $token,
        array $shopData,
    ): ShopifyStore;

    /**
     * Store a refreshed access/refresh token pair.
     *
     * Shopify treats the returned refresh token as the replacement for the one
     * just spent, so both must be written together or the store is stranded on
     * a dead credential.
     *
     * @param  array{access_token: string, refresh_token: ?string, expires_in: ?int}  $token
     */
    public function updateTokens(ShopifyStore $store, array $token): ShopifyStore;

    /**
     * Persist whatever of shop.json your storage cares to keep.
     *
     * Free to do nothing: the full payload reaches your listeners on the
     * StoreProfileUpdated event either way.
     *
     * @param  array<string, mixed>  $shopData
     * @return array{changed: array<int, string>, previous: array<string, mixed>}
     *         Which fields were actually stored, and what they held before —
     *         a listener cannot diff what it cannot see, and the store object
     *         it is handed already carries the new values.
     */
    public function updateProfile(ShopifyStore $store, array $shopData): array;

    public function updateScopes(ShopifyStore $store, ?string $scopes): ShopifyStore;

    /**
     * Record the uninstall and drop the credentials with it.
     *
     * The token is already dead — Shopify revokes it the moment the app is
     * removed — so keeping it stores a secret that can never be used again and
     * would still have to be disclosed in a breach.
     */
    public function markUninstalled(ShopifyStore $store): void;

    /** Clear merchant PII in response to a shop/redact webhook. */
    public function redact(ShopifyStore $store): void;
}
