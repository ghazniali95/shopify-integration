<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\ShopifyIntegrationServiceProvider;
use ShopGPT\ShopifyIntegration\Support\ColumnMap;

/**
 * The starter table an app publishes when it has none.
 *
 * It is the one place the package states column names out loud, so it can
 * drift from the map it is supposed to match. These tests are what stop that:
 * run it, then check the map against the table it built.
 */
class PublishedMigrationTest extends TestCase
{
    private const TABLE = 'published_integrations';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Its own table, so this runs alongside the suite's own migration.
        $app['config']->set('shopifyIntegration.store.table', self::TABLE);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);

        parent::tearDown();
    }

    #[Test]
    public function it_creates_a_column_for_every_field_the_map_knows_about(): void
    {
        foreach (ColumnMap::FIELDS as $field) {
            $this->assertTrue(
                Schema::hasColumn(self::TABLE, $field),
                "The published migration has no column for the `{$field}` field.",
            );
        }
    }

    #[Test]
    public function the_zero_config_map_resolves_every_field_to_a_real_column(): void
    {
        // No `store.columns` set anywhere in this test: an app that publishes
        // the migration should need no mapping at all.
        $this->assertSame([], ColumnMap::missingRequired());

        foreach (ColumnMap::FIELDS as $field) {
            $this->assertSame($field, ColumnMap::column($field));
        }
    }

    #[Test]
    public function user_id_is_nullable_so_the_callback_can_insert_without_an_owner(): void
    {
        $id = DB::table(self::TABLE)->insertGetId([
            'store_domain' => 'acme.myshopify.com',
            'access_token' => 'shpat_token',
            'platform'     => 'shopify',
        ]);

        $this->assertNull(DB::table(self::TABLE)->find($id)->user_id);
    }

    #[Test]
    public function a_store_cannot_be_connected_twice_on_the_same_platform(): void
    {
        DB::table(self::TABLE)->insert([
            'platform' => 'shopify', 'store_domain' => 'acme.myshopify.com', 'external_id' => '99',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table(self::TABLE)->insert([
            'platform' => 'shopify', 'store_domain' => 'acme.myshopify.com', 'external_id' => '100',
        ]);
    }

    #[Test]
    public function it_rolls_back(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable(self::TABLE));
    }

    #[Test]
    public function it_is_published_rather_than_loaded(): void
    {
        $paths = ShopifyIntegrationServiceProvider::pathsToPublish(
            ShopifyIntegrationServiceProvider::class,
            'shopifyIntegration-migrations',
        );

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('create_shopify_integrations_table.php.stub', array_key_first($paths));
        $this->assertStringEndsWith('_create_shopify_integrations_table.php', reset($paths));

        // Loading it would collide with the table an app already has.
        $this->assertNotContains(
            realpath(__DIR__.'/../database/migrations'),
            array_map('realpath', app('migrator')->paths()),
        );
    }

    private function migration(): Migration
    {
        return require __DIR__.'/../database/migrations/create_shopify_integrations_table.php.stub';
    }
}
