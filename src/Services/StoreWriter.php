<?php

namespace ShopGPT\ShopifyIntegration\Services;

use ShopGPT\ShopifyIntegration\Events\StoreRenamed;
use ShopGPT\ShopifyIntegration\Models\Integration;

/**
 * The single place a store row is created or updated from Shopify data, so
 * the OAuth callback and (later) token exchange cannot drift apart.
 */
class StoreWriter
{
    /**
     * @param  array{access_token: string, refresh_token: ?string, expires_in: ?int, scope: ?string}  $token
     * @param  array<string, mixed>  $shopData  Raw shop.json, or [] if unavailable.
     * @return array{0: Integration, 1: bool, 2: bool}  [store, isNewInstall, isReinstall]
     */
    public function write(string $shop, array $token, array $shopData = []): array
    {
        $externalId = isset($shopData['id']) ? (string) $shopData['id'] : null;

        $store = Integration::resolve($externalId, $shop);

        $isNewInstall = $store === null;
        // A row that exists but was uninstalled is a reinstall, not a new
        // install — the distinction decides whether onboarding runs again.
        $isReinstall = ! $isNewInstall && ! $store->isInstalled();

        // Captured before the write: the row is matched on the Shopify shop
        // id, so a renamed store is followed rather than duplicated — and
        // without this the old domain would be gone with nothing announcing it.
        $previousDomain = $store?->store_domain;

        $store ??= Integration::query()->make();

        $attributes = [
            'integration_platform'         => Integration::PLATFORM,
            'integration_store_domain'     => $shop,
            'integration_access_token'     => $token['access_token'],
            'integration_refresh_token'    => $token['refresh_token'] ?? null,
            'integration_token_expires_at' => ! empty($token['expires_in'])
                ? now()->addSeconds((int) $token['expires_in'])
                : null,
            // Trust the granted scope from Shopify over what we requested —
            // they differ whenever a merchant is mid-way through a scope change.
            'integration_scopes'           => $token['scope'] ?? config('shopifyIntegration.scopes'),
            'integration_uninstalled_at'   => null,
        ];

        if ($externalId !== null) {
            $attributes['integration_external_id'] = $externalId;
        }

        if ($store->integration_installed_at === null) {
            $attributes['integration_installed_at'] = now();
        }

        if ($shopData !== []) {
            $attributes += $this->profileFrom($shopData);
        }

        $store->forceFill($attributes)->save();

        if ($previousDomain !== null && $previousDomain !== $shop) {
            StoreRenamed::dispatch($store, $previousDomain, $shop);
        }

        return [$store, $isNewInstall, $isReinstall];
    }

    /**
     * Promote the eleven hot fields into columns and keep the whole payload.
     */
    public function profileFrom(array $shopData): array
    {
        return [
            'integration_domain'              => $shopData['domain'] ?? null,
            'integration_name'                => $shopData['name'] ?? null,
            'integration_email'               => $shopData['email'] ?? null,
            'integration_shop_owner'          => $shopData['shop_owner'] ?? null,
            'integration_phone'               => $shopData['phone'] ?? null,
            'integration_currency'            => $shopData['currency'] ?? null,
            'integration_country_code'        => $shopData['country_code'] ?? null,
            'integration_country_name'        => $shopData['country_name'] ?? null,
            'integration_primary_locale'      => $shopData['primary_locale'] ?? null,
            'integration_plan_name'           => $shopData['plan_name'] ?? null,
            'integration_weight_unit'         => $shopData['weight_unit'] ?? null,
            'integration_password_enabled'    => $shopData['password_enabled'] ?? null,
            'integration_shop_data'           => $shopData,
            'integration_shop_data_synced_at' => now(),
        ];
    }
}
