<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Events\OAuthFailed;
use ShopGPT\ShopifyIntegration\Events\StoreInstalled;
use ShopGPT\ShopifyIntegration\Events\StoreReinstalled;
use ShopGPT\ShopifyIntegration\Events\StoreTokenExchanged;
use ShopGPT\ShopifyIntegration\Models\Integration;
use ShopGPT\ShopifyIntegration\Services\SessionTokenService;
use ShopGPT\ShopifyIntegration\Services\TokenExchangeService;

class TokenExchangeTest extends TestCase
{
    private const SHOP = 'acme.myshopify.com';

    private function fakeShopify(array $tokenResponse = [], int $status = 200): void
    {
        Http::fake([
            'https://'.self::SHOP.'/admin/oauth/access_token' => Http::response(array_merge([
                'access_token'  => 'shpat_exchanged',
                'refresh_token' => 'shprt_refresh',
                'expires_in'    => 86400,
                'scope'         => 'write_products',
            ], $tokenResponse), $status),

            'https://'.self::SHOP.'/admin/api/*/shop.json' => Http::response([
                'shop' => [
                    'id'         => 111222333,
                    'name'       => 'Acme',
                    'domain'     => 'acme.com',
                    'email'      => 'owner@acme.com',
                    'shop_owner' => 'Wile E. Coyote',
                    'currency'   => 'USD',
                    'plan_name'  => 'basic',
                ],
            ]),
        ]);
    }

    private function exchange(): ?Integration
    {
        $token = $this->app->make(SessionTokenService::class)->mint(self::SHOP);

        return $this->app->make(TokenExchangeService::class)->exchange(self::SHOP, $token);
    }

    #[Test]
    public function it_sends_the_documented_token_exchange_grant(): void
    {
        $this->fakeShopify();

        $this->exchange();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'oauth/access_token')) {
                return true;
            }

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
                && $request['subject_token_type'] === 'urn:ietf:params:oauth:token-type:id_token'
                && $request['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token'
                // Without this Shopify issues a permanent token, which cannot
                // be rotated and is being withdrawn for public apps.
                && $request['expiring'] === '1';
        });
    }

    #[Test]
    public function it_stores_the_token_and_the_shop_profile(): void
    {
        $this->fakeShopify();

        $store = $this->exchange();

        $this->assertNotNull($store);
        $this->assertSame('shpat_exchanged', $store->access_token);
        $this->assertSame('shprt_refresh', $store->refresh_token);
        $this->assertSame('111222333', $store->external_id);
        $this->assertSame('acme.com', $store->domain);
        $this->assertSame(self::SHOP, $store->store_domain);
        $this->assertTrue($store->isInstalled());
        $this->assertTrue($store->token_expires_at->isFuture());
    }

    /**
     * A merchant installing from the App Store never touches the OAuth
     * redirect, so if the exchange stayed silent an app's onboarding would
     * simply never run for them.
     */
    #[Test]
    public function a_first_exchange_raises_the_same_install_events_as_the_redirect_flow(): void
    {
        Event::fake([StoreInstalled::class, StoreReinstalled::class, StoreTokenExchanged::class]);
        $this->fakeShopify();

        $this->exchange();

        Event::assertDispatched(StoreTokenExchanged::class);
        Event::assertDispatched(StoreInstalled::class, function (StoreInstalled $event) {
            // The event class is the classification now; the context carries
            // only facts about the install itself.
            return $event->context->viaTokenExchange
                && $event->context->domain() === self::SHOP;
        });
        Event::assertNotDispatched(StoreReinstalled::class);
    }

    #[Test]
    public function an_exchange_for_a_previously_uninstalled_store_is_a_reinstall(): void
    {
        Integration::query()->create([
            'store_domain'   => self::SHOP,
            'access_token'   => 'shpat_old',
            'uninstalled_at' => now()->subDay(),
        ]);

        Event::fake([StoreInstalled::class, StoreReinstalled::class]);
        $this->fakeShopify();

        $store = $this->exchange();

        $this->assertTrue($store->isInstalled());
        $this->assertSame('shpat_exchanged', $store->access_token);
        Event::assertDispatched(StoreReinstalled::class);
        Event::assertNotDispatched(StoreInstalled::class);
    }

    /**
     * The recovery path: an installed store whose token was revoked gets a
     * new one without the merchant seeing anything. That must not look like a
     * fresh install, or onboarding would re-run on a token hiccup.
     */
    #[Test]
    public function re_exchanging_for_an_installed_store_raises_no_install_event(): void
    {
        Integration::query()->create([
            'store_domain' => self::SHOP,
            'access_token' => 'shpat_revoked',
            'installed_at' => now()->subMonth(),
        ]);

        Event::fake([StoreInstalled::class, StoreReinstalled::class, StoreTokenExchanged::class]);
        $this->fakeShopify();

        $store = $this->exchange();

        $this->assertSame('shpat_exchanged', $store->access_token);
        Event::assertDispatched(StoreTokenExchanged::class);
        Event::assertNotDispatched(StoreInstalled::class);
        Event::assertNotDispatched(StoreReinstalled::class);
    }

    #[Test]
    public function a_rejected_exchange_returns_null_and_writes_nothing(): void
    {
        Event::fake([OAuthFailed::class]);

        Http::fake([
            'https://'.self::SHOP.'/admin/oauth/access_token' => Http::response(
                ['error' => 'invalid_subject_token'], 400
            ),
        ]);

        $this->assertNull($this->exchange());
        $this->assertSame(0, Integration::query()->count());
        Event::assertDispatched(OAuthFailed::class);
    }

    #[Test]
    public function an_install_that_cannot_read_the_shop_profile_still_succeeds(): void
    {
        Http::fake([
            'https://'.self::SHOP.'/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_exchanged',
                'expires_in'   => 86400,
                'scope'        => 'write_products',
            ]),
            'https://'.self::SHOP.'/admin/api/*/shop.json' => Http::response([], 500),
        ]);

        $store = $this->exchange();

        $this->assertNotNull($store);
        $this->assertSame('shpat_exchanged', $store->access_token);
        $this->assertNull($store->name);
    }

    #[Test]
    public function the_granted_scope_is_trusted_over_the_requested_one(): void
    {
        config(['shopifyIntegration.scopes' => 'write_products,write_orders']);

        $this->fakeShopify(['scope' => 'write_products']);

        $store = $this->exchange();

        $this->assertSame('write_products', $store->scopes);
        $this->assertFalse($store->hasRequiredScopes());
    }
}
