<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App credentials
    |--------------------------------------------------------------------------
    |
    | From your Shopify Partner dashboard. The env keys stay bare SHOPIFY_*
    | because they are Shopify's credentials, not this package's settings.
    |
    */

    'client_id'     => env('SHOPIFY_CLIENT_ID'),
    'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
    'api_version'   => env('SHOPIFY_API_VERSION', '2025-07'),
    'scopes'        => env('SHOPIFY_SCOPES', 'write_products'),

    /*
    |--------------------------------------------------------------------------
    | Debug
    |--------------------------------------------------------------------------
    |
    | Development convenience: skips HMAC verification on the OAuth routes so
    | you can open /shopify/auth/begin?shop=... by hand.
    |
    | NEVER TRUE IN PRODUCTION. HMAC is what proves an install request came
    | from Shopify; with this on, anyone who knows a store domain can install
    | any store against your app and be handed a working session. Every skip
    | is logged as a warning so it is obvious if this is ever left on.
    |
    */

    'debug' => env('SHOPIFY_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Embedded
    |--------------------------------------------------------------------------
    */

    'embedded' => [
        /*
         * Runs inside the Shopify Admin iframe.
         *
         * With this on, a completed install hands the browser back to Shopify
         * rather than to a path of your own — Shopify then loads the App URL
         * from your Partner dashboard inside the admin, which is what puts
         * the app in the frame. That App URL is the only place the entry
         * point is configured.
         */
        'enabled' => env('SHOPIFY_EMBEDDED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model for a connected store. Point this at a subclass to
    | add your own relations.
    |
    */

    'model' => ShopGPT\ShopifyIntegration\Models\Integration::class,

    /*
    |--------------------------------------------------------------------------
    | OAuth
    |--------------------------------------------------------------------------
    */

    'oauth' => [
        /*
         * Where the state nonce lives between begin and callback.
         *
         * 'cache' (default) keys the nonce by store domain and survives the
         * third-party-cookie restrictions that break sessions inside the
         * Shopify Admin iframe. Use 'session' only for a standalone app you
         * are certain will never be embedded.
         */
        'state_store' => 'cache',
        'state_ttl'   => 300,

        /*
         * How long a signed Shopify request stays acceptable, in seconds.
         *
         * The HMAC itself never expires, so without a window a signed install
         * URL pulled out of a log or a browser history keeps working forever.
         * Set to 0 to check the signature only.
         */
        'hmac_ttl' => 300,

        /*
         * Where to send someone who hits the install route with no shop
         * parameter — usually a human who found the URL. Your App Store
         * listing is the useful destination; null returns a 400.
         */
        'listing_url' => env('SHOPIFY_LISTING_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tokens
    |--------------------------------------------------------------------------
    */

    'tokens' => [
        // Refresh this many seconds before the token actually expires.
        'refresh_buffer' => 300,

        /*
         * Encrypt tokens at rest with the app key. Reads always fall back to
         * the raw value, so a table holding plaintext tokens keeps working
         * and is re-encrypted the next time the token is written.
         */
        'encrypt' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled'            => true,
        'prefix'             => 'shopify',
        'middleware'         => ['web'],
        'webhook_middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */

    'webhooks' => [
        /*
         * Topic => a class extending WebhookJob.
         *
         * The three GDPR topics are mandatory for public apps. The shipped
         * handlers verify, log and acknowledge, which is enough to pass
         * review; override them once you store customer data of your own.
         *
         * app/scopes_update is what keeps the scopes column honest under
         * Shopify managed installation, where the merchant approves a scope
         * change inside the admin and the app is never called.
         */
        'topics' => [
            'app/uninstalled'        => ShopGPT\ShopifyIntegration\Jobs\HandleAppUninstalled::class,
            'app/scopes_update'      => ShopGPT\ShopifyIntegration\Jobs\HandleScopesUpdate::class,
            'shop/update'            => ShopGPT\ShopifyIntegration\Jobs\HandleShopUpdate::class,
            'shop/redact'            => ShopGPT\ShopifyIntegration\Jobs\HandleShopRedact::class,
            'customers/redact'       => ShopGPT\ShopifyIntegration\Jobs\HandleCustomersRedact::class,
            'customers/data_request' => ShopGPT\ShopifyIntegration\Jobs\HandleCustomersDataRequest::class,

            // Any other topic you subscribe to, mapped to your own job:
            // 'your/topic' => App\Jobs\YourHandler::class,
        ],

        'queue'       => env('SHOPIFY_WEBHOOK_QUEUE', 'default'),
        'log_channel' => env('SHOPIFY_WEBHOOK_LOG'),

        /*
         * Drop a redelivery carrying an X-Shopify-Webhook-Id already seen.
         * Shopify redelivers anything it did not hear a 200 for, so a slow
         * response would otherwise duplicate the work.
         */
        'deduplicate' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirects
    |--------------------------------------------------------------------------
    |
    | A route name, a URL, or a closure receiving the InstallContext.
    | Embedded apps never leave the admin frame and ignore these.
    |
    */

    'redirects' => [
        'after_install'   => '/',
        'after_reinstall' => '/',
        'on_failure'      => '/',
    ],

];
