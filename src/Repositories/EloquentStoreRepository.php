<?php

namespace ShopGPT\ShopifyIntegration\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;
use ShopGPT\ShopifyIntegration\Exceptions\ShopifyIntegrationException;
use ShopGPT\ShopifyIntegration\Support\ColumnMap;

/**
 * The default repository: an ordinary Eloquent model, addressed through the
 * column map.
 *
 * Enough for an app whose table the package can fill on its own. When the
 * INSERT needs something the package cannot know — an owning user, a tenant,
 * a plan — extend this and override newStore(); everything else keeps working.
 *
 *     class AppStoreRepository extends EloquentStoreRepository
 *     {
 *         protected function newStore(string $shop, array $shopData): Model
 *         {
 *             $store = parent::newStore($shop, $shopData);
 *             $store->user_id = $this->owner($shopData)->id;   // stays NOT NULL
 *
 *             return $store;
 *         }
 *     }
 */
class EloquentStoreRepository implements ShopifyStoreRepository
{
    /** shop.json key => logical field. */
    protected const PROFILE = [
        'domain'           => 'domain',
        'name'             => 'name',
        'email'            => 'email',
        'shop_owner'       => 'shop_owner',
        'phone'            => 'phone',
        'currency'         => 'currency',
        'country_code'     => 'country_code',
        'country_name'     => 'country_name',
        'primary_locale'   => 'primary_locale',
        'plan_name'        => 'plan_name',
        'weight_unit'      => 'weight_unit',
        'password_enabled' => 'password_enabled',
    ];

    public function findByKey(int|string $key): ?ShopifyStore
    {
        return $this->query()->find($key);
    }

    public function findByDomain(string $domain): ?ShopifyStore
    {
        if ($domain === '' || ! $column = ColumnMap::column('store_domain')) {
            return null;
        }

        return $this->query()->where($column, $domain)->first();
    }

    public function findByExternalId(string $externalId): ?ShopifyStore
    {
        if ($externalId === '' || ! $column = ColumnMap::column('external_id')) {
            return null;
        }

        return $this->query()->where($column, $externalId)->first();
    }

    public function persistInstall(
        ?ShopifyStore $existing,
        string $shop,
        array $token,
        array $shopData,
    ): ShopifyStore {
        $this->assertMapped();

        $store = $existing instanceof Model ? $existing : $this->newStore($shop, $shopData);

        $attributes = [
            'store_domain'     => $shop,
            'access_token'     => $this->encrypt($token['access_token']),
            'refresh_token'    => $this->encrypt($token['refresh_token'] ?? null),
            'token_expires_at' => $this->expiryFrom($token),
            // Trust the granted scope from Shopify over what was requested —
            // they differ whenever a merchant is mid-way through a scope change.
            'scopes'           => $token['scope'] ?? config('shopifyIntegration.scopes'),
            'uninstalled_at'   => null,
        ];

        if (ColumnMap::has('platform')) {
            $attributes['platform'] = config('shopifyIntegration.store.platform', 'shopify');
        }

        if (isset($shopData['id'])) {
            $attributes['external_id'] = (string) $shopData['id'];
        }

        if (ColumnMap::has('installed_at') && $store->{ColumnMap::column('installed_at')} === null) {
            $attributes['installed_at'] = now();
        }

        if ($shopData !== []) {
            $attributes += $this->profileFrom($shopData);
        }

        $store->forceFill(ColumnMap::apply($attributes))->save();

        return $store;
    }

    public function updateTokens(ShopifyStore $store, array $token): ShopifyStore
    {
        $this->model($store)->forceFill(ColumnMap::apply([
            'access_token'     => $this->encrypt($token['access_token']),
            'refresh_token'    => $this->encrypt($token['refresh_token'] ?? $store->shopifyRefreshToken()),
            'token_expires_at' => $this->expiryFrom($token),
        ]))->saveQuietly();

        return $store;
    }

