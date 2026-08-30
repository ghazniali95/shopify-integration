<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ShopGPT\ShopifyIntegration\ShopifyIntegrationServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ShopifyIntegrationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'https://test-app.com');
        $app['config']->set('shopifyIntegration.client_id', 'test-client-id');
        $app['config']->set('shopifyIntegration.client_secret', 'test-secret');
        $app['config']->set('shopifyIntegration.scopes', 'write_products');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /** Sign a query string the way Shopify does. */
    protected function signed(string $path, array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }

        $params['hmac'] = hash_hmac('sha256', implode('&', $pairs), 'test-secret');

        return $path.'?'.http_build_query($params);
    }
}
