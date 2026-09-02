<?php

namespace ShopGPT\ShopifyIntegration;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ShopGPT\ShopifyIntegration\Http\Middleware\EnsureEmbedded;
use ShopGPT\ShopifyIntegration\Http\Middleware\EnsureStoreInstalled;
use ShopGPT\ShopifyIntegration\Http\Middleware\VerifySessionToken;
use ShopGPT\ShopifyIntegration\Http\Middleware\VerifyShopifyHmac;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;
use ShopGPT\ShopifyIntegration\Repositories\EloquentStoreRepository;
use ShopGPT\ShopifyIntegration\Services\OAuthService;
use ShopGPT\ShopifyIntegration\Services\SessionTokenService;
use ShopGPT\ShopifyIntegration\Services\StoreWriter;
use ShopGPT\ShopifyIntegration\Services\TokenExchangeService;
use ShopGPT\ShopifyIntegration\Services\TokenService;

class ShopifyIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/shopifyIntegration.php', 'shopifyIntegration');

        $this->app->singleton(ShopifyStoreRepository::class, function ($app) {
            $repository = config('shopifyIntegration.store.repository', EloquentStoreRepository::class);

            return $app->make($repository);
        });

        $this->app->singleton(OAuthService::class);
        $this->app->singleton(TokenService::class);
        $this->app->singleton(StoreWriter::class);
        $this->app->singleton(SessionTokenService::class);
        $this->app->singleton(TokenExchangeService::class);
        $this->app->singleton(ShopifyIntegrationManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/shopifyIntegration.php' => config_path('shopifyIntegration.php'),
        ], 'shopifyIntegration-config');

        $this->publishMigration();

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('shopifyIntegration.hmac', VerifyShopifyHmac::class);
        $router->aliasMiddleware('shopifyIntegration.installed', EnsureStoreInstalled::class);
        $router->aliasMiddleware('shopifyIntegration.embedded', EnsureEmbedded::class);
        $router->aliasMiddleware('shopifyIntegration.session', VerifySessionToken::class);

        $this->registerRoutes();
    }

    /**
     * Offer the starter table to an app that has none.
     *
     * Deliberately published rather than loaded: the package owns no table, and
     * an app that already has one of its own would otherwise get a migration it
     * never asked for, colliding with the table it already runs.
     */
    private function publishMigration(): void
    {
        $stub = __DIR__.'/../database/migrations/create_shopify_integrations_table.php.stub';

        $this->publishes([
            $stub => $this->migrationPath('create_shopify_integrations_table'),
        ], 'shopifyIntegration-migrations');
    }

    /**
     * A timestamped name, unless the app already published this migration —
     * publishing twice must overwrite the existing file rather than leave two
     * copies of the same CREATE TABLE behind.
     */
    private function migrationPath(string $name): string
    {
        foreach ((array) glob(database_path('migrations/*_'.$name.'.php')) as $existing) {
            return $existing;
        }

        return database_path('migrations/'.date('Y_m_d_His').'_'.$name.'.php');
    }

    private function registerRoutes(): void
    {
        $prefix = config('shopifyIntegration.routes.prefix', 'shopify');

        Route::group([
            'prefix'     => $prefix,
            'middleware' => config('shopifyIntegration.routes.middleware', ['web']),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/shopifyIntegration.php');
        });

        // Webhooks are stateless and must not run the web group: session and
        // CSRF middleware would reject a signed POST from Shopify.
        Route::group([
            'prefix'     => $prefix,
            'middleware' => config('shopifyIntegration.routes.webhook_middleware', ['api']),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/shopifyIntegrationWebhooks.php');
        });
    }
}
