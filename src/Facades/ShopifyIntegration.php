<?php

namespace ShopGPT\ShopifyIntegration\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ShopGPT\ShopifyIntegration\Models\Integration|null currentStore()
 * @method static void setCurrentStore(?\ShopGPT\ShopifyIntegration\Models\Integration $store)
 * @method static mixed asStore(\ShopGPT\ShopifyIntegration\Models\Integration $store, callable $callback)
 * @method static \ShopGPT\ShopifyIntegration\Models\Integration|null forDomain(string $domain)
 * @method static \ShopGPT\ShopifyIntegration\Models\Integration ensureFreshToken(\ShopGPT\ShopifyIntegration\Models\Integration $store)
 * @method static \ShopGPT\ShopifyIntegration\Models\Integration refreshToken(\ShopGPT\ShopifyIntegration\Models\Integration $store)
 * @method static array|null verifySessionToken(?string $jwt)
 * @method static string|null storeFromClaims(?array $claims)
 * @method static \ShopGPT\ShopifyIntegration\Models\Integration|null exchangeToken(string $shop, string $sessionToken)
 * @method static array sessionTokenHeaders(\ShopGPT\ShopifyIntegration\Models\Integration|string $store, array $claims = [])
 * @method static array webhookHeaders(string $topic, \ShopGPT\ShopifyIntegration\Models\Integration|string $store, array|string $payload = [])
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
