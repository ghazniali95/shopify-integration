<?php

namespace ShopGPT\ShopifyIntegration\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ShopGPT\ShopifyIntegration\Jobs\WebhookJob;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;
use ShopGPT\ShopifyIntegration\Support\Hmac;

class WebhookController extends Controller
{
    /**
     * One endpoint, every topic.
     *
     * Returns 200 for anything that is authentic, whatever happens next.
     * Shopify retries a non-2xx for 48 hours and then deletes the
     * subscription outright — so an unknown store, an unhandled topic or a
     * payload we cannot use must still be acknowledged, or one bad webhook
     * quietly costs you every webhook for every store.
     */
    public function handle(Request $request): Response
    {
        $raw = $request->getContent();

        if (! Hmac::verifyWebhook($raw, $request->header('X-Shopify-Hmac-Sha256'), (string) config('shopifyIntegration.client_secret'))) {
            // The one case that is NOT acknowledged: an unsigned request did
            // not come from Shopify, so there is no subscription to protect.
            $this->log()->warning('shopifyIntegration: webhook signature invalid', [
                'topic' => $request->header('X-Shopify-Topic'),
                'shop'  => $request->header('X-Shopify-Shop-Domain'),
                'ip'    => $request->ip(),
            ]);

            return response('Unauthorized', 401);
        }

        $topic      = (string) $request->header('X-Shopify-Topic');
        $shopDomain = (string) $request->header('X-Shopify-Shop-Domain');
        $webhookId  = $request->header('X-Shopify-Webhook-Id');
        $payload    = json_decode($raw, true) ?: [];

        // Never log the body. A products/update payload runs to ~110KB and a
        // busy store repeats it every few seconds; these five fields are what
        // anyone tracing a webhook actually reads.
        $this->log()->info('shopifyIntegration: webhook received', [
            'topic'         => $topic,
            'shop'          => $shopDomain,
            'resource_id'   => $payload['id'] ?? null,
            'webhook_id'    => $webhookId,
            'payload_bytes' => strlen($raw),
        ]);

        if ($this->isDuplicate($webhookId)) {
            return response('OK', 200);
        }

        $store = $this->resolveStore($shopDomain);

        if (! $store) {
            // Legitimately common: a GDPR topic for a store that never
            // finished installing, or an uninstall for a row already removed.
            $this->log()->info('shopifyIntegration: webhook for an unknown store, acknowledged', [
                'topic' => $topic,
                'shop'  => $shopDomain,
            ]);

            return response('OK', 200);
        }

        $job = $this->jobFor($topic);

        if (! $job) {
            $this->log()->info('shopifyIntegration: no handler configured for topic, acknowledged', [
                'topic' => $topic,
                'shop'  => $shopDomain,
            ]);

            return response('OK', 200);
        }

        dispatch($job::fromWebhook($store->getKey(), $topic, $payload, $webhookId));

        return response('OK', 200);
    }

    /**
     * Shopify redelivers a webhook it did not hear a 200 for, and the retry
     * carries the same X-Shopify-Webhook-Id. Dropping the repeat keeps a slow
     * response from turning into duplicated work.
     */
    private function isDuplicate(?string $webhookId): bool
    {
        if (! $webhookId) {
            return false;
        }

        $key = 'shopifyIntegration:webhook:'.$webhookId;

        // add() is atomic: it returns false if another process already claimed
        // this id, so two concurrent deliveries cannot both proceed.
        return ! Cache::add($key, true, now()->addMinutes(10));
    }

    private function resolveStore(string $shopDomain): ?ShopifyStore
    {
        if ($shopDomain === '') {
            return null;
        }

        return app(ShopifyStoreRepository::class)->findByDomain($shopDomain);
    }

    /** @return class-string<WebhookJob>|null */
    private function jobFor(string $topic): ?string
    {
        $job = config('shopifyIntegration.webhooks.topics.'.$topic);

        return is_string($job) && is_subclass_of($job, WebhookJob::class) ? $job : null;
    }

    private function log()
    {
        return Log::channel(config('shopifyIntegration.webhooks.log_channel'));
    }
}
