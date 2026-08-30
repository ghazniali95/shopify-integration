<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Events\OAuthFailed;
use ShopGPT\ShopifyIntegration\Events\OAuthStarted;
use ShopGPT\ShopifyIntegration\Events\StoreInstalled;
use ShopGPT\ShopifyIntegration\Events\StoreReinstalled;
use ShopGPT\ShopifyIntegration\Models\Integration;
use ShopGPT\ShopifyIntegration\Support\OAuthState;

class OAuthFlowTest extends TestCase
{
    private const SHOP = 'acme.myshopify.com';

    private function fakeShopify(array $overrides = []): void
    {
        Http::fake(array_merge([
            '*/admin/oauth/access_token' => Http::response([
                'access_token'  => 'shpat_new_token',
                'refresh_token' => 'shprt_refresh',
                'expires_in'    => 86400,
                'scope'         => 'write_products',
            ]),
            '*/admin/api/*/shop.json' => Http::response([
                'shop' => [
                    'id'               => 987654321,
                    'name'             => 'Acme Store',
                    'email'            => 'owner@acme.test',
                    'domain'           => 'acme-store.com',
                    'myshopify_domain' => self::SHOP,
                    'shop_owner'       => 'Dana Acme',
                    'phone'            => '+15550000',
                    'currency'         => 'USD',
                    'country_code'     => 'US',
                    'country_name'     => 'United States',
                    'primary_locale'   => 'en',
                    'plan_name'        => 'basic',
                    'weight_unit'      => 'kg',
                    'password_enabled' => false,
                    'money_format'     => '${{amount}}',
                ],
            ]),
        ], $overrides));
    }

    private function completeInstall(): \Illuminate\Testing\TestResponse
    {
        $state = OAuthState::issue(self::SHOP);

        return $this->get($this->signed('/shopify/auth/callback', [
            'shop'  => self::SHOP,
            'code'  => 'auth-code',
            'state' => $state,
        ]));
    }

