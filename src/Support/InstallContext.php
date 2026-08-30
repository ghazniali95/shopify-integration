<?php

namespace ShopGPT\ShopifyIntegration\Support;

use ShopGPT\ShopifyIntegration\Models\Integration;

/**
 * Everything known about an install at the moment it completes. Handed to
 * listeners and to redirect closures.
 */
class InstallContext
{
    public function __construct(
        public readonly Integration $store,
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
        return $this->store->store_domain;
    }

    public function shopOwner(): ?string
    {
        return $this->store->shop_owner;
    }

    public function email(): ?string
    {
        return $this->store->email;
    }

    public function currency(): ?string
    {
        return $this->store->currency;
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

        $id = $this->store->external_id ?: str_replace('.', '-', (string) $this->domain());

        return "shopify_{$id}@{$fallbackDomain}";
    }

    /** The eleven promoted profile columns, as an array. */
    public function profile(): array
    {
        return [
            'name'             => $this->store->name,
            'email'            => $this->store->email,
            'shop_owner'       => $this->store->shop_owner,
            'phone'            => $this->store->phone,
            'currency'         => $this->store->currency,
            'country_code'     => $this->store->country_code,
            'country_name'     => $this->store->country_name,
            'primary_locale'   => $this->store->primary_locale,
            'plan_name'        => $this->store->plan_name,
            'weight_unit'      => $this->store->weight_unit,
            'password_enabled' => $this->store->password_enabled,
        ];
    }

    /**
     * Development stores cannot be charged live — Shopify rejects a real
     * recurring charge and the merchant sees a failure with no explanation.
     */
    public function isDevelopmentStore(): bool
    {
        return in_array($this->store->plan_name, [
            'partner_test', 'affiliate', 'plus_partner_sandbox', 'staff', 'staff_business',
        ], true);
    }
}
