<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;
use ShopGPT\ShopifyIntegration\Concerns\InteractsWithShopifyStore;
use ShopGPT\ShopifyIntegration\Events\StoreInstalled;
use ShopGPT\ShopifyIntegration\Repositories\EloquentStoreRepository;
use ShopGPT\ShopifyIntegration\Support\OAuthState;

/**
 * The case the package used to make impossible.
 *
 * An app whose store table has a NOT NULL owning column, and its own column
 * names, and its own token encryption. Nothing here changes the package — it
 * is all configuration and one overridden method.
 */
class OwnedStoreTest extends TestCase
{
    private const SHOP = 'acme.myshopify.com';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('shopifyIntegration.store.repository', OwnedStoreRepository::class);
        $app['config']->set('shopifyIntegration.store.model', OwnedStore::class);
        $app['config']->set('shopifyIntegration.store.table', 'owned_stores');

        // The app's names, not the package's.
        $app['config']->set('shopifyIntegration.store.columns', [
            'store_domain'   => 'domain',
            'access_token'   => 'token',
            'external_id'    => 'shopify_id',
            'platform'       => 'type',
            'uninstalled_at' => 'disconnected_at',
            // This table keeps no merchant profile at all.
            'name'                => null,
            'email'               => null,
            'shop_owner'          => null,
            'phone'               => null,
            'currency'            => null,
            'country_code'        => null,
            'country_name'        => null,
            'primary_locale'      => null,
            'plan_name'           => null,
            'weight_unit'         => null,
            'password_enabled'    => null,
            'domain'              => null,
            'shop_data'           => null,
            'shop_data_synced_at' => null,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('owned_stores', function (Blueprint $table) {
            $table->id();
            // The whole point: required, no default, no way for the package to
            // guess it.
            $table->unsignedBigInteger('user_id');
            $table->string('type')->default('shopify');
            $table->string('shopify_id')->nullable();
            $table->string('domain')->nullable();
            $table->text('token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('scopes')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });
    }

    #[Test]
    public function an_install_fills_a_required_owner_column_the_package_knows_nothing_about(): void
    {
        $this->fakeShopify();

        $response = $this->get($this->callbackUrl());

        $response->assertRedirect();

        $row = DB::table('owned_stores')->first();

        $this->assertNotNull($row);
        // Derived by the app from shop.json, inside its own repository.
        $this->assertSame(4242, (int) $row->user_id);
        $this->assertSame(self::SHOP, $row->domain);
        $this->assertSame('shpat_new_token', $row->token);
        $this->assertSame('99', $row->shopify_id);
    }

    #[Test]
    public function the_package_reads_the_store_back_through_the_app_column_names(): void
    {
        $this->fakeShopify();
        $this->get($this->callbackUrl());

        $store = $this->stores()->findByDomain(self::SHOP);

        $this->assertInstanceOf(ShopifyStore::class, $store);
        $this->assertSame(self::SHOP, $store->shopifyDomain());
        $this->assertSame('shpat_new_token', $store->shopifyAccessToken());
        $this->assertSame('99', $store->shopifyExternalId());
        $this->assertTrue($store->shopifyIsInstalled());
    }

    #[Test]
    public function uninstalling_uses_the_app_column_and_drops_the_token(): void
    {
        $this->fakeShopify();
        $this->get($this->callbackUrl());

        $store = $this->stores()->findByDomain(self::SHOP);
        $this->stores()->markUninstalled($store);

        $row = DB::table('owned_stores')->first();

        $this->assertNotNull($row->disconnected_at);
        $this->assertNull($row->token);
        $this->assertFalse($this->stores()->findByDomain(self::SHOP)->shopifyIsInstalled());
    }

    #[Test]
    public function a_table_that_stores_no_profile_still_hands_the_full_profile_to_listeners(): void
    {
        $this->fakeShopify();

        $captured = null;
        \Event::listen(StoreInstalled::class, function (StoreInstalled $event) use (&$captured) {
            $captured = $event->context->profile();
        });

        $this->get($this->callbackUrl());

        $this->assertSame('Acme', $captured['name']);
        $this->assertSame('owner@acme.test', $captured['email']);
        $this->assertSame('basic', $captured['plan_name']);
        // ...and none of it was written, because the table has nowhere to put it.
        $this->assertFalse(Schema::hasColumn('owned_stores', 'plan_name'));
    }

    private function callbackUrl(): string
    {
        $state = OAuthState::issue(self::SHOP);

        return $this->signed('/shopify/auth/callback', [
            'shop'  => self::SHOP,
            'code'  => 'auth-code',
            'state' => $state,
        ]);
    }

    private function fakeShopify(): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_new_token',
                'scope'        => 'write_products',
            ]),
            '*/admin/api/*/shop.json' => Http::response([
                'shop' => [
                    'id'        => 99,
                    'name'      => 'Acme',
                    'email'     => 'owner@acme.test',
                    'domain'    => 'acme.test',
                    'plan_name' => 'basic',
                ],
            ]),
        ]);
    }
}

/** The app's own model: its own table, its own names. */
class OwnedStore extends Model implements ShopifyStore
{
    use InteractsWithShopifyStore;

    protected $table = 'owned_stores';

    protected $guarded = [];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'installed_at'     => 'datetime',
        'disconnected_at'  => 'datetime',
    ];
}

/**
 * One overridden method is the whole integration: the package hands over the
 * install, the app decides who owns it.
 */
class OwnedStoreRepository extends EloquentStoreRepository
{
    protected function newStore(string $shop, array $shopData): Model
    {
        $store = parent::newStore($shop, $shopData);

        // A real app resolves or creates a User here — from the session, or
        // from $shopData['email'] on an App Store install.
        $store->user_id = 4242;

        return $store;
    }
}