    /*
    |--------------------------------------------------------------------------
    | begin
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function begin_redirects_to_shopify_with_state(): void
    {
        Event::fake([OAuthStarted::class]);

        $response = $this->get($this->signed('/shopify/auth/begin', ['shop' => self::SHOP]));

        $response->assertRedirect();
        $target = $response->headers->get('Location');

        $this->assertStringStartsWith('https://'.self::SHOP.'/admin/oauth/authorize?', $target);
        $this->assertStringContainsString('client_id=test-client-id', $target);
        $this->assertStringContainsString('scope=write_products', $target);
        $this->assertStringContainsString(urlencode('https://test-app.com/shopify/auth/callback'), $target);

        parse_str(parse_url($target, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state']);

        Event::assertDispatched(OAuthStarted::class);
    }

    #[Test]
    public function begin_rejects_an_unsigned_request(): void
    {
        Event::fake([OAuthFailed::class]);

        $this->get('/shopify/auth/begin?shop='.self::SHOP)->assertStatus(401);

        Event::assertDispatched(OAuthFailed::class);
    }

    #[Test]
    public function begin_rejects_a_non_shopify_domain(): void
    {
        $this->get($this->signed('/shopify/auth/begin', ['shop' => 'evil.com']))
            ->assertStatus(400);
    }

    #[Test]
    public function begin_sends_a_visitor_with_no_shop_to_the_listing(): void
    {
        config(['shopifyIntegration.oauth.listing_url' => 'https://apps.shopify.com/acme']);

        $this->get('/shopify/auth/begin')
            ->assertRedirect('https://apps.shopify.com/acme');
    }

    /*
    |--------------------------------------------------------------------------
    | callback
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function callback_stores_the_token_and_the_promoted_profile(): void
    {
        $this->fakeShopify();

        $this->completeInstall()->assertRedirect('/');

        $store = Integration::forDomain(self::SHOP);

        $this->assertNotNull($store);
        $this->assertSame('shpat_new_token', $store->access_token);
        $this->assertSame('shprt_refresh', $store->refresh_token);
        $this->assertSame('987654321', $store->external_id);
        $this->assertSame('write_products', $store->scopes);
        $this->assertTrue($store->isInstalled());
        $this->assertNotNull($store->installed_at);
        $this->assertTrue($store->token_expires_at->isFuture());

        // the two domains stay distinct
        $this->assertSame(self::SHOP, $store->store_domain);
        $this->assertSame('acme-store.com', $store->domain);

        // promoted profile
        $this->assertSame('Acme Store', $store->name);
        $this->assertSame('owner@acme.test', $store->email);
        $this->assertSame('Dana Acme', $store->shop_owner);
        $this->assertSame('USD', $store->currency);
        $this->assertSame('US', $store->country_code);
        $this->assertSame('United States', $store->country_name);
        $this->assertSame('en', $store->primary_locale);
        $this->assertSame('basic', $store->plan_name);
        $this->assertSame('kg', $store->weight_unit);
        $this->assertFalse($store->password_enabled);

        // everything else kept in the raw snapshot
        $this->assertSame('${{amount}}', $store->shop_data['money_format']);
        $this->assertNotNull($store->shop_data_synced_at);
    }

    #[Test]
    public function the_access_token_is_encrypted_at_rest(): void
    {
        $this->fakeShopify();
        $this->completeInstall();

        $raw = \DB::table('integrations')->value('integration_access_token');

        $this->assertNotSame('shpat_new_token', $raw);
        $this->assertSame('shpat_new_token', Integration::forDomain(self::SHOP)->access_token);
    }

    /**
     * shopGPT-app stores tokens as plaintext today. A row written before the
     * package arrives must keep working, not decrypt to garbage or throw.
     */
    #[Test]
    public function a_plaintext_legacy_token_is_still_readable(): void
    {
        \DB::table('integrations')->insert([
            'integration_platform'     => 'shopify',
            'integration_store_domain' => self::SHOP,
            'integration_access_token' => 'shpat_plaintext_legacy',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $this->assertSame('shpat_plaintext_legacy', Integration::forDomain(self::SHOP)->access_token);
    }

    #[Test]
    public function callback_rejects_a_mismatched_state(): void
    {
        $this->fakeShopify();
        Event::fake([OAuthFailed::class]);

        OAuthState::issue(self::SHOP);

        $this->get($this->signed('/shopify/auth/callback', [
            'shop' => self::SHOP, 'code' => 'auth-code', 'state' => 'not-the-issued-state',
        ]))->assertRedirect('/');

        $this->assertNull(Integration::forDomain(self::SHOP));
        Event::assertDispatched(OAuthFailed::class);
    }

    #[Test]
    public function callback_rejects_a_replayed_state(): void
    {
        $this->fakeShopify();
        $state = OAuthState::issue(self::SHOP);

        $params = ['shop' => self::SHOP, 'code' => 'auth-code', 'state' => $state];

        $this->get($this->signed('/shopify/auth/callback', $params));
        $this->assertNotNull(Integration::forDomain(self::SHOP));

        // The nonce is single-use, so the same URL replayed must not authorise.
        Integration::query()->delete();
        $this->get($this->signed('/shopify/auth/callback', $params))->assertRedirect('/');
        $this->assertNull(Integration::forDomain(self::SHOP));
    }

    #[Test]
    public function callback_rejects_an_unsigned_request(): void
    {
        $this->fakeShopify();
        $state = OAuthState::issue(self::SHOP);

        $this->get('/shopify/auth/callback?'.http_build_query([
            'shop' => self::SHOP, 'code' => 'auth-code', 'state' => $state,
        ]))->assertRedirect('/');

        $this->assertNull(Integration::forDomain(self::SHOP));
    }

    #[Test]
    public function installing_twice_updates_one_row_and_fires_reinstalled(): void
    {
        $this->fakeShopify();
        Event::fake([StoreInstalled::class, StoreReinstalled::class]);

        $this->completeInstall();
        Event::assertDispatched(StoreInstalled::class);

        $store = Integration::forDomain(self::SHOP);
        $store->markUninstalled();
        $this->assertFalse($store->fresh()->isInstalled());

        $this->completeInstall();

        $this->assertSame(1, Integration::query()->count());
        $this->assertTrue(Integration::forDomain(self::SHOP)->isInstalled());
        Event::assertDispatched(StoreReinstalled::class);
    }

    /**
     * A renamed store keeps its Shopify id. Matching on the id rather than the
     * domain is what stops a second row appearing for a store already connected.
     */
    #[Test]
    public function a_store_that_changed_domain_is_matched_by_external_id(): void
    {
        $this->fakeShopify();
        $this->completeInstall();

        Integration::forDomain(self::SHOP)->forceFill([
            'integration_store_domain' => 'old-name.myshopify.com',
        ])->save();

        $this->completeInstall();

        $this->assertSame(1, Integration::query()->count());
        $this->assertSame(self::SHOP, Integration::query()->first()->store_domain);
    }

    #[Test]
    public function an_install_survives_shop_json_being_unavailable(): void
    {
        $this->fakeShopify(['*/admin/api/*/shop.json' => Http::response('', 500)]);

        $this->completeInstall()->assertRedirect('/');

        $store = Integration::forDomain(self::SHOP);
        $this->assertNotNull($store);
        $this->assertSame('shpat_new_token', $store->access_token);
        $this->assertNull($store->name);
    }

    #[Test]
    public function an_embedded_install_returns_to_the_admin_frame(): void
    {
        config(['shopifyIntegration.embedded.enabled' => true]);
        $this->fakeShopify();

        $state = OAuthState::issue(self::SHOP);

        $response = $this->get($this->signed('/shopify/auth/callback', [
            'shop' => self::SHOP, 'code' => 'auth-code', 'state' => $state, 'host' => 'YWNtZS9hZG1pbg',
        ]));

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/shopify/app', $location);
        $this->assertStringContainsString('host=YWNtZS9hZG1pbg', $location);
    }
}
