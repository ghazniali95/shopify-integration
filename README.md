# Shopify Integration for Laravel

Multi-tenant Shopify authentication for Laravel apps — embedded and standalone.

One Shopify app can be installed by thousands of merchants. Each install is a
tenant: its own store domain, its own access token, its own expiry, its own
webhooks. This package owns that whole lifecycle — OAuth, session tokens, token
exchange, expiring offline tokens, automatic refresh, HMAC verification,
webhook routing, and a rate-limit-aware API client scoped to a single store —
so your app never writes it again.

It is extracted from the production Shopify flows in `prompt-form` (embedded,
App Bridge, token exchange), `shopGPT-app` and `atelier` (standalone OAuth),
which had each grown their own copy of the same 600 lines.

**Design rule:** the package authenticates *Shopify*. It never touches your
users, your guards or your sessions. It stores the store and its tokens, fires
an event, and gets out of the way — so the same package serves an app with
Cashier billing and user accounts, and an app with no user table at all.

---

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Which mode do you need?](#which-mode-do-you-need)
- [Quick start — embedded app](#quick-start--embedded-app)
- [Quick start — standalone app](#quick-start--standalone-app)
- [The integrations table](#the-integrations-table)
- [Configuration](#configuration)
- [Core concepts](#core-concepts)
- [Hooking into the flow](#hooking-into-the-flow)
- [Embedded authentication](#embedded-authentication)
- [The OAuth install flow](#the-oauth-install-flow)
- [Making API calls](#making-api-calls)
- [Webhooks](#webhooks)
- [Middleware](#middleware)
- [Working with the current store](#working-with-the-current-store)
- [Queued jobs](#queued-jobs)
- [Artisan commands](#artisan-commands)
- [Testing](#testing)
- [Adopting it in an existing app](#adopting-it-in-an-existing-app)
- [Security notes](#security-notes)
- [Roadmap](#roadmap)

---

## Requirements

| Package | PHP | Laravel |
| --- | --- | --- |
| `1.x` | `^8.1` | `10.x`, `11.x`, `12.x` |

Tested against Laravel 10, 11 and 12 on PHP 8.1 – 8.4. Laravel 10 is fully
supported: the package ships no code that depends on 11/12-only APIs.

Requires a queue worker for webhook handling and post-install jobs, and a cache
store for OAuth state. Any driver works; Redis is recommended.

---

## Installation

```bash
composer require shopgpt/shopify-integration
```

> **On the package name.** The composer name must be lowercase — Composer
> enforces this and there is no way around it. Requiring
> `shopGPT/shopify-integration` fails outright and reverts your `composer.json`:
>
> ```
> require.shopGPT/shopify-integration is invalid, it should not contain upper
> case characters. Please use shopgpt/shopify-integration instead.
> ```
>
> The `name` field is validated against
> `^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$` as a schema
> error, not a warning. So the composer name is lowercase while everything a
> developer actually reads keeps its casing:
>
> | Where | Value |
> | --- | --- |
> | GitHub repo | `github.com/shopGPT/shopify-integration` |
> | PHP namespace | `ShopGPT\ShopifyIntegration\…` |
> | Composer name | `shopgpt/shopify-integration` |

The service provider is auto-discovered. Publish the config and the migration:

```bash
php artisan vendor:publish --tag=shopifyIntegration-config
php artisan vendor:publish --tag=shopifyIntegration-migrations
php artisan migrate
```

The config lands at **`config/shopifyIntegration.php`**, read as
`config('shopifyIntegration.client_id')`. It deliberately does not claim
`config/shopify.php` — that name is generic enough that another package, or
your own app, may already want it.

The migration is additive: it creates `integrations` if you do not have one,
and adds only the missing columns if you do. See
[The integrations table](#the-integrations-table).

### Naming

Nothing the package registers is called plain `shopify` — that name is generic
enough that another package, or your own app, may already want it. Everything
the package owns is namespaced `shopifyIntegration`:

| | |
| --- | --- |
| Config | `config('shopifyIntegration.client_id')` |
| Facade | `ShopifyIntegration::currentStore()` |
| Middleware | `shopifyIntegration.embedded`, `shopifyIntegration.session` |
| Route names | `shopifyIntegration.auth.begin` |
| Commands | `php artisan shopifyIntegration:stores` |
| Publish tags | `--tag=shopifyIntegration-config` |

Two things stay bare `shopify`, deliberately:

- **The URL paths** — `/shopify/auth/begin`, `/shopify/webhooks`. These are
  read by merchants and pasted into the Partner dashboard, and camelCase in a
  URL is a mistake you cannot take back once stores are installed against it.
  Change them with `routes.prefix` if you disagree.
- **The env keys** — `SHOPIFY_CLIENT_ID`, `SHOPIFY_CLIENT_SECRET`. These are
  Shopify's credentials, not the package's settings, and every Shopify app you
  have already names them this way.

Add your credentials to `.env`:

```dotenv
SHOPIFY_CLIENT_ID=your_api_key
SHOPIFY_CLIENT_SECRET=your_api_secret
SHOPIFY_API_VERSION=2025-07
SHOPIFY_SCOPES="write_products,write_files,write_content"
SHOPIFY_EMBEDDED=true
```

Register these three URLs with Shopify — in the Partner dashboard, or in
`shopify.app.toml`. All three follow `config('shopifyIntegration.routes.prefix')`,
so changing the prefix moves all of them together:

| | URL |
| --- | --- |
| **App URL** | `https://your-app.com/shopify/auth/begin` |
| **Allowed redirection URL** | `https://your-app.com/shopify/auth/callback` |
| **Webhook endpoint** | `https://your-app.com/shopify/webhooks` |

---

## Which mode do you need?

The package supports both, and the difference matters more than it looks.

| | **Embedded** | **Standalone** |
| --- | --- | --- |
| Where it runs | Inside an iframe in Shopify Admin | Your own domain, own browser tab |
| Authenticates each request with | An App Bridge session token (JWT) | Your app's own session |
| Gets its access token via | Token exchange, silently | The OAuth redirect round trip |
| Cookies | Unreliable — third-party context | Normal |
| Example | `prompt-form` | `shopGPT-app`, `atelier` |

**New public apps should be embedded.** Shopify requires it for App Store
listing, and merchants never leave the admin. Embedded apps still need the
OAuth routes as a fallback for reinstalls and scope changes — the package
registers both either way.

> **Cookies are the trap.** An embedded app runs in a third-party iframe, where
> Safari and Chrome's privacy modes drop your session cookie without warning.
> Anything the package needs to persist between two requests — OAuth state
> above all — goes in the **cache keyed by store domain**, never the session.
> This is the single most common reason a home-grown embedded app works in
> development and fails for real merchants.

---

## Quick start — embedded app

**1. Configure.** Set `SHOPIFY_EMBEDDED=true` and run the migration.

**2. Load App Bridge in your host view.** It must be the first script on the
page, before your bundle. The unversioned URL is deliberate — Shopify serves
the current App Bridge, and pinning it is unsupported.

```blade
<meta name="shopify-api-key" content="{{ config('shopifyIntegration.client_id') }}">
<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"
        data-api-key="{{ config('shopifyIntegration.client_id') }}"></script>

@vite(['resources/js/app.jsx'])
```

**3. Protect your routes.**

```php
// routes/web.php — pages loaded inside the Shopify Admin iframe
Route::middleware('shopifyIntegration.embedded')->group(function () {
    Route::get('/shopify/app', fn () => view('shopify'))->name('shopify.home');
});

// routes/api.php — XHR from inside the iframe, bearing a session token
Route::middleware('shopifyIntegration.session')->group(function () {
    Route::get('/api/products', [ProductController::class, 'index']);
});
```

**4. Send the session token from the front end.**

```js
const token = await shopify.idToken();

await fetch('/api/products', {
    headers: { Authorization: `Bearer ${token}` },
});
```

That is the whole install. There is no redirect, no consent screen on a managed
install, and no moment where the merchant leaves the admin. The first time a
verified session token arrives for a store the package has no token for, it
performs a **token exchange** in the background and stores the result.

**5. Call the API as that store.**

```php
use ShopGPT\ShopifyIntegration\Facades\ShopifyIntegration;

$products = ShopifyIntegration::currentStore()->api()->graphql(<<<'GQL'
    query {
        products(first: 10) {
            edges { node { id title handle } }
        }
    }
GQL)->json('data.products.edges');
```

---

## Quick start — standalone app

**1. Migrate.**

**2. Point a merchant at the install route.**

```
https://your-app.com/shopify/auth/begin?shop=acme.myshopify.com
```

The package verifies the request, runs OAuth, stores an encrypted expiring
token, registers webhooks, fires `StoreInstalled`, and redirects to
`config('shopifyIntegration.redirects.after_install')`.

**3. Do your own thing on install.**

The package will not create or log in a user — that is your app's decision.
Listen for the event:

```php
// app/Providers/EventServiceProvider.php
use ShopGPT\ShopifyIntegration\Events\StoreInstalled;

protected $listen = [
    StoreInstalled::class => [
        CreateUserForStore::class,
    ],
];
```

**4. Call the API as that store.**

```php
use ShopGPT\ShopifyIntegration\Models\Integration;

$store = Integration::forDomain('acme.myshopify.com');

$store->api()->graphql($query);
```

The token is refreshed automatically if it is close to expiry. You never touch
the access token.

---

## The integrations table

One table holds every OAuth connection your app has — Shopify today, Ecwid or
BigCommerce later. Every column the package owns carries an `integration_`
prefix, so it can sit alongside whatever else your app keeps there without
collisions.

**The column names are fixed.** There is no mapping layer and no configuration
for them: the package reads and writes exactly these names in every app that
installs it. That is what makes a store row mean the same thing everywhere, and
what lets a job or a listener move between your apps unchanged.

### Columns

**Laravel conventions — deliberately unprefixed**

| Column | Type | Null | Written by |
| --- | --- | --- | --- |
| `id` | `id` | no | the package |
| `user_id` | `foreignId` | yes | **you** |
| `created_at` | `timestamp` | yes | the package |
| `updated_at` | `timestamp` | yes | the package |

Prefixing these four would fight Eloquent: you would need `$primaryKey`,
`CREATED_AT`/`UPDATED_AT` constants and an explicit foreign key on every
relation, in every app, forever.

`user_id` is created for you but **never written by the package** — it does not
know or care what a user is. Your `StoreInstalled` listener fills it in. An app
with no user table can ignore the column entirely.

**Identity**

| Column | Type | Null | Holds |
| --- | --- | --- | --- |
| `integration_platform` | `string` | no | `shopify`. Every package query is scoped to this value |
| `integration_external_id` | `string` | yes | Shopify's numeric shop id |
| `integration_store_domain` | `string` | yes | `shopgpt.myshopify.com` — the API and webhook identity |
| `integration_domain` | `string` | yes | `shopgptpro.com` — the merchant's public storefront |

Two domains, because they are two different things. `integration_store_domain`
is what every API URL is built from, what webhooks send in
`X-Shopify-Shop-Domain`, and what a session token carries in `dest` — it is the
identity. `integration_domain` is the customer-facing address, which the
merchant can change at any time and which nothing in the auth flow depends on.

**OAuth**

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `integration_access_token` | `text` | yes | Encrypted at rest |
| `integration_refresh_token` | `text` | yes | Encrypted at rest |
| `integration_token_expires_at` | `timestamp` | yes | `null` = legacy permanent token, never refreshed |
| `integration_scopes` | `string` | yes | Scopes Shopify actually granted |

Both token columns are **`text`, not `string`**. Encryption inflates a ~40-char
Shopify token well past 255 characters, and `varchar(255)` truncates it
silently — no error on write, an unusable token on read.

**Lifecycle**

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `integration_installed_at` | `timestamp` | yes | First successful authorisation |
| `integration_uninstalled_at` | `timestamp` | yes | **`null` means currently installed** |

One column as the source of truth, rather than a boolean plus a timestamp that
can disagree.

**Store profile**

Promoted out of the raw payload because they are read constantly or drive real
logic. All `string` and nullable — no length constraints, because the
equivalent fields on other platforms do not guarantee ISO-length values.

| Column | From `shop.json` | Why it earns a column |
| --- | --- | --- |
| `integration_name` | `name` | Every admin list, support ticket, Slack ping |
| `integration_email` | `email` | Merchant contact |
| `integration_shop_owner` | `shop_owner` | The human's name |
| `integration_phone` | `phone` | Stored by both existing apps |
| `integration_currency` | `currency` | Billing amounts and plan pricing |
| `integration_country_code` | `country_code` | Tax, regional logic, reporting |
| `integration_country_name` | `country_name` | Display, without a lookup table |
| `integration_primary_locale` | `primary_locale` | Decides the language generated content is written in |
| `integration_plan_name` | `plan_name` | Decides test vs live billing charges |
| `integration_weight_unit` | `weight_unit` | Product sync |
| `integration_password_enabled` | `password_enabled` | `boolean` — whether the storefront is password-protected |

`integration_plan_name` is the one people forget. Values like `partner_test`,
`affiliate` and `plus_partner_sandbox` mean a development store, where Shopify
**rejects** a live recurring charge — you must send `test: true`. shopGPT-app
does this today with a global `SHOPIFY_TEST_CHARGE` env var; a per-store column
makes it automatic.

**Data**

| Column | Type | Null | Owner |
| --- | --- | --- | --- |
| `integration_shop_data` | `json` | yes | **The package** — the full raw `shop.json` payload |
| `integration_shop_data_synced_at` | `timestamp` | yes | The package — when that snapshot was taken |
| `integration_metadata` | `json` | yes | **You** — the package never writes here |

Two JSON columns, because they have two different owners. `shop.json` returns
around 60 fields; the eleven above are promoted and the rest — money formats,
tax settings, capability flags, `primary_location_id`,
`enabled_presentment_currencies`, address — stay in `integration_shop_data`,
free to keep and there when you need one.

### Indexes

```
unique  (integration_platform, integration_external_id)
unique  (integration_platform, integration_store_domain)
index   (user_id, integration_platform)
index   (integration_platform, integration_uninstalled_at)
index   (integration_token_expires_at)
```

Both uniques are composite, so the same store can exist under two platforms
without collision. `integration_token_expires_at` is indexed so the refresh
sweeper stays a range scan once you have thousands of stores.

### The migration adds only what is missing

```php
public function up(): void
{
    if (! Schema::hasTable('integrations')) {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    Schema::table('integrations', function (Blueprint $table) {
        $columns = [
            'integration_platform'            => fn () => $table->string('integration_platform')->default('shopify'),
            'integration_external_id'         => fn () => $table->string('integration_external_id')->nullable(),
            'integration_store_domain'        => fn () => $table->string('integration_store_domain')->nullable(),
            'integration_domain'              => fn () => $table->string('integration_domain')->nullable(),

            'integration_access_token'        => fn () => $table->text('integration_access_token')->nullable(),
            'integration_refresh_token'       => fn () => $table->text('integration_refresh_token')->nullable(),
            'integration_token_expires_at'    => fn () => $table->timestamp('integration_token_expires_at')->nullable(),
            'integration_scopes'              => fn () => $table->string('integration_scopes')->nullable(),

            'integration_installed_at'        => fn () => $table->timestamp('integration_installed_at')->nullable(),
            'integration_uninstalled_at'      => fn () => $table->timestamp('integration_uninstalled_at')->nullable(),

            'integration_name'                => fn () => $table->string('integration_name')->nullable(),
            'integration_email'               => fn () => $table->string('integration_email')->nullable(),
            'integration_shop_owner'          => fn () => $table->string('integration_shop_owner')->nullable(),
            'integration_phone'               => fn () => $table->string('integration_phone')->nullable(),
            'integration_currency'            => fn () => $table->string('integration_currency')->nullable(),
            'integration_country_code'        => fn () => $table->string('integration_country_code')->nullable(),
            'integration_country_name'        => fn () => $table->string('integration_country_name')->nullable(),
            'integration_primary_locale'      => fn () => $table->string('integration_primary_locale')->nullable(),
            'integration_plan_name'           => fn () => $table->string('integration_plan_name')->nullable(),
            'integration_weight_unit'         => fn () => $table->string('integration_weight_unit')->nullable(),
            'integration_password_enabled'    => fn () => $table->boolean('integration_password_enabled')->nullable(),

            'integration_shop_data'           => fn () => $table->json('integration_shop_data')->nullable(),
            'integration_shop_data_synced_at' => fn () => $table->timestamp('integration_shop_data_synced_at')->nullable(),
            'integration_metadata'            => fn () => $table->json('integration_metadata')->nullable(),
        ];

        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('integrations', $name)) {
                $add();
            }
        }
    });

    // Indexes are added separately: a duplicate index name is a hard error,
    // and there is no Schema::hasIndex() before Laravel 11.
    $this->addIndexIfMissing('integrations', ['integration_platform', 'integration_external_id'], unique: true);
    $this->addIndexIfMissing('integrations', ['integration_platform', 'integration_store_domain'], unique: true);
    $this->addIndexIfMissing('integrations', ['user_id', 'integration_platform']);
    $this->addIndexIfMissing('integrations', ['integration_platform', 'integration_uninstalled_at']);
    $this->addIndexIfMissing('integrations', ['integration_token_expires_at']);
}
```

`integration_store_domain` is created nullable even though it is conceptually
required: adding a `NOT NULL` column to a table that already has rows fails.
The unique index and package-level validation enforce presence instead.

### The model

Every query is scoped to `integration_platform = 'shopify'` automatically — the
package has no reason to see any other platform's rows.

```php
use ShopGPT\ShopifyIntegration\Models\Integration;

$store = Integration::forDomain('acme.myshopify.com');
$store = Integration::forExternalId('12345678');

$store->store_domain;     // acme.myshopify.com
$store->plan_name;        // 'basic'
$store->shop_data;        // the full shop.json array
$store->isInstalled();    // bool
$store->hasValidToken();  // bool
$store->api();            // API client bound to this store
```

**You address attributes without the prefix.** `$store->access_token` reads
`integration_access_token`. The columns are prefixed so they can share a table;
your code should not have to carry that.

To add your own relations, extend the model and point the config at your
subclass:

```php
class Integration extends \ShopGPT\ShopifyIntegration\Models\Integration
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

---

## Configuration

`config/shopifyIntegration.php` in full — it is deliberately short.

```php
return [

    /*
     * App credentials from your Shopify Partner dashboard.
     */
    'client_id'     => env('SHOPIFY_CLIENT_ID'),
    'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
    'api_version'   => env('SHOPIFY_API_VERSION', '2025-07'),
    'scopes'        => env('SHOPIFY_SCOPES', 'write_products'),

    'embedded' => [
        /*
         * Runs inside the Shopify Admin iframe, authenticating with App Bridge
         * session tokens. Enables the token-exchange path.
         */
        'enabled' => env('SHOPIFY_EMBEDDED', false),

        /*
         * Where an authenticated merchant lands inside the admin frame.
         */
        'entry' => '/shopify/app',
    ],

    /*
     * The Eloquent model for a connected store. Point this at a subclass to
     * add your own relations.
     */
    'model' => ShopGPT\ShopifyIntegration\Models\Integration::class,

    'oauth' => [
        /*
         * Where the OAuth state nonce lives between begin and callback.
         *
         * 'cache' (default) keys the nonce by store domain and survives the
         * third-party-cookie restrictions that break sessions inside the
         * Shopify Admin iframe. Use 'session' only for a standalone app you
         * are certain will never be embedded.
         */
        'state_store' => 'cache',
        'state_ttl'   => 300,
    ],

    'tokens' => [
        /*
         * Refresh this many seconds before the token actually expires, so an
         * in-flight request never races the expiry.
         */
        'refresh_buffer' => 300,

        /*
         * Encrypt tokens at rest using the app key. Leave true.
         */
        'encrypt' => true,
    ],

    'routes' => [
        'enabled'            => true,
        'prefix'             => 'shopify',
        'middleware'         => ['web'],
        'webhook_middleware' => ['api'],
    ],

    /*
     * Where a standalone app lands after the OAuth redirect. A route name, a
     * URL, or a closure receiving the InstallContext. Embedded apps never
     * leave the admin frame and ignore these.
     */
    'redirects' => [
        'after_install'   => '/',
        'after_reinstall' => '/',
        'on_failure'      => '/',
    ],

    'webhooks' => [
        /*
         * Registered with Shopify after every install and reinstall.
         * Topic => queued job. The GDPR topics are mandatory for public apps;
         * the defaults verify, log and return 200 so you pass review.
         */
        'topics' => [
            'app/uninstalled'        => ShopGPT\ShopifyIntegration\Jobs\HandleAppUninstalled::class,
            'shop/redact'            => ShopGPT\ShopifyIntegration\Jobs\HandleShopRedact::class,
            'customers/redact'       => ShopGPT\ShopifyIntegration\Jobs\HandleCustomersRedact::class,
            'customers/data_request' => ShopGPT\ShopifyIntegration\Jobs\HandleCustomersDataRequest::class,

            // 'products/update' => App\Jobs\SyncShopifyProduct::class,
        ],

        'queue'       => env('SHOPIFY_WEBHOOK_QUEUE', 'default'),
        'log_channel' => env('SHOPIFY_WEBHOOK_LOG', null),
    ],
];
```

There is no `auth` section, no `columns` map and no platform `scope`. Those
were three ways of asking your app to describe itself to the package. The
column names are fixed, the platform is always `shopify`, and everything about
users belongs to you — reached through
[events](#hooking-into-the-flow), not configuration.

---

## Core concepts

### The store is the tenant

Every connected store is one row. Everything the package does is scoped to it:
tokens, API calls, webhooks, rate limits. Lookups resolve by
`integration_external_id` first and `integration_store_domain` second, since
the external id is the durable key.

### The package never touches your users

It has no guard, no login call, no `User` model and no opinion about what an
account is. What it does instead:

1. Stores the connected store and its tokens.
2. Fires `StoreInstalled` / `StoreReinstalled` / `StoreUninstalled`.
3. Exposes `ShopifyIntegration::currentStore()` on authenticated requests.

Everything else — creating an account, seeding credits, logging someone in,
choosing which of two accounts a second store attaches to — happens in your
listener, in your app, where the rules actually live. That is the difference
between a package `prompt-form` (synthetic accounts, no humans), `atelier`
(password-setup flow) and `shopGPT-app` (credits, plans, account-choice screen)
can all install unchanged, and one that needs a config option per app.

### Offline tokens only

Shopify issues two kinds of access token. The package uses one.

| | **Offline** — what this package uses | **Online** — not supported |
| --- | --- | --- |
| Tied to | The store | One logged-in staff user |
| Lifetime | ~24h, refreshable indefinitely | ~24h, not refreshable |
| Rows needed | One per store | One per user, per store |
| Works in a queued job | Yes | No — expires with the user's session |

Background work is the whole point of a multi-tenant app: catalog imports,
webhook handlers, scheduled syncs. Those run when nobody is logged in, which
online tokens cannot do.

### Two tokens, doing different jobs

This is the distinction that makes embedded apps confusing:

| | **Session token** | **Access token** |
| --- | --- | --- |
| Proves | *This request came from a merchant in Shopify Admin* | *This app may call the Admin API for this store* |
| Lifetime | ~1 minute | ~24 hours, refreshable |
| Lives in | The `Authorization: Bearer` header | Your database, encrypted |
| Issued by | App Bridge, client side | Shopify's OAuth endpoint |

A session token **never** talks to the Admin API. An access token **never**
authenticates a browser request. The package trades the first for the second by
token exchange, once, then keeps the second fresh.

### The access token lifecycle

```
install / token exchange ──► access_token (~24h) + refresh_token
              │
              ├─ every API call: is expiry < now + refresh_buffer?
              │      no  ──► use it
              │      yes ──► POST grant_type=refresh_token, save, use it
              │
              └─ refresh fails but token still valid ──► carry on, retry next call
                 refresh fails and token expired      ──► throw, let the job retry
```

You never call the refresh yourself. Every path into the Shopify API runs
`ensureFreshToken()` first. Tokens are encrypted at rest and hidden from array
and JSON serialisation.

---

## Hooking into the flow

Events are the extension point. Every one carries the store model.

| Event | Fired when |
| --- | --- |
| `OAuthStarted` | A merchant hits the install route |
| `StoreInstalled` | A store the app has never seen was authorised |
| `StoreReinstalled` | A previously uninstalled store came back |
| `StoreTokenExchanged` | An access token was obtained from a session token |
| `StoreTokenRefreshed` | An access token was rotated |
| `StoreUninstalled` | `app/uninstalled` arrived, or an API call got a 401 |
| `OAuthFailed` | HMAC, state, or token exchange failed |

```php
namespace App\Listeners;

use ShopGPT\ShopifyIntegration\Events\StoreInstalled;

class CreateUserForStore
{
    public function handle(StoreInstalled $event): void
    {
        $store = $event->store;

        $user = User::firstOrCreate(
            ['email' => $store->email],
            [
                'name'       => $store->shop_owner,
                'password'   => Hash::make(Str::random(32)),
                'login_type' => 'shopify',
                'currency'   => $store->currency,
                'credits'    => Plan::getFreePlan()->credits,
            ],
        );

        $store->update(['user_id' => $user->id]);

        // Your app decides whether to log anyone in.
        session()->regenerate();
        Auth::login($user, remember: true);
    }
}
```

The eleven promoted profile columns are already populated on `$event->store`
by the time your listener runs, so you rarely need `shop_data`.

`InstallContext` is available as `$event->context` when you need more:

```php
$event->context->shopData;          // raw shop.json array
$event->context->isNewInstall;
$event->context->isReinstall;
$event->context->viaTokenExchange;  // arrived embedded, not through the redirect
$event->context->scopes;            // scopes Shopify actually granted
```

> **If your listener logs someone in, regenerate the session first.** Calling
> `Auth::login()` on the existing session id is a session-fixation hole. The
> package cannot do this for you, because it does not know whether you want a
> login at all.

> **Never reassign a store to whoever is logged in.** On a reinstall,
> `$event->store->user_id` is already set — keep it. Attaching a returning
> store to the current session's user is how one merchant ends up with another
> merchant's storefront, and it is an easy mistake to make in a listener.

---

## Embedded authentication

Everything here is what `prompt-form` proved in production, moved behind
package middleware.

### The request lifecycle

```
Shopify Admin
     │  loads iframe: /shopify/app?shop=acme.myshopify.com&host=…
     ▼
shopifyIntegration.embedded ─── shop param looks valid ──► render the host view
     │                                             │
     │                                             ▼
     │                              App Bridge boots, mints a session token
     │                                             │
     │                                   fetch('/api/…', Bearer <jwt>)
     │                                             ▼
     └──────────────────────────────────► shopifyIntegration.session
                                                   │
                          verify JWT: signature, exp, nbf, aud, iss, dest
                                                   │
                              ┌────────────────────┴────────────────────┐
                       store has an access token             store has none
                              │                                        │
                              ▼                                        ▼
                 ShopifyIntegration::currentStore() set                token exchange,
                                                            store, continue
```

Note what is *absent*: no redirect, no consent screen, no cookie.

### Session token verification

A partial check here is an authentication bypass, so every claim is validated:

| Check | Why |
| --- | --- |
| HS256 signature against `client_secret` | Proves Shopify minted it |
| `exp` in the future (10s leeway) | Tokens live ~1 minute; leeway absorbs clock skew |
| `nbf` not in the future (10s leeway) | Rejects not-yet-valid tokens |
| `aud` equals your `client_id` | A token minted for a *different app* must not authenticate yours |
| `iss` matches `https://{shop}.myshopify.com/admin` | Rejects a forged issuer |
| `dest` matches `https://{shop}.myshopify.com` | This is the store you resolve from |
| `iss` and `dest` name the **same** store | Otherwise a token for store A could act on store B |

That last row is the one hand-rolled implementations miss. The store is
resolved from `dest`, so a token whose `iss` and `dest` disagree must never be
treated as authenticated.

```php
$claims = ShopifyIntegration::verifySessionToken($jwt);   // array|null
$domain = ShopifyIntegration::storeFromClaims($claims);   // acme.myshopify.com
```

### Token exchange

When a verified session token arrives for a store with no stored access token,
the package exchanges it — silently, server side, no merchant-visible redirect:

```
POST https://{shop}/admin/oauth/access_token

  client_id             = …
  client_secret         = …
  grant_type            = urn:ietf:params:oauth:grant-type:token-exchange
  subject_token         = <the session token>
  subject_token_type    = urn:ietf:params:oauth:token-type:id_token
  requested_token_type  = urn:shopify:params:oauth:token-type:offline-access-token
  expiring              = 1
```

This is why a modern embedded app can skip the OAuth redirect entirely on
install. It is also the recovery path: if a token is revoked, the next request
quietly re-obtains one instead of bouncing the merchant out of the admin.

### The front end

Handle the two failure modes distinctly — an expired token is retryable, a
missing install is not.

```js
import { useAppBridge } from '@shopify/app-bridge-react';

const shopify = useAppBridge();

instance.interceptors.request.use(async (config) => {
    config.headers.Authorization = `Bearer ${await shopify.idToken()}`;
    return config;
});

instance.interceptors.response.use(null, async (error) => {
    const status = error.response?.status;

    // Stale token — mint a fresh one and retry exactly once
    if ((status === 400 || status === 403) && !error.config._retried) {
        error.config._retried = true;
        error.config.headers.Authorization = `Bearer ${await shopify.idToken()}`;
        return instance.request(error.config);
    }

    // Not installed / token revoked — full-page redirect, out of the iframe
    if (status === 401) {
        const shop = new URLSearchParams(location.search).get('shop');
        window.top.location.href = `/shopify/auth/begin?shop=${shop}`;
    }

    return Promise.reject(error);
});
```

`window.top.location` matters: redirecting `window.location` navigates the
iframe, and Shopify's OAuth screen refuses to render framed.

### shopify.app.toml

```toml
name = "Your App"
client_id = "…"
application_url = "https://your-app.com/shopify/auth/begin"
embedded = true

# Managed install: Shopify handles the grant screen and scope changes.
use_legacy_install_flow = false

[access_scopes]
scopes = "write_products"

[auth]
redirect_urls = [ "https://your-app.com/shopify/auth/callback" ]

[webhooks]
api_version = "2025-07"

  [[webhooks.subscriptions]]
  topics = [ "app/uninstalled" ]
  uri = "https://your-app.com/shopify/webhooks"
```

---

## The OAuth install flow

Used by standalone apps, and by embedded apps as the re-auth fallback.

| Method | URI | Name |
| --- | --- | --- |
| `GET` | `/shopify/auth/begin` | `shopifyIntegration.auth.begin` |
| `GET` | `/shopify/auth/callback` | `shopifyIntegration.auth.callback` |
| `POST` | `/shopify/webhooks` | `shopifyIntegration.webhooks` |

```
 1. Validate the store domain against Shopify's hostname rules
 2. Require and verify the HMAC using client_secret        ← rejects forged installs
 3. Generate a state nonce, store it in the CACHE keyed by store
 4. Redirect to https://{shop}/admin/oauth/authorize

 5. Re-validate the domain and verify the HMAC again
 6. Pull the state from the cache, compare with hash_equals
 7. POST /admin/oauth/access_token with expiring=1
 8. GET  /admin/api/{version}/shop.json  → the store profile
 9. Look up by integration_external_id, then integration_store_domain
10. Save: encrypted tokens, expiry, scopes, the 11 profile columns,
          the raw payload into integration_shop_data, integration_installed_at
11. Register every webhook topic in config (idempotent — safe on reinstall)
12. Fire StoreInstalled or StoreReinstalled   ← your listener runs here
13. Redirect: embedded apps back into the admin frame, standalone per config
```

Steps 2, 5 and 6 are security-critical and are not optional.

### A note on HMAC construction

Shopify's query-string HMAC is computed over the parameters sorted by key and
joined as `key=value` pairs with `&` — **not** `http_build_query()`, which
percent-encodes values and produces a different digest for any parameter
containing a space or slash.

```php
$params = $request->except('hmac');
ksort($params);

$message = collect($params)->map(fn ($v, $k) => "{$k}={$v}")->implode('&');

hash_equals(hash_hmac('sha256', $message, $secret), $request->query('hmac'));
```

---

## Making API calls

```php
$api = $store->api();          // or ShopifyIntegration::for($store)
```

### GraphQL

```php
$response = $api->graphql(<<<'GQL'
    query getProducts($first: Int!) {
        products(first: $first) { edges { node { id title } } }
    }
GQL, ['first' => 50]);

$response->json('data.products.edges');
$response->throw();  // raises on userErrors as well as HTTP errors
```

### REST

```php
$api->rest()->get('products.json', ['limit' => 250]);
$api->rest()->post('products.json', ['product' => [...]]);
```

### Pagination

```php
foreach ($api->paginate('products', ['first' => 250]) as $product) {
    // cursor pagination handled for you, one page in memory at a time
}
```

### Rate limits

GraphQL cost and REST call-limit headers are read after each response. When the
bucket drops below 20% the client waits for it to refill rather than letting
Shopify 429 you. Genuine 429s are retried with backoff.

### When the app has been uninstalled

A 401 means the merchant removed the app. The client throws
`StoreUninstalledException`, stamps `integration_uninstalled_at`, and fires
`StoreUninstalled` — so your cleanup listeners run from one place whether you
learned it from the webhook or an API call.

```php
try {
    $api->graphql($query);
} catch (StoreUninstalledException $e) {
    $e->store;   // already flagged uninstalled
}
```

---

## Webhooks

### Registration

Every topic in config is registered after each install and reinstall, via a
queued idempotent job. Reinstalls matter: Shopify drops all subscriptions on
uninstall, so they must be recreated, and re-registering an existing topic is a
no-op.

Topics declared in `shopify.app.toml` are managed by Shopify instead — declare
them in one place or the other, not both.

```bash
php artisan shopifyIntegration:webhooks:sync
php artisan shopifyIntegration:webhooks:sync --store=acme.myshopify.com
```

### Handling

The middleware verifies the HMAC against the **raw body**, the controller
resolves the store from `X-Shopify-Shop-Domain`, dispatches the configured job,
and returns `200` immediately — Shopify's timeout is 5 seconds, so all real
work belongs on the queue.

```php
namespace App\Jobs;

use ShopGPT\ShopifyIntegration\Jobs\WebhookJob;

class SyncShopifyProduct extends WebhookJob
{
    public function handle(): void
    {
        $this->store;             // resolved for you
        $this->payload;
        $this->topic;             // 'products/update'
        $this->webhookId;         // X-Shopify-Webhook-Id, for deduplication

        $this->store->api()->graphql(/* re-fetch the product */);
    }
}
```

`WebhookJob` carries only the store id, topic and resource id across the queue
— not the raw body. A single `products/update` payload runs to ~110KB, and a
busy store repeats it every few seconds; keeping it off Redis is the difference
between a healthy queue and a full one.

Bodies are never logged. Each webhook logs topic, store domain, resource id and
payload size.

### GDPR topics

`shop/redact`, `customers/redact` and `customers/data_request` are mandatory
for public apps. The shipped handlers verify, log and `200` so you pass review.

**`shop/redact` clears PII from both places it lives** —
`integration_shop_data` *and* the promoted `integration_email`,
`integration_phone` and `integration_shop_owner` columns. Shopify requires this
within 48 hours of uninstall and checks it at review. If you override the
handler, do not forget the columns. Anything you copied onto your own `User`
row is yours to redact.

---

## Middleware

| Alias | Use |
| --- | --- |
| `shopifyIntegration.embedded` | Page loads inside the admin iframe. Accepts a session token or a valid `shop` param; otherwise redirects to `/shopify/auth/begin` |
| `shopifyIntegration.session` | API requests carrying a session token. Verifies the JWT, resolves the store, token-exchanges if needed |
| `shopifyIntegration.hmac` | Verifies query-string HMAC on OAuth entry points |
| `shopifyIntegration.webhook` | Verifies webhook HMAC against the raw body |

```php
Route::middleware('shopifyIntegration.embedded')->group(function () {
    Route::get('/shopify/app', fn () => view('shopify'));
});

Route::middleware('shopifyIntegration.session')->group(function () {
    Route::get('/api/products', [ProductController::class, 'index']);
});
```

These middleware set `ShopifyIntegration::currentStore()`. They do **not** touch
`Auth::user()` — if you want a Laravel user resolved from the store, stack your
own middleware after `shopifyIntegration.session`:

```php
class AuthenticateStoreOwner
{
    public function handle($request, Closure $next)
    {
        if ($user = ShopifyIntegration::currentStore()?->user) {
            Auth::setUser($user);
        }

        return $next($request);
    }
}
```

That is four lines in your app, and it keeps the package free of any guard
configuration.

---

## Working with the current store

```php
ShopifyIntegration::currentStore();     // Integration|null
ShopifyIntegration::currentStore()->api()->graphql($query);
```

To act as a specific store — in a command, a job, an admin tool:

```php
ShopifyIntegration::asStore($store, function () {
    // ShopifyIntegration::currentStore() is $store for the duration of this closure,
    // then restored. Nested calls are safe.
});
```

This is the guard rail that stops a queued job leaking one merchant's data into
another's account.

---

## Queued jobs

```php
class ImportCatalog implements ShouldQueue
{
    public function __construct(public Integration $store) {}

    public function handle(): void
    {
        $this->store->api()->paginate('products');
    }
}
```

---

## Artisan commands

```bash
php artisan shopifyIntegration:stores                      # list stores and token status
php artisan shopifyIntegration:stores --expiring           # tokens expiring within the hour
php artisan shopifyIntegration:token acme.myshopify.com    # force a token refresh
php artisan shopifyIntegration:migrate-tokens              # permanent tokens → expiring
php artisan shopifyIntegration:webhooks:sync               # reconcile webhooks with Shopify
php artisan shopifyIntegration:webhooks:list --store=acme.myshopify.com
php artisan shopifyIntegration:uninstall acme.myshopify.com    # mark uninstalled locally
```

---

## Testing

```php
ShopifyIntegration::fake([
    'products' => ['data' => ['products' => ['edges' => []]]],
]);

ShopifyIntegration::assertGraphQLSent(fn ($query) => str_contains($query, 'products'));
ShopifyIntegration::assertWebhookRegistered('products/update');
```

```php
$store = Integration::factory()->create();
$store = Integration::factory()->uninstalled()->create();
$store = Integration::factory()->withExpiredToken()->create();
$store = Integration::factory()->withLegacyPermanentToken()->create();
```

The package mints valid session tokens and webhook signatures so you never
hand-roll a JWT in a test:

```php
$this->withHeaders(ShopifyIntegration::sessionTokenHeaders($store))
     ->getJson('/api/products')
     ->assertOk();

$this->postJson('/shopify/webhooks', $payload,
        ShopifyIntegration::webhookHeaders('products/update', $store, $payload))
     ->assertOk();
```

---

## Adopting it in an existing app

Because the column names are fixed, an app with an existing `integrations`
table needs a one-time backfill. It is a copy, not a move — your old columns
stay where they are until you choose to drop them, so every step is reversible
and separately deployable.

1. **Publish and migrate.** The additive migration adds the `integration_*`
   columns alongside your existing ones. Nothing reads them yet.

2. **Backfill.** One migration per app, because only you know which of your
   columns held what:

   ```php
   // shopGPT-app: domain = custom, url = myshopify host
   DB::table('integrations')->where('type', 'shopify')->update([
       'integration_platform'         => 'shopify',
       'integration_external_id'      => DB::raw('integration_id'),
       'integration_store_domain'     => DB::raw('url'),
       'integration_domain'           => DB::raw('domain'),
       'integration_access_token'     => DB::raw('token'),
       'integration_refresh_token'    => DB::raw('refresh_token'),
       'integration_token_expires_at' => DB::raw('token_expires_at'),
       'integration_scopes'           => DB::raw('scope'),
       'integration_uninstalled_at'   => DB::raw('CASE WHEN status = 1 THEN NULL ELSE NOW() END'),
   ]);
   ```

   **`atelier` is the trap:** its `domain` column holds the `.myshopify.com`
   host, where shopGPT-app puts the custom domain. Map atelier's `domain` to
   `integration_store_domain`, not `integration_domain`, or every API call
   after cutover targets the wrong host.

   Tokens are encrypted with your app key in both the old and new columns, so a
   raw SQL copy carries the ciphertext across intact.

3. **Swap the API client.** Replace `new ShopifyServiceGraphQL($integration)`
   (or `ApiService::graph()`) with `$store->api()`. Delete the local
   `TokenService` — the package refreshes on every call already.

4. **Move the webhook endpoint.** Point the config topics at your existing jobs
   and drop the hand-rolled `verifyWebhook()` and topic `match`. Keep the old
   route as an alias until Shopify's registrations have rolled over.

5. **Move authentication last.** Replace `VerifyShopify` / `ApiAuth` with
   `shopifyIntegration.embedded` and `shopifyIntegration.session`, and move the user creation from
   your OAuth callback into a `StoreInstalled` listener. Then delete the
   controller and point the Partner dashboard at `/shopify/auth/begin`.

6. **Drop the old columns**, once a release has passed with no reads from them.

Step 5 changes the URL Shopify redirects to. Keep the old callback route
registered and forwarding for one release.

**Per app:** `prompt-form` is closest to the package's shape and should go
first. `shopGPT-app` and `atelier` are standalone and only need steps 1–4 until
they go embedded — and shopGPT-app's account-choice screen stays entirely in
shopGPT-app, as a listener that redirects, since the package has no opinion
about it.

---

## Security notes

- **Verify every session token claim.** Signature, `exp`, `nbf`, `aud`, `iss`,
  `dest`, and that `iss` and `dest` name the same store. Checking the signature
  alone lets a token minted for another app — or naming another store —
  authenticate a request.
- **HMAC on every OAuth entry point**, and a missing `hmac` parameter is a
  rejection, not a skip. Webhooks verify against the raw request body, not the
  parsed array, because re-encoding changes the bytes.
- **OAuth state in the cache, not the session**, keyed by store and compared
  with `hash_equals`. Session state silently breaks inside the admin iframe.
- **Tokens encrypted at rest** and hidden from `toArray()` and `toJson()`, so
  they cannot leak through an API resource.
- **Store domain validation.** Only hostnames matching
  `^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$` are accepted, which closes the
  redirect-to-attacker hole in a naive `shop` parameter.

Two the package cannot enforce, because they happen in your listener:

- **Regenerate the session before `Auth::login()`**, or you have a
  session-fixation hole.
- **Never reassign a returning store to the currently logged-in user.** Keep
  the `user_id` that is already on the row.

---

## Roadmap

Deliberately out of scope for `1.0`, in rough order:

- **Managed billing** — recurring subscriptions, `app_subscriptions/update`
  handling. All three apps have working implementations to donate; they differ
  enough to need their own design pass.
- **Bulk operations** — the bulk query/mutation lifecycle with polling.
- **Metafields and metaobjects** helpers.
- **Other platforms** — the `integration_platform` column is already shaped for
  Ecwid, BigCommerce and WooCommerce, which `shopGPT-app` also carries.

---

## Versioning

Semantic versioning. Anything under `ShopGPT\ShopifyIntegration\Internal` is
not covered by the compatibility promise.
