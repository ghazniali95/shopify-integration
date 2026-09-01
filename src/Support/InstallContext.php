<?php

namespace ShopGPT\ShopifyIntegration\Support;

use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;

/**
 * Everything known about an install at the moment it completes. Handed to
 * listeners and to redirect closures.
 */
class InstallContext
{
    public function __construct(
        public readonly ShopifyStore $store,
        public readonly array $shopData = [],
        public readonly bool $isNewInstall = false,
        public readonly bool $isReinstall = false,
        public readonly bool $viaTokenExchange = false,
        public readonly ?string $scopes = null,
        public readonly ?string $host = null,
    ) {
    }

    public function domain(): ?string
    {
        return $this->store->shopifyDomain() ?: ($this->shopData['myshopify_domain'] ?? null);
    }

    public function shopOwner(): ?string
    {
        return $this->shopData['shop_owner'] ?? null;
    }

    public function email(): ?string
    {
        return $this->shopData['email'] ?? null;
    }

    public function currency(): ?string
    {
        return $this->shopData['currency'] ?? null;
    }

    /**
     * The store's contact email, or a stable synthetic address when that email
     * already belongs to another account in your app.
     *
     * One person running several stores gives Shopify the same contact address
     * on each, so a plain unique constraint on users.email would reject the
     * second store's signup.
     */
    public function uniqueEmail(string $fallbackDomain, callable $isTaken): string
    {
        $email = $this->email();

        if ($email && ! $isTaken($email)) {
            return $email;
        }

        $id = $this->store->shopifyExternalId() ?: str_replace('.', '-', (string) $this->domain());

        return "shopify_{$id}@{$fallbackDomain}";
    }

    /**
     * The eleven fields worth promoting out of shop.json.
     *
     * Read from the payload, not from the store: whether any of them is
     * persisted is your app's decision now, and a listener still needs them
     * on a table that keeps none.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return [
            'name'             => $this->shopData['name'] ?? null,
            'email'            => $this->shopData['email'] ?? null,
            'shop_owner'       => $this->shopData['shop_owner'] ?? null,
            'phone'            => $this->shopData['phone'] ?? null,
            'currency'         => $this->shopData['currency'] ?? null,
            'country_code'     => $this->shopData['country_code'] ?? null,
            'country_name'     => $this->shopData['country_name'] ?? null,
            'primary_locale'   => $this->shopData['primary_locale'] ?? null,
            'plan_name'        => $this->shopData['plan_name'] ?? null,
            'weight_unit'      => $this->shopData['weight_unit'] ?? null,
            'password_enabled' => $this->shopData['password_enabled'] ?? null,
        ];
    }

    /**
     * Development stores cannot be charged live — Shopify rejects a real
     * recurring charge and the merchant sees a failure with no explanation.
     */
    public function isDevelopmentStore(): bool
    {
        return in_array($this->shopData['plan_name'] ?? null, [
            'partner_test', 'affiliate', 'plus_partner_sandbox', 'staff', 'staff_business',
        ], true);
    }
}