    public function updateProfile(ShopifyStore $store, array $shopData): array
    {
        $empty = ['changed' => [], 'previous' => []];

        if ($shopData === []) {
            return $empty;
        }

        $profile = $this->profileFrom($shopData);
        $mapped  = ColumnMap::apply($profile);

        if ($mapped === []) {
            return $empty;
        }

        $model = $this->model($store);

        // Read before the write: the caller is handed the same object back,
        // so afterwards there is nothing left to compare against.
        $previous = [];

        foreach (array_keys($profile) as $field) {
            if ($column = ColumnMap::column($field)) {
                $previous[$field] = $model->getAttribute($column);
            }
        }

        $model->forceFill($mapped)->saveQuietly();

        // What the caller reports as changed: fields this table stored AND
        // this payload actually carried a value for. shop/update fires for
        // changes the app does not care about.
        unset($profile['shop_data'], $profile['shop_data_synced_at']);

        $changed = [];

        foreach ($profile as $field => $value) {
            if ($value !== null && ColumnMap::has($field)) {
                $changed[] = $field;
            }
        }

        unset($previous['shop_data'], $previous['shop_data_synced_at']);

        return ['changed' => $changed, 'previous' => $previous];
    }

    public function updateScopes(ShopifyStore $store, ?string $scopes): ShopifyStore
    {
        if (ColumnMap::has('scopes')) {
            $this->model($store)->forceFill(ColumnMap::apply(['scopes' => $scopes]))->saveQuietly();
        }

        return $store;
    }

    public function markUninstalled(ShopifyStore $store): void
    {
        $this->model($store)->forceFill(ColumnMap::apply([
            'uninstalled_at'   => now(),
            'access_token'     => null,
            'refresh_token'    => null,
            'token_expires_at' => null,
        ]))->saveQuietly();
    }

    public function redact(ShopifyStore $store): void
    {
        $fields = ColumnMap::storable((array) config('shopifyIntegration.store.pii', [
            'email', 'phone', 'shop_owner', 'shop_data',
        ]));

        if ($fields === []) {
            return;
        }

        $this->model($store)
            ->forceFill(ColumnMap::apply(array_fill_keys($fields, null)))
            ->saveQuietly();
    }

    /*
    |--------------------------------------------------------------------------
    | Extension points
    |--------------------------------------------------------------------------
    */

    /**
     * A store row that does not exist yet, unsaved.
     *
     * The hook for anything your table requires that Shopify does not supply.
     * Override, call parent, set your columns, return it.
     *
     * @param  array<string, mixed>  $shopData
     */
    protected function newStore(string $shop, array $shopData): Model
    {
        return $this->query()->make();
    }

    /** Promote the known shop.json fields to logical field names. */
    protected function profileFrom(array $shopData): array
    {
        $profile = [];

        foreach (static::PROFILE as $key => $field) {
            $profile[$field] = $shopData[$key] ?? null;
        }

        $profile['shop_data']           = $shopData;
        $profile['shop_data_synced_at'] = now();

        return $profile;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** @return \Illuminate\Database\Eloquent\Builder */
    protected function query()
    {
        $model = $this->modelClass();
        $query = $model::query();

        // The table may hold connections for other platforms; they are none of
        // this package's business.
        if ($column = ColumnMap::column('platform')) {
            $query->where($column, config('shopifyIntegration.store.platform', 'shopify'));
        }

        return $query;
    }

    /** @return class-string<Model> */
    protected function modelClass(): string
    {
        return config('shopifyIntegration.store.model')
            ?: \ShopGPT\ShopifyIntegration\Models\Integration::class;
    }

    /**
     * Fail on a map that cannot hold a Shopify connection.
     *
     * Checked here because this is the first moment a bad map does damage: an
     * unmapped store_domain makes every lookup return null, so each install
     * looks like the first one and writes a row nothing can find again. Better
     * a thrown exception on the callback than a table filling with orphans.
     *
     * @throws ShopifyIntegrationException
     */
    protected function assertMapped(): void
    {
        $missing = ColumnMap::missingRequired();

        if ($missing === []) {
            return;
        }

        throw new ShopifyIntegrationException(sprintf(
            'shopifyIntegration.store.columns maps no column for: %s. '
            .'A store cannot be stored without them.',
            implode(', ', $missing),
        ));
    }

    protected function model(ShopifyStore $store): Model
    {
        if (! $store instanceof Model) {
            throw new \InvalidArgumentException(
                'EloquentStoreRepository was handed a store that is not an Eloquent model. '
                .'Bind your own ShopifyStoreRepository implementation instead.'
            );
        }

        return $store;
    }

    protected function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || ! config('shopifyIntegration.store.encrypt_tokens', false)) {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    protected function expiryFrom(array $token): ?\DateTimeInterface
    {
        return ! empty($token['expires_in'])
            ? now()->addSeconds((int) $token['expires_in'])
            : null;
    }
}
