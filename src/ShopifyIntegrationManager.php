<?php

namespace ShopGPT\ShopifyIntegration;

use ShopGPT\ShopifyIntegration\Models\Integration;
use ShopGPT\ShopifyIntegration\Services\OAuthService;
use ShopGPT\ShopifyIntegration\Services\TokenService;

class ShopifyIntegrationManager
{
    private ?Integration $currentStore = null;

    public function __construct(
        private readonly OAuthService $oauth,
        private readonly TokenService $tokens,
    ) {
    }

    /** The store the current request is acting as, if any. */
    public function currentStore(): ?Integration
    {
        return $this->currentStore;
    }

    public function setCurrentStore(?Integration $store): void
    {
        $this->currentStore = $store;
    }

    /**
     * Run a callback as a specific store, restoring the previous one after.
     *
     * The guard rail against a queued job or a loop over stores leaking one
     * merchant's data into another's account.
     */
    public function asStore(Integration $store, callable $callback): mixed
    {
        $previous = $this->currentStore;
        $this->currentStore = $store;

        try {
            return $callback($store);
        } finally {
            $this->currentStore = $previous;
        }
    }

    public function forDomain(string $domain): ?Integration
    {
        return $this->model()::forDomain($domain);
    }

    public function ensureFreshToken(Integration $store): Integration
    {
        return $this->tokens->ensureFresh($store);
    }

    public function refreshToken(Integration $store): Integration
    {
        return $this->tokens->refresh($store);
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

    /** @return class-string<Integration> */
    public function model(): string
    {
        return config('shopifyIntegration.model', Integration::class);
    }
}
