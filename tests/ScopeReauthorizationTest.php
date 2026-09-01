<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Models\Integration;

class ScopeReauthorizationTest extends TestCase
{
    private const SHOP = 'acme.myshopify.com';

    protected function defineRoutes($router): void
    {
        $router->middleware(['shopifyIntegration.installed'])
            ->get('/protected', fn () => 'allowed');

        $router->middleware(['shopifyIntegration.installed'])
            ->get('/protected-json', fn () => response()->json(['ok' => true]));
    }

    private function store(array $attributes = []): Integration
    {
        return Integration::query()->create(array_merge([
            'store_domain' => self::SHOP,
            'access_token' => 'shpat_token',
            'scopes'       => 'write_products',
        ], $attributes));
    }

    #[Test]
    public function a_healthy_store_passes_through(): void
    {
        config(['shopifyIntegration.scopes' => 'write_products']);
        $this->store();

        $this->get('/protected?shop='.self::SHOP)
            ->assertOk()
            ->assertSee('allowed');
    }

    /**
     * The scenario none of the three apps handles today: a scope is added to
     * config, but tokens already issued do not have it. Without this the
     * merchant hits a 403 at whichever call site needed the new scope.
     */
    #[Test]
    public function a_store_missing_a_newly_required_scope_is_sent_back_through_oauth(): void
    {
        $this->store(['scopes' => 'write_products']);

        config(['shopifyIntegration.scopes' => 'write_products,write_files']);

        $this->get('/protected?shop='.self::SHOP)
            ->assertRedirect('/shopify/auth/begin?shop='.self::SHOP);
    }

    #[Test]
    public function extra_granted_scopes_are_fine(): void
    {
        $this->store(['scopes' => 'write_products,read_orders,write_files']);
        config(['shopifyIntegration.scopes' => 'write_products']);

        $this->get('/protected?shop='.self::SHOP)->assertOk();
    }

    #[Test]
    public function an_uninstalled_store_is_sent_back_through_oauth(): void
    {
        config(['shopifyIntegration.scopes' => 'write_products']);
        $this->store(['uninstalled_at' => now()]);

        $this->get('/protected?shop='.self::SHOP)
            ->assertRedirect('/shopify/auth/begin?shop='.self::SHOP);
    }

    #[Test]
    public function a_store_with_no_token_is_sent_back_through_oauth(): void
    {
        config(['shopifyIntegration.scopes' => 'write_products']);
        $this->store(['access_token' => null]);

        $this->get('/protected?shop='.self::SHOP)
            ->assertRedirect('/shopify/auth/begin?shop='.self::SHOP);
    }

    #[Test]
    public function an_unknown_store_is_refused(): void
    {
        $this->get('/protected')->assertStatus(403);
    }

    /**
     * An XHR inside the admin iframe cannot follow a redirect out of the
     * frame, so it needs the URL to break out with, not a 302 it will follow
     * into the iframe.
     */
    #[Test]
    public function an_xhr_gets_json_rather_than_a_redirect(): void
    {
        $this->store(['scopes' => 'write_products']);
        config(['shopifyIntegration.scopes' => 'write_products,write_files']);

        $this->getJson('/protected-json?shop='.self::SHOP)
            ->assertStatus(401)
            ->assertJson([
                'error' => 'reauthorization_required',
                'url'   => '/shopify/auth/begin?shop='.self::SHOP,
            ]);
    }

    #[Test]
    public function needs_reauthorization_reports_both_reasons(): void
    {
        config(['shopifyIntegration.scopes' => 'write_products']);

        $healthy = $this->store();
        $this->assertFalse($healthy->needsReauthorization());

        config(['shopifyIntegration.scopes' => 'write_products,write_files']);
        $this->assertTrue($healthy->needsReauthorization());

        config(['shopifyIntegration.scopes' => 'write_products']);
        $this->stores()->markUninstalled($healthy);
        $this->assertTrue($healthy->fresh()->needsReauthorization());
    }
}
