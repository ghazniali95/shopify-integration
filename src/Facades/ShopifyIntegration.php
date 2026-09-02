<?php

namespace ShopGPT\ShopifyIntegration\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ShopGPT\ShopifyIntegration\Contracts\ShopifyStore|null currentStore()
 * @method static void setCurrentStore(?\ShopGPT\ShopifyIntegration\Contracts\ShopifyStore $store)
 * @method static mixed asStore(\ShopGPT\ShopifyIntegration\Contracts\ShopifyStore $store, callable $callback)
 * @method static \ShopGPT\ShopifyIntegration\Contracts\ShopifyStore|null forDomain(string $domain)
 * @method static \ShopGPT\ShopifyIntegration\Api\ApiClient api(\ShopGPT\ShopifyIntegration\Contracts\ShopifyStore $store)
 * @method static \ShopGPT\ShopifyIntegration\Contracts\ShopifyStore ensureFreshToken(\ShopGPT\ShopifyIntegration\Contracts\ShopifyStore $store)
 * @method static \ShopGPT\ShopifyIntegration\Contracts\ShopifyStore refreshToken(\ShopGPT\ShopifyIntegration\Contracts\ShopifyStore $store)
 * @method static array|null verifySessionToken(?string $jwt)
 * @method static string|null storeFromClaims(?array $claims)
 * @method static \ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository stores()
 * @method static \ShopGPT\ShopifyIntegration\Contracts\ShopifyStore|null exchangeToken(string $shop, string $sessionToken)
 * @method static array sessionTokenHeaders(\ShopGPT\ShopifyIntegration\Contracts\ShopifyStore|string $store, array $claims = [])
 * @method static array webhookHeaders(string $topic, \ShopGPT\ShopifyIntegration\Contracts\ShopifyStore|string $store, array|string $payload = [])
 * @method static string installUrl(string $shop)
 * @method static string redirectUri()
 *
 * @see \ShopGPT\ShopifyIntegration\ShopifyIntegrationManager
 */
class ShopifyIntegration extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ShopGPT\ShopifyIntegration\ShopifyIntegrationManager::class;
    }
}
