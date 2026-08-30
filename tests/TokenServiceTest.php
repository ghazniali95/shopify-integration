<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Events\StoreTokenRefreshed;
use ShopGPT\ShopifyIntegration\Exceptions\TokenRefreshException;
use ShopGPT\ShopifyIntegration\Models\Integration;
use ShopGPT\ShopifyIntegration\Services\TokenService;

class TokenServiceTest extends TestCase
{
    private function store(array $attributes = []): Integration
    {
        return Integration::query()->create(array_merge([
            'integration_store_domain'     => 'acme.myshopify.com',
            'integration_access_token'     => 'shpat_current',
            'integration_refresh_token'    => 'shprt_current',
            'integration_token_expires_at' => now()->addDay(),
        ], $attributes));
    }

    private function service(): TokenService
    {
        return app(TokenService::class);
    }

    #[Test]
    public function a_healthy_token_is_left_alone(): void
    {
        Http::fake();

        $this->service()->ensureFresh($this->store());

        Http::assertNothingSent();
    }

    /** A null expiry is a legacy permanent token: valid, and never refreshed. */
    #[Test]
    public function a_permanent_token_is_never_refreshed(): void
    {
        Http::fake();

        $store = $this->service()->ensureFresh($this->store(['integration_token_expires_at' => null]));

        Http::assertNothingSent();
        $this->assertSame('shpat_current', $store->access_token);
    }

    #[Test]
    public function a_token_inside_the_refresh_buffer_is_rotated(): void
    {
        Http::fake(['*/admin/oauth/access_token' => Http::response([
            'access_token'  => 'shpat_rotated',
            'refresh_token' => 'shprt_rotated',
            'expires_in'    => 86400,
        ])]);
        Event::fake([StoreTokenRefreshed::class]);

        // refresh_buffer is 300s, so 60s of life left must trigger a refresh.
        $store = $this->service()->ensureFresh($this->store([
            'integration_token_expires_at' => now()->addSeconds(60),
        ]));

        $this->assertSame('shpat_rotated', $store->access_token);
        $this->assertSame('shprt_rotated', $store->refresh_token);
        $this->assertSame('shpat_rotated', $store->fresh()->access_token);
        Event::assertDispatched(StoreTokenRefreshed::class);
    }

    /**
     * A refresh starts before expiry, so a failure usually leaves a token that
     * still works. Carry on with it rather than breaking the request.
     */
    #[Test]
    public function a_failed_refresh_falls_back_to_the_still_valid_token(): void
    {
        Http::fake(['*/admin/oauth/access_token' => Http::response('', 500)]);

        $store = $this->service()->ensureFresh($this->store([
            'integration_token_expires_at' => now()->addSeconds(60),
        ]));

        $this->assertSame('shpat_current', $store->access_token);
    }

    /** Once it has actually expired there is nothing usable to hand back. */
    #[Test]
    public function a_failed_refresh_on_an_expired_token_throws(): void
    {
        Http::fake(['*/admin/oauth/access_token' => Http::response('', 500)]);

        $this->expectException(TokenRefreshException::class);

        $this->service()->ensureFresh($this->store([
            'integration_token_expires_at' => now()->subMinute(),
        ]));
    }

    #[Test]
    public function a_store_with_no_refresh_token_and_an_expired_token_throws(): void
    {
        Http::fake();

        $this->expectException(TokenRefreshException::class);

        $this->service()->ensureFresh($this->store([
            'integration_refresh_token'    => null,
            'integration_token_expires_at' => now()->subMinute(),
        ]));
    }

    /** Shopify may omit a new refresh token; the existing one stays valid. */
    #[Test]
    public function an_omitted_refresh_token_keeps_the_existing_one(): void
    {
        Http::fake(['*/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_rotated',
            'expires_in'   => 86400,
        ])]);

        $store = $this->service()->refresh($this->store());

        $this->assertSame('shprt_current', $store->refresh_token);
    }
}
