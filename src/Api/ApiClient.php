<?php

namespace ShopGPT\ShopifyIntegration\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ShopGPT\ShopifyIntegration\Events\StoreUninstalled;
use ShopGPT\ShopifyIntegration\Exceptions\RateLimitedException;
use ShopGPT\ShopifyIntegration\Exceptions\ShopifyApiException;
use ShopGPT\ShopifyIntegration\Exceptions\StoreUnavailableException;
use ShopGPT\ShopifyIntegration\Exceptions\StoreUninstalledException;
use ShopGPT\ShopifyIntegration\Models\Integration;
use ShopGPT\ShopifyIntegration\Services\TokenService;

/**
 * The Admin API, bound to one store.
 *
 * Two guarantees for every call: the token is fresh before the request goes
 * out, and a failure is classified rather than thrown as a bare HTTP error.
 */
class ApiClient
{
    /** Wait for the bucket to refill once it drops below this fraction. */
    private const THROTTLE_THRESHOLD = 0.2;

    public function __construct(
        private Integration $store,
        private readonly TokenService $tokens,
    ) {
    }

    public function store(): Integration
    {
        return $this->store;
    }

    /**
     * @param  array<string, mixed>  $variables
     *
     * @throws ShopifyApiException
     */
    public function graphql(string $query, array $variables = []): Response
    {
        $response = $this->send('post', 'graphql.json', [
            'query'     => $query,
            'variables' => (object) $variables,
        ]);

        $this->throttleGraphql($response);

        return $response;
    }

    /**
     * @throws ShopifyApiException
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @throws ShopifyApiException
     */
    public function post(string $path, array $payload = []): Response
    {
        return $this->send('post', $path, $payload);
    }

    /**
     * @throws ShopifyApiException
     */
    public function put(string $path, array $payload = []): Response
    {
        return $this->send('put', $path, $payload);
    }

    /**
     * @throws ShopifyApiException
     */
    public function delete(string $path, array $payload = []): Response
    {
        return $this->send('delete', $path, $payload);
    }

    private function send(string $method, string $path, array $data): Response
    {
        // Every call funnels through here, so this is the one place that has
        // to guarantee the token is still valid.
        $this->store = $this->tokens->ensureFresh($this->store);

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->store->access_token,
            'Accept'                 => 'application/json',
        ])
            ->timeout(30)
            // Only transport failures are retried here. A 4xx will fail
            // identically every time, and 429/5xx are classified below so the
            // caller (or the queue) decides how to back off.
            ->retry(3, 1000, fn ($e) => $e instanceof ConnectionException, throw: false)
            ->{$method}($this->url($path), $data);

        return $this->classify($response);
    }

    /**
     * Turn an HTTP status into something a caller can act on.
     *
     * The distinction that matters is 401 versus 402/423. Only 401 means the
     * merchant removed the app. A frozen or locked store is temporary, and
     * flagging it uninstalled would stop every job for a customer who is
     * still paying.
     */
    private function classify(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $body   = $response->body();

        if ($status === 401) {
            if ($this->store->isInstalled()) {
                $this->store->markUninstalled();
                StoreUninstalled::dispatch($this->store);
            }

            throw new StoreUninstalledException($this->store);
        }

        if (in_array($status, [402, 423], true)) {
            Log::warning('shopifyIntegration: store unavailable', [
                'store'  => $this->store->store_domain,
                'status' => $status,
            ]);

            throw new StoreUnavailableException(
                $this->store,
                $status === 402
                    ? "Store {$this->store->store_domain} is frozen (unpaid bill)."
                    : "Store {$this->store->store_domain} is locked by Shopify.",
                $status,
                $body,
            );
        }

        if ($status === 429) {
            $e = new RateLimitedException($this->store, 'Shopify rate limit exceeded.', 429, $body);
            $e->retryAfter = (int) ($response->header('Retry-After') ?: 2);

            throw $e;
        }

        throw new ShopifyApiException(
            $this->store,
            "Shopify API returned {$status} for {$this->store->store_domain}.",
            $status,
            $body,
        );
    }

    /**
     * GraphQL reports a leaky-bucket cost per query. Pausing when the bucket
     * runs low costs one sleep; letting it empty costs a 429 and a retry.
     */
    private function throttleGraphql(Response $response): void
    {
        $cost = $response->json('extensions.cost.throttleStatus');

        if (! is_array($cost) || ! isset($cost['currentlyAvailable'], $cost['maximumAvailable'])) {
            return;
        }

        $max = (float) $cost['maximumAvailable'];

        if ($max <= 0) {
            return;
        }

        if (((float) $cost['currentlyAvailable'] / $max) >= self::THROTTLE_THRESHOLD) {
            return;
        }

        $restoreRate = (float) ($cost['restoreRate'] ?? 50);
        $deficit     = ($max * self::THROTTLE_THRESHOLD) - (float) $cost['currentlyAvailable'];

        $seconds = $restoreRate > 0 ? (int) ceil($deficit / $restoreRate) : 1;

        if ($seconds > 0) {
            usleep(min($seconds, 5) * 1_000_000);
        }
    }

    private function url(string $path): string
    {
        $version = config('shopifyIntegration.api_version');
        $path    = ltrim($path, '/');

        return "https://{$this->store->store_domain}/admin/api/{$version}/{$path}";
    }
}
