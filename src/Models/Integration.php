<?php

namespace ShopGPT\ShopifyIntegration\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * One connected store.
 *
 * Columns carry an `integration_` prefix so this table can hold connections
 * for several platforms without collisions. Your code addresses them without
 * the prefix — `$store->access_token`, not `$store->integration_access_token`.
 *
 * @property string|null $external_id
 * @property string|null $store_domain
 * @property string|null $domain
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property string|null $scopes
 */
class Integration extends Model
{
    public const PLATFORM = 'shopify';

    protected $table = 'integrations';

    protected $guarded = [];

    protected $hidden = [
        'integration_access_token',
        'integration_refresh_token',
    ];

    protected $casts = [
        'integration_token_expires_at'    => 'datetime',
        'integration_installed_at'        => 'datetime',
        'integration_uninstalled_at'      => 'datetime',
        'integration_shop_data_synced_at' => 'datetime',
        'integration_shop_data'           => 'array',
        'integration_metadata'            => 'array',
        'integration_password_enabled'    => 'boolean',
    ];

    /**
     * Logical name => physical column. Fixed, not configurable: a store row
     * means the same thing in every app that installs this package.
     */
    public const COLUMNS = [
        'platform'            => 'integration_platform',
        'external_id'         => 'integration_external_id',
        'store_domain'        => 'integration_store_domain',
        'domain'              => 'integration_domain',
        'access_token'        => 'integration_access_token',
        'refresh_token'       => 'integration_refresh_token',
        'token_expires_at'    => 'integration_token_expires_at',
        'scopes'              => 'integration_scopes',
        'installed_at'        => 'integration_installed_at',
        'uninstalled_at'      => 'integration_uninstalled_at',
        'name'                => 'integration_name',
        'email'               => 'integration_email',
        'shop_owner'          => 'integration_shop_owner',
        'phone'               => 'integration_phone',
        'currency'            => 'integration_currency',
        'country_code'        => 'integration_country_code',
        'country_name'        => 'integration_country_name',
        'primary_locale'      => 'integration_primary_locale',
        'plan_name'           => 'integration_plan_name',
        'weight_unit'         => 'integration_weight_unit',
        'password_enabled'    => 'integration_password_enabled',
        'shop_data'           => 'integration_shop_data',
        'shop_data_synced_at' => 'integration_shop_data_synced_at',
        'metadata'            => 'integration_metadata',
    ];

    /** Columns holding a token, encrypted at rest. */
    private const ENCRYPTED = ['integration_access_token', 'integration_refresh_token'];

    /** Columns holding merchant PII, cleared by a shop/redact webhook. */
    public const PII = [
        'integration_email',
        'integration_phone',
        'integration_shop_owner',
        'integration_shop_data',
    ];

    protected static function booted(): void
    {
        // Every query the package makes is scoped to Shopify. The table may
        // hold other platforms; they are none of this package's business.
        static::addGlobalScope('shopifyIntegration.platform', function (Builder $query) {
            $query->where('integration_platform', static::PLATFORM);
        });

        static::creating(function (self $store) {
            $store->integration_platform ??= static::PLATFORM;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Lookups
    |--------------------------------------------------------------------------
    */

    public static function forDomain(string $domain): ?static
    {
        return static::query()->where('integration_store_domain', $domain)->first();
    }

    public static function forExternalId(string|int $externalId): ?static
    {
        return static::query()->where('integration_external_id', (string) $externalId)->first();
    }

    /**
     * Resolve by the durable key first, the domain second. A store keeps its
     * external id across a domain change, so matching on it avoids creating a
     * second row for a store already connected.
     */
    public static function resolve(string|int|null $externalId, ?string $domain): ?static
    {
        if ($externalId !== null && $found = static::forExternalId($externalId)) {
            return $found;
        }

        return $domain ? static::forDomain($domain) : null;
    }

    public function scopeInstalled(Builder $query): Builder
    {
        return $query->whereNull('integration_uninstalled_at');
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    public function isInstalled(): bool
    {
        return $this->integration_uninstalled_at === null;
    }

    public function hasValidToken(): bool
    {
        return ! empty($this->access_token) && $this->isInstalled();
    }

    /**
     * A null expiry is a legacy permanent token: valid, and never refreshed.
     */
    public function tokenExpiresSoon(?int $bufferSeconds = null): bool
    {
        if ($this->integration_token_expires_at === null) {
            return false;
        }

        $buffer = $bufferSeconds ?? (int) config('shopifyIntegration.tokens.refresh_buffer', 300);

        return $this->integration_token_expires_at->subSeconds($buffer)->isPast();
    }

    /**
     * Whether the granted scopes still cover what the app now asks for.
     *
     * Adding a scope to config does not change tokens already issued. Without
     * this check the merchant keeps a token missing the new scope and hits
     * 403s at whichever call site needed it, which reads as a random bug.
     */
    public function hasRequiredScopes(?string $required = null): bool
    {
        $required = $required ?? (string) config('shopifyIntegration.scopes', '');

        $granted = $this->splitScopes($this->scopes);
        $needed  = $this->splitScopes($required);

        return empty(array_diff($needed, $granted));
    }

    public function markUninstalled(): void
    {
        $this->forceFill(['integration_uninstalled_at' => now()])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $except  Columns to keep, e.g. an id.
     */
    public function redact(): void
    {
        $this->forceFill(array_fill_keys(static::PII, null))->saveQuietly();
    }

    private function splitScopes(?string $scopes): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $scopes))));
    }

    /*
    |--------------------------------------------------------------------------
    | Unprefixed attribute access
    |--------------------------------------------------------------------------
    */

    public function getAttribute($key)
    {
        if (isset(static::COLUMNS[$key]) && ! array_key_exists($key, $this->attributes)) {
            return parent::getAttribute(static::COLUMNS[$key]);
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        if (isset(static::COLUMNS[$key]) && ! array_key_exists($key, $this->attributes)) {
            return parent::setAttribute(static::COLUMNS[$key], $value);
        }

        return parent::setAttribute($key, $value);
    }

    /*
    |--------------------------------------------------------------------------
    | Token encryption
    |--------------------------------------------------------------------------
    */

    protected function integrationAccessToken(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function integrationRefreshToken(): Attribute
    {
        return $this->encryptedAttribute();
    }

    /**
     * Encrypt on write, decrypt on read — but fall back to the raw value when
     * decryption fails.
     *
     * That fallback is the whole point. shopGPT-app stores its Shopify tokens
     * as plaintext today and atelier stores them encrypted; a package that
     * assumed either would hand one of them an unusable token after backfill.
     * A plaintext row keeps working and is re-encrypted next time it is saved.
     */
    private function encryptedAttribute(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null || $value === '' || ! $this->encryptionEnabled()) {
                    return $value;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (DecryptException) {
                    return $value;
                }
            },
            set: fn ($value) => $value === null || $value === '' || ! $this->encryptionEnabled()
                ? $value
                : Crypt::encryptString($value),
        );
    }

    private function encryptionEnabled(): bool
    {
        return (bool) config('shopifyIntegration.tokens.encrypt', true);
    }
}
