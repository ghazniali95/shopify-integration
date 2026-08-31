<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Events\StoreProfileUpdated;
use ShopGPT\ShopifyIntegration\Events\StoreRenamed;
use ShopGPT\ShopifyIntegration\Events\TokenRefreshFailed;
use ShopGPT\ShopifyIntegration\Exceptions\TokenRefreshException;
use ShopGPT\ShopifyIntegration\Jobs\HandleShopUpdate;
use ShopGPT\ShopifyIntegration\Models\Integration;
use ShopGPT\ShopifyIntegration\Services\StoreWriter;
use ShopGPT\ShopifyIntegration\Services\TokenService;

class LifecycleEventsTest extends TestCase
{
    private const SHOP = 'acme.myshopify.com';

    private function store(array $attributes = []): Integration
    {
        return Integration::query()->create(array_merge([
            'integration_store_domain' => self::SHOP,
            'integration_access_token' => 'shpat_token',
            'integration_scopes'       => 'read_products',
        ], $attributes));
    }

    /*
    |--------------------------------------------------------------------------
    | StoreRenamed
    |--------------------------------------------------------------------------
    */

    /**
     * The row is matched on the Shopify shop id, so a rename is followed
     * rather than duplicated — but anything of yours holding the old domain
     * will not follow it on its own.
     */
    #[Test]
    public function a_renamed_store_announces_itself(): void
    {
        Event::fake([StoreRenamed::class]);

        $this->store(['integration_external_id' => '555']);

        app(StoreWriter::class)->write(
            'acme-new.myshopify.com',
            ['access_token' => 'shpat_new', 'refresh_token' => null, 'expires_in' => 3600, 'scope' => 'read_products'],
            ['id' => 555],
        );

        Event::assertDispatched(StoreRenamed::class, function (StoreRenamed $event) {
            return $event->previousDomain === self::SHOP
                && $event->currentDomain === 'acme-new.myshopify.com';
        });

        $this->assertSame(1, Integration::query()->count());
    }

    #[Test]
    public function an_ordinary_reauthorisation_is_not_a_rename(): void
    {
        Event::fake([StoreRenamed::class]);

        $this->store(['integration_external_id' => '555']);

        app(StoreWriter::class)->write(
            self::SHOP,
            ['access_token' => 'shpat_new', 'refresh_token' => null, 'expires_in' => 3600, 'scope' => 'read_products'],
            ['id' => 555],
        );

        Event::assertNotDispatched(StoreRenamed::class);
    }

    #[Test]
    public function a_first_install_is_not_a_rename(): void
    {
        Event::fake([StoreRenamed::class]);

        app(StoreWriter::class)->write(
            self::SHOP,
            ['access_token' => 'shpat_new', 'refresh_token' => null, 'expires_in' => 3600, 'scope' => 'read_products'],
            ['id' => 555],
        );

        Event::assertNotDispatched(StoreRenamed::class);
    }

    /*
    |--------------------------------------------------------------------------
    | StoreProfileUpdated
    |--------------------------------------------------------------------------
    */

    /**
     * The reason this event exists: a store leaving a development plan
     * decides whether billing has to be created in test mode.
     */
    #[Test]
    public function a_plan_change_is_visible_on_the_profile_event(): void
    {
        Event::fake([StoreProfileUpdated::class]);

        $store = $this->store(['integration_plan_name' => 'partner_test']);

        (new HandleShopUpdate($store->id, 'shop/update', [
            'id'        => 555,
            'plan_name' => 'basic',
        ]))->handle(app(StoreWriter::class));

        Event::assertDispatched(StoreProfileUpdated::class, function (StoreProfileUpdated $event) {
            return $event->planChanged()
                && $event->previousPlan === 'partner_test'
                && $event->store->plan_name === 'basic'
                && in_array('integration_plan_name', $event->changed, true);
        });
    }

    #[Test]
    public function an_update_that_leaves_the_plan_alone_says_so(): void
    {
        Event::fake([StoreProfileUpdated::class]);

        $store = $this->store(['integration_plan_name' => 'basic']);

        (new HandleShopUpdate($store->id, 'shop/update', [
            'id'        => 555,
            'plan_name' => 'basic',
            'phone'     => '+15550001',
        ]))->handle(app(StoreWriter::class));

        Event::assertDispatched(StoreProfileUpdated::class, fn ($e) => ! $e->planChanged());
    }

    /*
    |--------------------------------------------------------------------------
    | TokenRefreshFailed
    |--------------------------------------------------------------------------
    */

    /**
     * A refresh starts before the token actually expires, so a failure often
     * leaves a perfectly usable token. The listener needs to tell that apart
     * from a store that has genuinely lost its connection.
     */
    #[Test]
    public function a_recoverable_refresh_failure_is_not_fatal(): void
    {
        Event::fake([TokenRefreshFailed::class]);
        Http::fake(['*/admin/oauth/access_token' => Http::response([], 500)]);

        $store = $this->store([
            'integration_refresh_token'    => 'shprt_old',
            'integration_token_expires_at' => now()->addMinutes(2),
        ]);

        app(TokenService::class)->refresh($store);

        Event::assertDispatched(TokenRefreshFailed::class, fn ($e) => $e->fatal === false);
    }

    #[Test]
    public function an_expired_token_that_cannot_be_refreshed_is_fatal(): void
    {
        Event::fake([TokenRefreshFailed::class]);
        Http::fake(['*/admin/oauth/access_token' => Http::response([], 400)]);

        $store = $this->store([
            'integration_refresh_token'    => 'shprt_old',
            'integration_token_expires_at' => now()->subHour(),
        ]);

        $this->expectException(TokenRefreshException::class);

        try {
            app(TokenService::class)->refresh($store);
        } finally {
            Event::assertDispatched(TokenRefreshFailed::class, fn ($e) => $e->fatal === true);
        }
    }
}
