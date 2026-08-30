<?php

namespace ShopGPT\ShopifyIntegration;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ShopGPT\ShopifyIntegration\Http\Middleware\VerifyShopifyHmac;
use ShopGPT\ShopifyIntegration\Services\OAuthService;
use ShopGPT\ShopifyIntegration\Services\StoreWriter;
use ShopGPT\ShopifyIntegration\Services\TokenService;

class ShopifyIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/shopifyIntegration.php', 'shopifyIntegration');

        $this->app->singleton(OAuthService::class);
        $this->app->singleton(TokenService::class);
        $this->app->singleton(StoreWriter::class);
        $this->app->singleton(ShopifyIntegrationManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/shopifyIntegration.php' => config_path('shopifyIntegration.php'),
        ], 'shopifyIntegration-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'shopifyIntegration-migrations');

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('shopifyIntegration.hmac', VerifyShopifyHmac::class);

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        if (! config('shopifyIntegration.routes.enabled', true)) {
            return;
        }

        Route::group([
            'prefix'     => config('shopifyIntegration.routes.prefix', 'shopify'),
            'middleware' => config('shopifyIntegration.routes.middleware', ['web']),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/shopifyIntegration.php');
        });
    }
}
