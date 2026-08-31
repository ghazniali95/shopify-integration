<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Events\StoreUninstalled;
use ShopGPT\ShopifyIntegration\Jobs\HandleAppUninstalled;
use ShopGPT\ShopifyIntegration\Jobs\HandleShopRedact;
use ShopGPT\ShopifyIntegration\Models\Integration;

class WebhookTest extends TestCase
{
    private const SHOP = 'acme.myshopify.com';

    private function store(array $attributes = []): Integration
    {
        return Integration::query()->create(array_merge([
            'integration_store_domain' => self::SHOP,
            'integration_access_token' => 'shpat_token',
        ], $attributes));
    }

    private function sendWebhook(string $topic, array $payload, array $headers = [], ?string $secret = 'test-secret')
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            '/shopify/webhooks',
            [], [], [],
            $this->headers(array_merge([
                'X-Shopify-Topic'       => $topic,
                'X-Shopify-Shop-Domain' => self::SHOP,
                'X-Shopify-Webhook-Id'  => 'wh_'.bin2hex(random_bytes(4)),
                'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
                'Content-Type'          => 'application/json',
            ], $headers)),
            $body,
        );
    }

    private function headers(array $headers): array
    {
        $server = [];
        foreach ($headers as $key => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        return $server;
    }

    #[Test]
    public function a_forged_webhook_is_rejected(): void
    {
        Bus::fake();
        $this->store();

        $this->sendWebhook('app/uninstalled', ['id' => 1], secret: 'wrong-secret')
            ->assertStatus(401);

        Bus::assertNothingDispatched();
    }

    /**
     * The one that costs you every webhook if you get it wrong: Shopify
     * retries a non-2xx for 48 hours and then deletes the subscription.
     */
    #[Test]
    public function a_webhook_for_an_unknown_store_is_acknowledged_not_failed(): void
    {
        Bus::fake();

        // No store row at all.
        $this->sendWebhook('app/uninstalled', ['id' => 1])
            ->assertOk()
            ->assertSee('OK');

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function a_topic_with_no_handler_is_acknowledged(): void
    {
        Bus::fake();
        $this->store();

        $this->sendWebhook('orders/create', ['id' => 99])->assertOk();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function a_configured_topic_dispatches_its_job(): void
    {
        Bus::fake();
        $store = $this->store();

        $this->sendWebhook('app/uninstalled', ['id' => 1])->assertOk();

        Bus::assertDispatched(
            HandleAppUninstalled::class,
            fn (HandleAppUninstalled $job) => $job->storeId === $store->getKey()
                && $job->topic === 'app/uninstalled',
        );
    }

    #[Test]
    public function a_redelivered_webhook_is_dropped(): void
    {
        Bus::fake();
        $this->store();

        $id = 'wh_repeat';

        $this->sendWebhook('app/uninstalled', ['id' => 1], ['X-Shopify-Webhook-Id' => $id])->assertOk();
        $this->sendWebhook('app/uninstalled', ['id' => 1], ['X-Shopify-Webhook-Id' => $id])->assertOk();

        Bus::assertDispatchedTimes(HandleAppUninstalled::class, 1);
    }

    #[Test]
    public function uninstall_marks_the_store_and_fires_the_event(): void
    {
        Event::fake([StoreUninstalled::class]);
        $store = $this->store();

        (new HandleAppUninstalled($store->getKey(), 'app/uninstalled', ['id' => 1]))->handle();

        $this->assertFalse($store->fresh()->isInstalled());
        Event::assertDispatched(StoreUninstalled::class);
    }

    #[Test]
    public function uninstall_is_idempotent(): void
    {
        Event::fake([StoreUninstalled::class]);
        $store = $this->store(['integration_uninstalled_at' => now()->subDay()]);

        (new HandleAppUninstalled($store->getKey(), 'app/uninstalled', ['id' => 1]))->handle();

        Event::assertNotDispatched(StoreUninstalled::class);
    }

    /** shop/redact must clear the snapshot AND the promoted PII columns. */
    #[Test]
    public function shop_redact_clears_pii_in_both_places(): void
    {
        $store = $this->store([
            'integration_email'      => 'owner@acme.test',
            'integration_phone'      => '+15550000',
            'integration_shop_owner' => 'Dana Acme',
            'integration_shop_data'  => json_encode(['email' => 'owner@acme.test', 'zip' => '90210']),
        ]);

        (new HandleShopRedact($store->getKey(), 'shop/redact', []))->handle();

        $store = $store->fresh();
        $this->assertNull($store->email);
        $this->assertNull($store->phone);
        $this->assertNull($store->shop_owner);
        $this->assertNull($store->shop_data);
    }

    #[Test]
    public function shop_update_refreshes_the_promoted_profile(): void
    {
        $store = $this->store(['integration_plan_name' => 'partner_test']);

        (new \ShopGPT\ShopifyIntegration\Jobs\HandleShopUpdate($store->getKey(), 'shop/update', [
            'id'        => 1,
            'plan_name' => 'basic',
            'currency'  => 'EUR',
        ]))->handle(app(\ShopGPT\ShopifyIntegration\Services\StoreWriter::class));

        $store = $store->fresh();
        $this->assertSame('basic', $store->plan_name);
        $this->assertSame('EUR', $store->currency);
    }

    #[Test]
    public function the_webhook_route_is_not_behind_csrf(): void
    {
        Bus::fake();
        $this->store();

        // No session, no CSRF token — a signed POST from Shopify must pass.
        $this->sendWebhook('app/uninstalled', ['id' => 1])->assertOk();
    }

    /**
     * The helper an app uses to test its own webhook handlers. If it and the
     * receiver ever disagree about how the signature is built, every consuming
     * app's webhook tests go green against a receiver that would reject the
     * real thing — so they are checked against each other here.
     */
    #[Test]
    public function the_webhook_header_helper_produces_a_delivery_the_receiver_accepts(): void
    {
        Bus::fake();
        $store = $this->store();

        $payload = ['id' => 42, 'domain' => self::SHOP];
        $body    = json_encode($payload);

        $this->call(
            'POST',
            '/shopify/webhooks',
            [], [], [],
            $this->headers(\ShopGPT\ShopifyIntegration\Facades\ShopifyIntegration::webhookHeaders(
                'app/uninstalled', $store, $payload
            )),
            $body,
        )->assertOk();

        Bus::assertDispatched(HandleAppUninstalled::class);
    }
}
