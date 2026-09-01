<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Events\StoreUninstalled;
use ShopGPT\ShopifyIntegration\Exceptions\RateLimitedException;
use ShopGPT\ShopifyIntegration\Exceptions\ShopifyApiException;
use ShopGPT\ShopifyIntegration\Exceptions\StoreUnavailableException;
use ShopGPT\ShopifyIntegration\Exceptions\StoreUninstalledException;
use ShopGPT\ShopifyIntegration\Models\Integration;

class ApiClientTest extends TestCase
{
    private function store(array $attributes = []): Integration
    {
        return Integration::query()->create(array_merge([
            'store_domain'     => 'acme.myshopify.com',
            'access_token'     => 'shpat_token',
            'refresh_token'    => 'shprt_token',
            'token_expires_at' => now()->addDay(),
        ], $attributes));
    }

    #[Test]
    public function it_sends_the_access_token_and_the_configured_api_version(): void
    {
        Http::fake(['*' => Http::response(['data' => ['shop' => ['name' => 'Acme']]])]);
        config(['shopifyIntegration.api_version' => '2025-07']);

        $this->store()->api()->graphql('{ shop { name } }');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://acme.myshopify.com/admin/api/2025-07/graphql.json'
                && $request->header('X-Shopify-Access-Token')[0] === 'shpat_token';
        });
    }

    /** A near-expiry token is rotated before the call, not after it fails. */
    #[Test]
    public function it_refreshes_an_expiring_token_before_calling(): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_rotated', 'refresh_token' => 'shprt_new', 'expires_in' => 86400,
            ]),
            '*/graphql.json' => Http::response(['data' => []]),
        ]);

        $store = $this->store(['token_expires_at' => now()->addSeconds(30)]);

        $store->api()->graphql('{ shop { name } }');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'graphql.json')
            && $r->header('X-Shopify-Access-Token')[0] === 'shpat_rotated');
    }

    /*
    |--------------------------------------------------------------------------
    | Error taxonomy
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_401_means_uninstalled_and_flags_the_store(): void
    {
        Http::fake(['*' => Http::response('', 401)]);
        Event::fake([StoreUninstalled::class]);

        $store = $this->store();

        try {
            $store->api()->graphql('{ shop { name } }');
            $this->fail('Expected StoreUninstalledException.');
        } catch (StoreUninstalledException $e) {
            $this->assertSame($store->getKey(), $e->store->getKey());
        }

        $this->assertFalse($store->fresh()->isInstalled());
        Event::assertDispatched(StoreUninstalled::class);
    }

    /**
     * The distinction that matters. A frozen store is a paying customer whose
     * bill bounced, not an uninstall — marking it uninstalled would stop every
     * job for them and nothing would mark it alive again.
     */
    #[Test]
    public function a_402_is_frozen_and_does_not_mark_the_store_uninstalled(): void
    {
        Http::fake(['*' => Http::response('', 402)]);
        Event::fake([StoreUninstalled::class]);

        $store = $this->store();

        try {
            $store->api()->graphql('{ shop { name } }');
            $this->fail('Expected StoreUnavailableException.');
        } catch (StoreUnavailableException $e) {
            $this->assertTrue($e->isFrozen());
            $this->assertFalse($e->isLocked());
        }

        $this->assertTrue($store->fresh()->isInstalled());
        Event::assertNotDispatched(StoreUninstalled::class);
    }

    #[Test]
    public function a_423_is_locked_and_does_not_mark_the_store_uninstalled(): void
    {
        Http::fake(['*' => Http::response('', 423)]);

        $store = $this->store();

        try {
            $store->api()->graphql('{ shop { name } }');
            $this->fail('Expected StoreUnavailableException.');
        } catch (StoreUnavailableException $e) {
            $this->assertTrue($e->isLocked());
        }

        $this->assertTrue($store->fresh()->isInstalled());
    }

    #[Test]
    public function a_frozen_store_is_not_confused_with_an_uninstalled_one(): void
    {
        Http::fake(['*' => Http::response('', 402)]);

        $this->expectException(StoreUnavailableException::class);

        // Guards against StoreUnavailableException ever being made a subclass
        // of StoreUninstalledException, which would silently reintroduce the bug.
        $this->assertFalse(
            is_a(StoreUnavailableException::class, StoreUninstalledException::class, true),
        );

        $this->store()->api()->graphql('{ shop { name } }');
    }

    #[Test]
    public function a_429_reports_the_retry_after_hint(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '7'])]);

        try {
            $this->store()->api()->graphql('{ shop { name } }');
            $this->fail('Expected RateLimitedException.');
        } catch (RateLimitedException $e) {
            $this->assertSame(7, $e->retryAfter);
        }
    }

    #[Test]
    public function a_500_is_a_plain_api_error(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        try {
            $this->store()->api()->graphql('{ shop { name } }');
            $this->fail('Expected ShopifyApiException.');
        } catch (ShopifyApiException $e) {
            $this->assertSame(500, $e->getCode());
            $this->assertSame('boom', $e->body);
            $this->assertNotInstanceOf(StoreUninstalledException::class, $e);
        }

        $this->assertTrue($this->stores()->findByDomain('acme.myshopify.com')->isInstalled());
    }

    #[Test]
    public function rest_helpers_hit_the_versioned_admin_path(): void
    {
        Http::fake(['*' => Http::response(['products' => []])]);
        config(['shopifyIntegration.api_version' => '2025-07']);

        $this->store()->api()->get('products.json', ['limit' => 250]);

        Http::assertSent(fn ($r) => str_starts_with(
            $r->url(), 'https://acme.myshopify.com/admin/api/2025-07/products.json',
        ));
    }
}
