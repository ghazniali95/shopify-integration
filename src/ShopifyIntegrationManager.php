<?php

namespace ShopGPT\ShopifyIntegration;

use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;
use ShopGPT\ShopifyIntegration\Services\OAuthService;
use ShopGPT\ShopifyIntegration\Services\SessionTokenService;
use ShopGPT\ShopifyIntegration\Services\TokenExchangeService;
use ShopGPT\ShopifyIntegration\Services\TokenService;

class ShopifyIntegrationManager
{
    private ?ShopifyStore $currentStore = null;

    public function __construct(
        private readonly ShopifyStoreRepository $stores,
        private readonly OAuthService $oauth,
        private readonly TokenService $tokens,
        private readonly SessionTokenService $sessions,
        private readonly TokenExchangeService $exchange,
    ) {
    }

    /** The store the current request is acting as, if any. */
    public function currentStore(): ?ShopifyStore
    {
        return $this->currentStore;
    }

    public function setCurrentStore(?ShopifyStore $store): void
    {
        $this->currentStore = $store;
    }

    /**
     * Run a callback as a specific store, restoring the previous one after.
     *
     * The guard rail against a queued job or a loop over stores leaking one
     * merchant's data into another's account.
     */
    public function asStore(ShopifyStore $store, callable $callback): mixed
    {
        $previous = $this->currentStore;
        $this->currentStore = $store;

        try {
            return $callback($store);
        } finally {
            $this->currentStore = $previous;
        }
    }

    public function forDomain(string $domain): ?ShopifyStore
    {
        return $this->stores->findByDomain($domain);
    }

    /** The repository every read and write goes through. */
    public function stores(): ShopifyStoreRepository
    {
        return $this->stores;
    }

    /**
     * The Admin API bound to a store, token guaranteed fresh.
     *
     * The storage-agnostic path to a client: `$store->api()` needs the
     * InteractsWithShopifyStore trait, this works on any ShopifyStore.
     */
    public function api(ShopifyStore $store): \ShopGPT\ShopifyIntegration\Api\ApiClient
    {
        return new \ShopGPT\ShopifyIntegration\Api\ApiClient($store, $this->tokens, $this->stores);
    }

    public function ensureFreshToken(ShopifyStore $store): ShopifyStore
    {
        return $this->tokens->ensureFresh($store);
    }

    public function refreshToken(ShopifyStore $store): ShopifyStore
    {
        return $this->tokens->refresh($store);
    }

    /*
    |--------------------------------------------------------------------------
    | Embedded
    |--------------------------------------------------------------------------
    */

    /**
     * Verify an App Bridge session token, returning its claims or null.
     *
     * @return array<string, mixed>|null
     */
    public function verifySessionToken(?string $jwt): ?array
    {
        return $this->sessions->verify($jwt);
    }

    /** The store domain a verified claims array speaks for. */
    public function storeFromClaims(?array $claims): ?string
    {
        return $this->sessions->shopFromClaims($claims);
    }

    /** Trade a verified session token for a stored access token. */
    public function exchangeToken(string $shop, string $sessionToken): ?ShopifyStore
    {
        return $this->exchange->exchange($shop, $sessionToken);
    }

    /**
     * Headers an authenticated request from the admin iframe would carry —
     * for your own tests, so an embedded route can be exercised end to end.
     */
    public function sessionTokenHeaders(ShopifyStore|string $store, array $claims = []): array
    {
        return $this->sessions->headersFor($store, $claims);
    }

    /**
     * Headers a genuine Shopify webhook delivery would carry, signed with your
     * client secret — test support, so an app testing its own webhook handlers
     * never hand-rolls an HMAC.
     */
    public function webhookHeaders(string $topic, ShopifyStore|string $store, array|string $payload = []): array
    {
        $shop = $store instanceof ShopifyStore ? $store->shopifyDomain() : $store;
        $body = is_string($payload) ? $payload : (string) json_encode($payload);

        return [
            'X-Shopify-Topic'                 => $topic,
            'X-Shopify-Shop-Domain'           => $shop,
            'X-Shopify-Webhook-Id'            => (string) \Illuminate\Support\Str::uuid(),
            'X-Shopify-API-Version'           => (string) config('shopifyIntegration.api_version'),
            // Signed over the raw body, exactly as the receiver verifies it —
            // re-encoding the payload anywhere in between changes the digest.
            'X-Shopify-Hmac-Sha256'           => base64_encode(hash_hmac(
                'sha256', $body, (string) config('shopifyIntegration.client_secret'), true
            )),
        ];
    }

    /** The install URL for a store, for a "connect your store" button. */
    public function installUrl(string $shop): string
    {
        $prefix = trim((string) config('shopifyIntegration.routes.prefix', 'shopify'), '/');

        return rtrim((string) config('app.url'), '/')."/{$prefix}/auth/begin?shop={$shop}";
    }

    public function redirectUri(): string
    {
        return $this->oauth->redirectUri();
    }

    /** @return class-string */
    public function model(): string
    {
        return config('shopifyIntegration.store.model', \ShopGPT\ShopifyIntegration\Models\Integration::class);
    }
}
