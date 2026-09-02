<?php

namespace ShopGPT\ShopifyIntegration\Models;

use Illuminate\Database\Eloquent\Model;
use ShopGPT\ShopifyIntegration\Concerns\InteractsWithShopifyStore;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Support\ColumnMap;

/**
 * The default store model — a starting point, not a requirement.
 *
 * It prescribes no column names: everything it reads and writes comes from
 * `shopifyIntegration.store`, so it fits the published starter table without
 * configuration and an existing table through the column map. Point
 * `store.model` at your own model instead as soon as you have one; add the
 * InteractsWithShopifyStore trait to it and it will satisfy the contract the
 * same way this does.
 */
class Integration extends Model implements ShopifyStore
{
    use InteractsWithShopifyStore;

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable((string) config('shopifyIntegration.store.table', 'integrations'));
        $this->mergeCasts($this->shopifyCasts());
        $this->makeHidden(array_values(array_filter([
            ColumnMap::column('access_token'),
            ColumnMap::column('refresh_token'),
        ])));
    }

    /**
     * Casts for the mapped columns only. A field your table does not have gets
     * no cast, so nothing here assumes a column exists.
     *
     * @return array<string, string>
     */
    protected function shopifyCasts(): array
    {
        $types = [
            'token_expires_at'    => 'datetime',
            'installed_at'        => 'datetime',
            'uninstalled_at'      => 'datetime',
            'shop_data_synced_at' => 'datetime',
            'shop_data'           => 'array',
            'password_enabled'    => 'boolean',
        ];

        $casts = [];

        foreach ($types as $field => $type) {
            if ($column = ColumnMap::column($field)) {
                $casts[$column] = $type;
            }
        }

        return $casts;
    }
}
