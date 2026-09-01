<?php

namespace ShopGPT\ShopifyIntegration\Support;

/**
 * Logical field => the column your table actually uses.
 *
 * The package ships no migration, so it cannot assume a single name for
 * anything. Every field defaults to its own logical name and is overridden in
 * `shopifyIntegration.store.columns`; mapping one to null says your table does
 * not store it, and the package stops writing it.
 */
class ColumnMap
{
    /** Without these there is no Shopify connection to speak of. */
    public const REQUIRED = [
        'store_domain',
        'access_token',
    ];

    /** Everything the package will write if you give it somewhere to go. */
    public const FIELDS = [
        // Identity and credentials.
        'platform',
        'external_id',
        'store_domain',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'installed_at',
        'uninstalled_at',
        // Profile, promoted from shop.json. All optional.
        'domain',
        'name',
        'email',
        'shop_owner',
        'phone',
        'currency',
        'country_code',
        'country_name',
        'primary_locale',
        'plan_name',
        'weight_unit',
        'password_enabled',
        'shop_data',
        'shop_data_synced_at',
    ];

    /** The column for a logical field, or null when it is not stored. */
    public static function column(string $field): ?string
    {
        $columns = (array) config('shopifyIntegration.store.columns', []);

        if (! array_key_exists($field, $columns)) {
            return in_array($field, self::FIELDS, true) ? $field : null;
        }

        $column = $columns[$field];

        return is_string($column) && $column !== '' ? $column : null;
    }

    public static function has(string $field): bool
    {
        return self::column($field) !== null;
    }

    /**
     * Rewrite [logical => value] as [column => value], dropping anything this
     * table has no column for.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function apply(array $values): array
    {
        $mapped = [];

        foreach ($values as $field => $value) {
            if ($column = self::column($field)) {
                $mapped[$column] = $value;
            }
        }

        return $mapped;
    }

    /** Which of these logical fields the table can actually hold. */
    public static function storable(array $fields): array
    {
        return array_values(array_filter($fields, fn (string $f) => self::has($f)));
    }

    /**
     * @return array<int, string>  Required fields with no column. Empty is good.
     */
    public static function missingRequired(): array
    {
        return array_values(array_filter(
            self::REQUIRED,
            fn (string $field) => ! self::has($field),
        ));
    }
}
