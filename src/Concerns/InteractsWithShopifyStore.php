<?php

namespace ShopGPT\ShopifyIntegration\Concerns;

use DateTimeInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use ShopGPT\ShopifyIntegration\Api\ApiClient;
use ShopGPT\ShopifyIntegration\Services\TokenService;
use ShopGPT\ShopifyIntegration\Support\ColumnMap;
use ShopGPT\ShopifyIntegration\Support\StoreState;

/**
 * Satisfies the ShopifyStore contract on an Eloquent model using the column
 * map, so an app puts this on the model it already has and writes nothing.
 *
 *     class Integration extends Model implements ShopifyStore
 *     {
 *         use InteractsWithShopifyStore;
 *     }
 *
 * Override any method whose value your model gets from somewhere else — an
 * accessor, a cast, a related row.
 */
trait InteractsWithShopifyStore
{
    public function shopifyDomain(): string
    {
        return (string) $this->shopifyColumn('store_domain');
    }

    public function shopifyExternalId(): ?string
    {
        $value = $this->shopifyColumn('external_id');

        return $value === null ? null : (string) $value;
    }

    public function shopifyAccessToken(): ?string
    {
        return $this->shopifyDecrypt($this->shopifyColumn('access_token'));
    }

    public function shopifyRefreshToken(): ?string
    {
        return $this->shopifyDecrypt($this->shopifyColumn('refresh_token'));
    }

    public function shopifyTokenExpiresAt(): ?DateTimeInterface
    {
        $value = $this->shopifyColumn('token_expires_at');

        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? $value : Carbon::parse($value);
    }

    public function shopifyScopes(): ?string
    {
        $value = $this->shopifyColumn('scopes');

        return $value === null ? null : (string) $value;
    }

    /**
     * Installed unless something says otherwise.
     *
     * A table with an `uninstalled_at` column answers from that. One with only
     * a boolean flag maps `uninstalled_at` to null and overrides this.
     */
    public function shopifyIsInstalled(): bool
    {
        if (! ColumnMap::has('uninstalled_at')) {
            return true;
        }

        return $this->shopifyColumn('uninstalled_at') === null;
    }

    /*
    |--------------------------------------------------------------------------
    | Conveniences
    |--------------------------------------------------------------------------
    */

    /** The Admin API, bound to this store, token guaranteed fresh. */
    public function api(): ApiClient
    {
        return new ApiClient($this, app(TokenService::class));
    }

    /**
     * Stores the merchant has not removed the app from.
     *
     * A no-op on a table with no `uninstalled_at` column — there is nothing
     * there to say a store ever went away.
     */
    public function scopeInstalled(Builder $query): Builder
    {
        $column = ColumnMap::column('uninstalled_at');

        return $column ? $query->whereNull($column) : $query;
    }

    /** Reads better than shopifyIsInstalled() in app code. */
    public function isInstalled(): bool
    {
        return $this->shopifyIsInstalled();
    }

    public function hasValidToken(): bool
    {
        return StoreState::hasValidToken($this);
    }

    public function tokenExpiresSoon(?int $bufferSeconds = null): bool
    {
        return StoreState::tokenExpiresSoon($this, $bufferSeconds);
    }

    public function hasRequiredScopes(?string $required = null): bool
    {
        return StoreState::hasRequiredScopes($this, $required);
    }

    public function needsReauthorization(): bool
    {
        return StoreState::needsReauthorization($this);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** Read a logical field through the column map. */
    protected function shopifyColumn(string $field): mixed
    {
        $column = ColumnMap::column($field);

        return $column === null ? null : $this->getAttribute($column);
    }

    /**
     * Decrypt on read, falling back to the raw value.
     *
     * Only used when the package is doing the encrypting. An app whose model
     * already casts its token column leaves `store.encrypt_tokens` off and
     * this returns what the cast produced, untouched.
     */
    protected function shopifyDecrypt(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! config('shopifyIntegration.store.encrypt_tokens', false)) {
            return $value === null ? null : (string) $value;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            return (string) $value;
        }
    }
}
