<?php

namespace ShopGPT\ShopifyIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;

/**
 * Base class for every webhook handler.
 *
 * Carries the store id rather than the model, so a job sitting on the queue
 * cannot act on a stale copy of a row that has since been uninstalled.
 */
abstract class WebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int|string $storeId,
        public readonly string $topic,
        public readonly array $payload = [],
        public readonly ?string $webhookId = null,
    ) {
        $this->onQueue(config('shopifyIntegration.webhooks.queue', 'default'));
    }

    /**
     * Trim the payload before it goes onto the queue.
     *
     * The default keeps everything, which is what GDPR topics need. Override
     * it for anything high-volume: a large resource body runs to ~110KB and a
     * busy store can repeat it every few seconds, so a job that re-fetches the
     * resource anyway should carry only the id.
     *
     *     protected static function payloadForQueue(array $payload): array
     *     {
     *         return ['id' => $payload['id'] ?? null];
     *     }
     */
    public static function fromWebhook(int|string $storeId, string $topic, array $payload, ?string $webhookId): static
    {
        return new static($storeId, $topic, static::payloadForQueue($payload), $webhookId);
    }

    protected static function payloadForQueue(array $payload): array
    {
        return $payload;
    }

    /**
     * The store this webhook belongs to, or null if it has since been deleted.
     */
    public function store(): ?ShopifyStore
    {
        return app(ShopifyStoreRepository::class)->findByKey($this->storeId);
    }

    /** The resource the webhook is about, when the payload carries one. */
    public function resourceId(): int|string|null
    {
        return $this->payload['id'] ?? null;
    }
}
