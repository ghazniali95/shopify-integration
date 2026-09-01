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

    /**
     * A "connect your store" button on your own site, and every reauthorisation
     * URL this package hands out, carry no signature and cannot invent one.
     * Refusing them made installUrl() and all three reauthorise paths 401.
     */
    #[Test]
    public function begin_accepts_an_unsigned_merchant_initiated_request(): void
    {
        Event::fake([OAuthStarted::class, OAuthFailed::class]);

        $response = $this->get('/shopify/auth/begin?shop='.self::SHOP);

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://'.self::SHOP.'/admin/oauth/authorize?',
            $response->headers->get('Location'),
        );

        Event::assertDispatched(OAuthStarted::class);
        Event::assertNotDispatched(OAuthFailed::class);
    }

    /** Absent is fine; present and wrong is not. */
    #[Test]
    public function begin_rejects_a_request_carrying_a_bad_signature(): void
    {
        Event::fake([OAuthFailed::class, OAuthStarted::class]);

        $this->get('/shopify/auth/begin?shop='.self::SHOP.'&hmac=deadbeef')->assertStatus(401);

        Event::assertDispatched(OAuthFailed::class, fn ($e) => $e->reason === 'hmac');
        Event::assertNotDispatched(OAuthStarted::class);
    }

    /** A signed install URL kept from a log must not still work an hour later. */
    #[Test]
    public function begin_rejects_a_signed_request_that_has_gone_stale(): void
    {
        Event::fake([OAuthFailed::class, OAuthStarted::class]);

        $this->get($this->signed('/shopify/auth/begin', [
            'shop' => self::SHOP, 'timestamp' => (string) (time() - 3600),
        ]))->assertStatus(401);

        Event::assertDispatched(OAuthFailed::class, fn ($e) => $e->reason === 'hmac');
        Event::assertNotDispatched(OAuthStarted::class);
    }

    /** The end-to-end version of the bug: the package's own URL must be usable. */
    #[Test]
    public function the_install_url_the_package_generates_is_accepted_by_begin(): void
    {
        $url = \ShopGPT\ShopifyIntegration\Facades\ShopifyIntegration::installUrl(self::SHOP);

        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))
            ->assertRedirect();
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

    /**
     * The merchant pressed Cancel. A normal outcome, and a listener has to be
     * able to tell it from a malformed request — one is a person changing
     * their mind, the other is a bug or an attack.
     */
    #[Test]
    public function callback_reports_a_declined_authorisation_distinctly(): void
    {
        Event::fake([OAuthFailed::class, StoreInstalled::class]);

        $this->get($this->signed('/shopify/auth/callback', [
            'shop' => self::SHOP, 'error' => 'access_denied',
        ]))->assertRedirect('/');

        Event::assertDispatched(OAuthFailed::class, fn ($e) => $e->reason === 'access_denied');
        Event::assertNotDispatched(StoreInstalled::class);
        $this->assertNull(Integration::forDomain(self::SHOP));
    }

    /**
     * Two installs started for one store at once. Keyed by shop alone, the
     * second issue overwrote the first, and the first tab's callback failed
     * on a nonce the package had itself handed out.
     */
    #[Test]
    public function two_concurrent_installs_for_one_store_both_stay_valid(): void
    {
        $this->fakeShopify();

        $first  = OAuthState::issue(self::SHOP);
        $second = OAuthState::issue(self::SHOP);

        $this->assertNotSame($first, $second);

        $this->get($this->signed('/shopify/auth/callback', [
            'shop' => self::SHOP, 'code' => 'auth-code', 'state' => $first,
        ]));

        $this->assertNotNull(Integration::forDomain(self::SHOP));

        Integration::query()->delete();

        $this->get($this->signed('/shopify/auth/callback', [
            'shop' => self::SHOP, 'code' => 'auth-code', 'state' => $second,
        ]));

        $this->assertNotNull(Integration::forDomain(self::SHOP));
    }

    /** A state parameter is a cache key now, so its shape is checked first. */
    #[Test]
    public function callback_rejects_a_malformed_state_parameter(): void
    {
        $this->fakeShopify();

        OAuthState::issue(self::SHOP);

        $this->get($this->signed('/shopify/auth/callback', [
            'shop' => self::SHOP, 'code' => 'auth-code', 'state' => '../../some:other:key',
        ]))->assertRedirect('/');

        $this->assertNull(Integration::forDomain(self::SHOP));
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

        // base64url of admin.shopify.com/store/acme — what Shopify actually sends.
        $host = rtrim(strtr(base64_encode('admin.shopify.com/store/acme'), '+/', '-_'), '=');

        $response = $this->get($this->signed('/shopify/auth/callback', [
            'shop' => self::SHOP, 'code' => 'auth-code', 'state' => $state, 'host' => $host,
        ]));

        // Handing the browser back to Shopify is what puts the app in the
        // frame. Redirecting to the app's own entry path renders it at top
        // level, outside the admin entirely.
        $response->assertRedirect('https://admin.shopify.com/store/acme/apps/test-client-id');
    }

    /** No host, or one that is not a Shopify admin: derive it from the domain. */
    #[Test]
    public function an_embedded_install_falls_back_to_the_derived_admin_url(): void
    {
        config(['shopifyIntegration.embedded.enabled' => true]);
        $this->fakeShopify();

        $state = OAuthState::issue(self::SHOP);

        $this->get($this->signed('/shopify/auth/callback', [
            'shop' => self::SHOP, 'code' => 'auth-code', 'state' => $state,
        ]))->assertRedirect('https://admin.shopify.com/store/acme/apps/test-client-id');
    }

    /** An attacker-supplied host must never become the redirect target. */
    #[Test]
    public function an_embedded_install_refuses_a_foreign_host(): void
    {
        config(['shopifyIntegration.embedded.enabled' => true]);
        $this->fakeShopify();

        $state = OAuthState::issue(self::SHOP);
        $host  = rtrim(strtr(base64_encode('evil.example.com/store/acme'), '+/', '-_'), '=');

        $this->get($this->signed('/shopify/auth/callback', [
            'shop' => self::SHOP, 'code' => 'auth-code', 'state' => $state, 'host' => $host,
        ]))->assertRedirect('https://admin.shopify.com/store/acme/apps/test-client-id');
    }

    /*
    |--------------------------------------------------------------------------
    | The debug switch
    |--------------------------------------------------------------------------
    */

    /**
     * The default that matters. `begin` only redirects to Shopify's own
     * authorize page, so an unsigned request there is admitted — but the
     * callback is where the install is actually granted, and an unsigned one
     * must be refused unless someone deliberately turned the check off.
     */
    #[Test]
    public function an_unsigned_callback_is_refused_by_default(): void
    {
        $this->fakeShopify();
        Event::fake([OAuthFailed::class, StoreInstalled::class]);

        $this->assertFalse(config('shopifyIntegration.debug'));

        $state = OAuthState::issue(self::SHOP);

        $this->get('/shopify/auth/callback?'.http_build_query([
            'shop' => self::SHOP, 'code' => 'auth-code', 'state' => $state,
        ]))->assertRedirect('/');

        $this->assertNull(Integration::forDomain(self::SHOP));
        Event::assertDispatched(OAuthFailed::class, fn ($e) => $e->reason === 'hmac');
        Event::assertNotDispatched(StoreInstalled::class);
    }

    #[Test]
    public function the_debug_switch_lets_an_unsigned_install_through(): void
    {
        Event::fake([OAuthStarted::class]);

        config(['shopifyIntegration.debug' => true]);

        $this->get('/shopify/auth/begin?shop='.self::SHOP)
            ->assertRedirectContains('https://'.self::SHOP.'/admin/oauth/authorize');

        Event::assertDispatched(OAuthStarted::class);
    }

    /**
     * The callback is a separate public request carrying the authorisation
     * code, so the same switch has to govern it — otherwise a developer who
     * turns debug on gets through the install and is then stopped halfway.
     */
    #[Test]
    public function the_debug_switch_also_covers_the_callback(): void
    {
        config(['shopifyIntegration.debug' => true]);
        $this->fakeShopify();

        $state = OAuthState::issue(self::SHOP);

        $this->get('/shopify/auth/callback?shop='.self::SHOP.'&code=abc123&state='.$state)
            ->assertRedirect();

        $this->assertNotNull(Integration::forDomain(self::SHOP));
    }
}
