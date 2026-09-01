<?php

namespace ShopGPT\ShopifyIntegration\Services;

use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;
use ShopGPT\ShopifyIntegration\Events\StoreRenamed;

/**
 * The single place an install is turned into a stored store, so the OAuth
 * callback and token exchange cannot drift apart.
 *
 * It no longer writes anything itself — the repository does, and your app owns
 * the repository. What stays here is the part that is the same whatever the
 * storage: matching an install to an existing row, telling a first install
 * from a reinstall, and noticing a rename.
 */
class StoreWriter
{
    public function __construct(
        private readonly ShopifyStoreRepository $stores,
    ) {
    }

    /**
     * @param  array{access_token: string, refresh_token: ?string, expires_in: ?int, scope: ?string}  $token
     * @param  array<string, mixed>  $shopData  Raw shop.json, or [] if unavailable.
     * @return array{0: ShopifyStore, 1: bool, 2: bool}  [store, isNewInstall, isReinstall]
     */
    public function write(string $shop, array $token, array $shopData = []): array
    {
        $externalId = isset($shopData['id']) ? (string) $shopData['id'] : null;

        $existing = $this->resolve($externalId, $shop);

        $isNewInstall = $existing === null;
        // A row that exists but was uninstalled is a reinstall, not a new
        // install — the distinction decides whether onboarding runs again.
        $isReinstall = ! $isNewInstall && ! $existing->shopifyIsInstalled();

        // Captured before the write: the row is matched on the Shopify shop
        // id, so a renamed store is followed rather than duplicated — and
        // without this the old domain would be gone with nothing announcing it.
        $previousDomain = $existing?->shopifyDomain();

        $store = $this->stores->persistInstall($existing, $shop, $token, $shopData);

        if ($previousDomain !== null && $previousDomain !== '' && $previousDomain !== $shop) {
            StoreRenamed::dispatch($store, $previousDomain, $shop);
        }

        return [$store, $isNewInstall, $isReinstall];
    }

    /**
     * Resolve by the durable key first, the domain second. A store keeps its
     * external id across a domain change, so matching on it avoids creating a
     * second row for a store already connected.
     */
    public function resolve(?string $externalId, ?string $domain): ?ShopifyStore
    {
        if ($externalId !== null && $found = $this->stores->findByExternalId($externalId)) {
            return $found;
        }

        return $domain ? $this->stores->findByDomain($domain) : null;
    }
}
