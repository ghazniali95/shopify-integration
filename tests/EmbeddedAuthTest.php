<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Facades\ShopifyIntegration;
use ShopGPT\ShopifyIntegration\Models\Integration;
use ShopGPT\ShopifyIntegration\Services\SessionTokenService;

class EmbeddedAuthTest extends TestCase
{
    private const SHOP = 'acme.myshopify.com';

    protected function defineRoutes($router): void
    {
        $router->middleware(['shopifyIntegration.embedded'])
            ->get('/shopify/app', fn () => 'host page');

        $router->middleware(['shopifyIntegration.session'])
            ->get('/api/products', fn () => response()->json([
                'store' => ShopifyIntegration::currentStore()?->store_domain,
            ]));
    }

    private function store(array $attributes = []): Integration
    {
        return Integration::query()->create(array_merge([
            'store_domain' => self::SHOP,
            'access_token' => 'shpat_token',
            'scopes'       => 'write_products',
            'installed_at' => now()->subMonth(),
        ], $attributes));
    }

    private function token(array $claims = []): string
    {
        return $this->app->make(SessionTokenService::class)->mint(self::SHOP, $claims);
    }

    private function fakeExchange(): void
    {
        Http::fake([
            'https://'.self::SHOP.'/admin/oauth/access_token' => Http::response([
                'access_token'  => 'shpat_exchanged',
                'refresh_token' => 'shprt_refresh',
                'expires_in'    => 86400,
                'scope'         => 'write_products',
            ]),
            'https://'.self::SHOP.'/admin/api/*/shop.json' => Http::response([
                'shop' => ['id' => 999, 'name' => 'Acme'],
            ]),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | shopifyIntegration.session
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_valid_session_token_authenticates_an_api_request(): void
    {
        $this->store();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->getJson('/api/products')
            ->assertOk()
            ->assertJson(['store' => self::SHOP]);
    }

    #[Test]
    public function the_facade_helper_produces_headers_that_work(): void
    {
        $store = $this->store();

        $this->withHeaders(ShopifyIntegration::sessionTokenHeaders($store))
            ->getJson('/api/products')
            ->assertOk();
    }

    #[Test]
    public function a_request_with_no_token_is_rejected(): void
    {
        $this->store();

        $this->getJson('/api/products')
            ->assertStatus(401)
            ->assertJson(['error' => 'session_token_missing']);
    }

    /**
     * 401, not 403: session tokens last about a minute, so the front end just
     * mints a fresh one and retries. Answering 403 here would turn an ordinary
     * expiry into a bounce out of the admin.
     */
    #[Test]
    public function an_expired_token_is_a_retryable_401(): void
    {
        $this->store();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token(['exp' => time() - 120])])
            ->getJson('/api/products')
            ->assertStatus(401)
            ->assertJson(['error' => 'session_token_invalid']);
    }

    /**
     * The other half of that split. A perfectly valid token for a store the
     * app has no install for must not look retryable, or the client loops
     * forever minting good tokens Shopify is happy to sign.
     */
    #[Test]
    public function a_valid_token_for_an_uninstallable_store_is_a_terminal_403(): void
    {
        Http::fake([
            'https://'.self::SHOP.'/admin/oauth/access_token' => Http::response(
                ['error' => 'invalid_grant'], 400
            ),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->getJson('/api/products')
            ->assertStatus(403)
            ->assertJson([
                'error' => 'reauthorization_required',
                'url'   => 'https://test-app.com/shopify/auth/begin?shop='.self::SHOP,
            ]);
    }

    #[Test]
    public function a_forged_token_never_reaches_the_route(): void
    {
        $this->store();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token([
            'iss'  => 'https://attacker.myshopify.com/admin',
            'dest' => 'https://'.self::SHOP,
        ])])->getJson('/api/products')->assertStatus(401);
    }

    /**
     * The install path for an app the merchant added from the App Store:
     * there is no row yet, and the first authenticated request creates one.
     */
    #[Test]
    public function an_unknown_store_is_token_exchanged_on_first_request(): void
    {
        $this->fakeExchange();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->getJson('/api/products')
            ->assertOk()
            ->assertJson(['store' => self::SHOP]);

        $store = $this->stores()->findByDomain(self::SHOP);

        $this->assertNotNull($store);
        $this->assertSame('shpat_exchanged', $store->access_token);
    }

    #[Test]
    public function a_store_whose_token_was_revoked_recovers_silently(): void
    {
        $this->store(['access_token' => null]);
        $this->fakeExchange();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->getJson('/api/products')
            ->assertOk();

        $this->assertSame('shpat_exchanged', $this->stores()->findByDomain(self::SHOP)->access_token);
    }

    #[Test]
    public function an_expired_token_with_no_refresh_token_is_exchanged_rather_than_left_to_fail(): void
    {
        $this->store([
            'token_expires_at' => now()->subHour(),
            'refresh_token'    => null,
        ]);
        $this->fakeExchange();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->getJson('/api/products')
            ->assertOk();

        $this->assertSame('shpat_exchanged', $this->stores()->findByDomain(self::SHOP)->access_token);
    }

    /**
     * A merchant who approved new scopes through Shopify's managed install has
     * already granted them — the exchange picks them up with no redirect.
     */
    #[Test]
    public function a_scope_upgrade_is_picked_up_by_exchange(): void
    {
        $this->store(['scopes' => 'write_products']);

        config(['shopifyIntegration.scopes' => 'write_products,write_orders']);

        Http::fake([
            'https://'.self::SHOP.'/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_wider',
                'expires_in'   => 86400,
                'scope'        => 'write_products,write_orders',
            ]),
            'https://'.self::SHOP.'/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 999]]),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->getJson('/api/products')
            ->assertOk();

        $this->assertTrue($this->stores()->findByDomain(self::SHOP)->hasRequiredScopes());
    }

    #[Test]
    public function a_scope_upgrade_the_merchant_has_not_granted_falls_back_to_oauth(): void
    {
        $this->store(['scopes' => 'write_products']);

        config(['shopifyIntegration.scopes' => 'write_products,write_orders']);

        Http::fake([
            'https://'.self::SHOP.'/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_same',
                'expires_in'   => 86400,
                'scope'        => 'write_products',
            ]),
            'https://'.self::SHOP.'/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 999]]),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->getJson('/api/products')
            ->assertStatus(403)
            ->assertJson(['error' => 'reauthorization_required']);
    }

    /*
    |--------------------------------------------------------------------------
    | shopifyIntegration.embedded
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_installed_store_renders_the_host_page_from_the_shop_parameter(): void
    {
        $this->store();

        $this->get('/shopify/app?shop='.self::SHOP.'&host=abc123')
            ->assertOk()
            ->assertSee('host page');
    }

    #[Test]
    public function the_id_token_on_the_iframe_url_authenticates_the_document_request(): void
    {
        $this->store();

        $this->get('/shopify/app?shop='.self::SHOP.'&id_token='.$this->token())
            ->assertOk()
            ->assertSee('host page');

        $this->assertSame(self::SHOP, ShopifyIntegration::currentStore()?->store_domain);
    }

    /**
     * Shopify requires an embedded app to name its framers, and a stray
     * X-Frame-Options from elsewhere in the stack blanks the app inside the
     * admin with nothing in the response to explain it.
     */
    #[Test]
    public function the_host_page_may_be_framed_by_the_admin(): void
    {
        $this->store();

        $response = $this->get('/shopify/app?shop='.self::SHOP.'&host=abc123');

        $response->assertHeader(
            'Content-Security-Policy',
            'frame-ancestors https://'.self::SHOP.' https://admin.shopify.com;'
        );

        $this->assertNull($response->headers->get('X-Frame-Options'));
    }

    /**
     * Shopify's authorize page refuses to be framed, so a 302 from inside the
     * iframe renders an empty box. The app has to break out to the top window.
     */
    #[Test]
    public function an_uninstalled_store_breaks_out_of_the_frame_to_reach_oauth(): void
    {
        $response = $this->get('/shopify/app?shop='.self::SHOP.'&host=abc123');

        $response->assertOk();
        $response->assertSee('window.top.location', false);
        $response->assertSee('https://test-app.com/shopify/auth/begin?shop='.self::SHOP, false);
    }

    #[Test]
    public function the_same_request_outside_a_frame_is_a_plain_redirect(): void
    {
        $this->get('/shopify/app?shop='.self::SHOP)
            ->assertRedirect('https://test-app.com/shopify/auth/begin?shop='.self::SHOP);
    }

    #[Test]
    public function a_visit_with_no_shop_at_all_goes_to_the_listing(): void
    {
        config(['shopifyIntegration.oauth.listing_url' => 'https://apps.shopify.com/acme']);

        $this->get('/shopify/app')->assertRedirect('https://apps.shopify.com/acme');
    }

    #[Test]
    public function a_bogus_shop_parameter_is_not_treated_as_a_store(): void
    {
        config(['shopifyIntegration.oauth.listing_url' => null]);

        $this->get('/shopify/app?shop=attacker.example.com')->assertStatus(400);
    }
}
