<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Events\StoreScopesUpdated;
use ShopGPT\ShopifyIntegration\Jobs\HandleScopesUpdate;
use ShopGPT\ShopifyIntegration\Models\Integration;

class ScopesUpdateWebhookTest extends TestCase
{
    private const SHOP = 'acme.myshopify.com';

    private function store(array $attributes = []): Integration
    {
        return Integration::query()->create(array_merge([
            'store_domain' => self::SHOP,
            'access_token' => 'shpat_token',
            'scopes'       => 'read_products',
        ], $attributes));
    }

    private function handle(Integration $store, array $payload): void
    {
        app()->call([new HandleScopesUpdate($store->id, 'app/scopes_update', $payload), 'handle']);
    }

    /**
     * The scenario this webhook exists for: under managed installation the
     * merchant approves a scope change inside the admin and the app is never
     * called, so nothing else would move the column.
     */
    #[Test]
    public function it_writes_the_new_scopes(): void
    {
        $store = $this->store(['scopes' => 'read_products']);

        $this->handle($store, [
            'previous' => ['read_products'],
            'current'  => ['read_products', 'write_products'],
        ]);

        $this->assertSame('read_products,write_products', $store->fresh()->scopes);
    }

    #[Test]
    public function it_clears_a_false_shortfall(): void
    {
        config(['shopifyIntegration.scopes' => 'read_products,write_products']);

        $store = $this->store(['scopes' => 'read_products']);

        $this->assertFalse($store->hasRequiredScopes());

        $this->handle($store, [
            'previous' => ['read_products'],
            'current'  => ['read_products', 'write_products'],
        ]);

        $this->assertTrue($store->fresh()->hasRequiredScopes());
    }

    #[Test]
    public function it_reports_what_was_gained_and_lost(): void
    {
        Event::fake([StoreScopesUpdated::class]);

        $store = $this->store();

        $this->handle($store, [
            'previous' => ['read_products', 'read_orders'],
            'current'  => ['read_products', 'write_products'],
        ]);

        Event::assertDispatched(StoreScopesUpdated::class, function (StoreScopesUpdated $event) {
            return $event->gained() === ['write_products']
                && $event->lost() === ['read_orders'];
        });
    }

    /**
     * Shopify sends arrays, but writing a malformed scope column because the
     * shape differed would be silent and would strand the store.
     */
    #[Test]
    public function it_accepts_a_comma_separated_string_too(): void
    {
        $store = $this->store();

        $this->handle($store, [
            'previous' => 'read_products',
            'current'  => 'read_products, write_products',
        ]);

        $this->assertSame('read_products,write_products', $store->fresh()->scopes);
    }

    /**
     * Shopify sends an empty current array as an app is being removed.
     * Writing that would leave a store that later reinstalls looking
     * permanently under-scoped — app/uninstalled is what handles removal.
     */
    #[Test]
    public function an_empty_scope_list_is_ignored(): void
    {
        Event::fake([StoreScopesUpdated::class]);

        $store = $this->store(['scopes' => 'read_products']);

        $this->handle($store, ['previous' => ['read_products'], 'current' => []]);

        $this->assertSame('read_products', $store->fresh()->scopes);
        Event::assertNotDispatched(StoreScopesUpdated::class);
    }

    #[Test]
    public function a_deleted_store_is_a_no_op(): void
    {
        $store = $this->store();
        $id    = $store->id;
        $store->delete();

        app()->call([new HandleScopesUpdate($id, 'app/scopes_update', ['current' => ['read_products']]), 'handle']);

        $this->assertSame(0, Integration::query()->count());
    }

    #[Test]
    public function the_topic_is_registered_by_default(): void
    {
        $this->assertSame(
            HandleScopesUpdate::class,
            config('shopifyIntegration.webhooks.topics')['app/scopes_update'] ?? null,
        );
    }
}
