<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Models\Integration;

class IntegrationModelTest extends TestCase
{
    #[Test]
    public function unprefixed_attributes_read_and_write_the_prefixed_columns(): void
    {
        $store = Integration::query()->create([
            'store_domain' => 'acme.myshopify.com',
            'plan_name'    => 'basic',
        ]);

        $this->assertSame('acme.myshopify.com', $store->store_domain);
        $this->assertSame('acme.myshopify.com', $store->integration_store_domain);
        $this->assertSame('acme.myshopify.com', DB::table('integrations')->value('integration_store_domain'));
        $this->assertSame('basic', $store->fresh()->plan_name);
    }

    #[Test]
    public function queries_are_scoped_to_shopify_rows_only(): void
    {
        DB::table('integrations')->insert([
            ['integration_platform' => 'shopify', 'integration_store_domain' => 'acme.myshopify.com'],
            ['integration_platform' => 'ecwid',   'integration_store_domain' => 'ecwid-store'],
        ]);

        $this->assertSame(1, Integration::query()->count());
        $this->assertNull(Integration::forDomain('ecwid-store'));
    }

    #[Test]
    public function the_platform_defaults_to_shopify_on_create(): void
    {
        $store = Integration::query()->create(['store_domain' => 'acme.myshopify.com']);

        $this->assertSame('shopify', $store->fresh()->integration_platform);
    }

    #[Test]
    public function tokens_are_hidden_from_serialisation(): void
    {
        $store = Integration::query()->create([
            'store_domain' => 'acme.myshopify.com',
            'access_token' => 'shpat_secret',
        ]);

        $json = $store->toJson();

        $this->assertStringNotContainsString('shpat_secret', $json);
        $this->assertArrayNotHasKey('integration_access_token', $store->toArray());
    }

    #[Test]
    public function install_state_is_driven_by_uninstalled_at(): void
    {
        $store = Integration::query()->create(['store_domain' => 'acme.myshopify.com']);

        $this->assertTrue($store->isInstalled());
        $this->assertSame(1, Integration::query()->installed()->count());

        $store->markUninstalled();

        $this->assertFalse($store->fresh()->isInstalled());
        $this->assertSame(0, Integration::query()->installed()->count());
    }

    /**
     * Adding a scope to config does not change tokens already issued — without
     * this check the merchant hits 403s at whichever call site needed it.
     */
    #[Test]
    public function it_detects_when_granted_scopes_no_longer_cover_the_config(): void
    {
        $store = Integration::query()->create([
            'store_domain' => 'acme.myshopify.com',
            'scopes'       => 'write_products,read_orders',
        ]);

        $this->assertTrue($store->hasRequiredScopes('write_products'));
        $this->assertTrue($store->hasRequiredScopes('read_orders, write_products'));
        $this->assertFalse($store->hasRequiredScopes('write_products,write_files'));
    }

    #[Test]
    public function redact_clears_every_pii_column(): void
    {
        $store = Integration::query()->create([
            'store_domain' => 'acme.myshopify.com',
            'email'        => 'owner@acme.test',
            'phone'        => '+15550000',
            'shop_owner'   => 'Dana Acme',
            'shop_data'    => ['email' => 'owner@acme.test', 'zip' => '90210'],
            'access_token' => 'shpat_secret',
        ]);

        $store->redact();
        $store = $store->fresh();

        $this->assertNull($store->email);
        $this->assertNull($store->phone);
        $this->assertNull($store->shop_owner);
        $this->assertNull($store->shop_data);

        // The token is not PII and is still needed to deregister webhooks.
        $this->assertSame('shpat_secret', $store->access_token);
    }
}
