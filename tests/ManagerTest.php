<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Exceptions\ShopifyIntegrationException;
use ShopGPT\ShopifyIntegration\Facades\ShopifyIntegration;
use ShopGPT\ShopifyIntegration\Models\Integration;

/**
 * The manager's own state — which store the current request is acting as.
 *
 * Worth its own file because getting this wrong is the quiet kind of bug: no
 * exception, no failing call, just one merchant's data written into another
 * merchant's account.
 */
class ManagerTest extends TestCase
{
    #[Test]
    public function no_store_is_current_until_one_is_set(): void
    {
        $this->assertNull(ShopifyIntegration::currentStore());

        $store = $this->store('acme.myshopify.com');
        ShopifyIntegration::setCurrentStore($store);

        $this->assertSame($store, ShopifyIntegration::currentStore());

        ShopifyIntegration::setCurrentStore(null);

        $this->assertNull(ShopifyIntegration::currentStore());
    }

    #[Test]
    public function as_store_restores_whatever_was_current_before(): void
    {
        $outer = $this->store('outer.myshopify.com');
        $inner = $this->store('inner.myshopify.com');

        ShopifyIntegration::setCurrentStore($outer);

        $seen = ShopifyIntegration::asStore($inner, function (ShopifyStore $store) {
            // The callback receives the store, and it is the current one.
            $this->assertSame($store, ShopifyIntegration::currentStore());

            return $store->shopifyDomain();
        });

        $this->assertSame('inner.myshopify.com', $seen);
        $this->assertSame($outer, ShopifyIntegration::currentStore());
    }

    #[Test]
    public function as_store_restores_the_previous_store_even_when_the_callback_throws(): void
    {
        $outer = $this->store('outer.myshopify.com');

        ShopifyIntegration::setCurrentStore($outer);

        try {
            ShopifyIntegration::asStore($this->store('inner.myshopify.com'), function () {
                throw new \RuntimeException('the job failed');
            });
        } catch (\RuntimeException) {
            // The point of the test is what survives the throw.
        }

        // Without the finally, every store after this one in a loop would run
        // as the store that blew up.
        $this->assertSame($outer, ShopifyIntegration::currentStore());
    }

    #[Test]
    public function nesting_unwinds_in_order(): void
    {
        $a = $this->store('a.myshopify.com');
        $b = $this->store('b.myshopify.com');

        ShopifyIntegration::asStore($a, function () use ($b) {
            ShopifyIntegration::asStore($b, function () use ($b) {
                $this->assertSame($b->shopifyDomain(), ShopifyIntegration::currentStore()->shopifyDomain());
            });

            $this->assertSame('a.myshopify.com', ShopifyIntegration::currentStore()->shopifyDomain());
        });

        $this->assertNull(ShopifyIntegration::currentStore());
    }

    #[Test]
    public function for_domain_reads_through_the_repository(): void
    {
        $this->store('lookup.myshopify.com');

        $this->assertSame(
            'lookup.myshopify.com',
            ShopifyIntegration::forDomain('lookup.myshopify.com')?->shopifyDomain(),
        );

        $this->assertNull(ShopifyIntegration::forDomain('unknown.myshopify.com'));
    }

    #[Test]
    public function an_install_refuses_to_run_against_a_map_with_no_domain_column(): void
    {
        config()->set('shopifyIntegration.store.columns', ['store_domain' => null]);

        $this->expectException(ShopifyIntegrationException::class);
        $this->expectExceptionMessage('store_domain');

        $this->stores()->persistInstall(null, 'acme.myshopify.com', [
            'access_token' => 'shpat_token',
        ], []);
    }

    private function store(string $domain): ShopifyStore
    {
        return Integration::create([
            'platform'     => 'shopify',
            'store_domain' => $domain,
            'access_token' => 'shpat_token',
        ]);
    }
}
