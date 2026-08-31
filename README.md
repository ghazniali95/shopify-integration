# Shopify Integration for Laravel

Multi-tenant Shopify authentication for Laravel apps — embedded and standalone.

One Shopify app can be installed by thousands of merchants. Each install is a
tenant: its own store domain, its own access token, its own expiry, its own
webhooks. This package owns that lifecycle so your app does not have to.

**Design rule:** the package authenticates *Shopify*. It never touches your
users, guards or sessions. It stores the store and its tokens, fires an event,
and gets out of the way.

---

## Contents

- [Build status](#build-status)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [The integrations table](#the-integrations-table)
- [Quick start — embedded app](#quick-start--embedded-app)
- [Quick start — standalone app](#quick-start--standalone-app)
- [Routes](#routes)
- [Middleware](#middleware)
- [The facade](#the-facade)
- [The store model](#the-store-model)
- [Calling the Admin API](#calling-the-admin-api)
- [Errors](#errors)
- [Events](#events)
- [Webhooks](#webhooks)
- [What you can change](#what-you-can-change)
- [Testing](#testing)
- [Security](#security)
- [Versioning](#versioning)

---

## Build status

| Area | State |
| --- | --- |
| OAuth install / callback, HMAC, state | **Built** |
| Expiring offline tokens, refresh, encryption at rest | **Built** |
| The `integrations` table and additive migration | **Built** |
| Session token verification, token exchange, embedded middleware | **Built** |
| Webhook receipt, GDPR topics, dedupe, `app/uninstalled` | **Built** |
| Admin API client — GraphQL, REST verbs, error classification | **Built** |
| Events, exceptions, facade, `asStore()` | **Built** |
| Webhook *registration* with Shopify (`webhooks:sync`) | Planned |
| Artisan commands | Planned |
| `$api->paginate()`, REST call-limit headers | Planned |
| `ShopifyIntegration::fake()`, model factories | Planned |

135 tests, 298 assertions, green on Laravel 10.50, 11.56 and 12.68.

---

## Requirements

| Package | PHP | Laravel |
| --- | --- | --- |
| `0.3.x` | `^8.1` | `10.x`, `11.x`, `12.x` |

Requires a cache store (OAuth state) and a queue worker (webhook handling).
Any driver works.

---

## Installation

```bash
composer require shopgpt/shopify-integration
```

```bash
php artisan vendor:publish --tag=shopifyIntegration-config
php artisan vendor:publish --tag=shopifyIntegration-migrations
php artisan migrate
```

The service provider is auto-discovered. Config lands at
`config/shopifyIntegration.php`.

```env
SHOPIFY_CLIENT_ID=your_api_key
SHOPIFY_CLIENT_SECRET=your_api_secret
SHOPIFY_SCOPES=write_products,read_orders
SHOPIFY_EMBEDDED=true
```

In your Partner dashboard set the App URL to
`https://your-app.com/shopify/auth/begin` and the redirect URL to
`https://your-app.com/shopify/auth/callback`.

---

## Configuration

Every key in `config/shopifyIntegration.php`. Ten of them read from the
environment; the rest are edited in the config file.

| Key | Env var | Default | What it does |
| --- | --- | --- | --- |
| `client_id` | `SHOPIFY_CLIENT_ID` | — | Your app's API key |
| `client_secret` | `SHOPIFY_CLIENT_SECRET` | — | Signs and verifies everything |
| `api_version` | `SHOPIFY_API_VERSION` | `2025-07` | Admin API version used for every call |
| `scopes` | `SHOPIFY_SCOPES` | `write_products` | Comma-separated. Changing this forces re-auth |
| `debug` | `SHOPIFY_DEBUG` | `false` | Skips HMAC verification on the OAuth routes. Local only |
| `embedded.enabled` | `SHOPIFY_EMBEDDED` | `false` | Runs inside the Shopify Admin iframe |
| `embedded.entry` | — | `/shopify/app` | Where a merchant lands after installing |
| `model` | — | `Integration::class` | Point at your own subclass to add relations |
| `oauth.state_store` | — | `cache` | `cache` or `session`. Keep `cache` if embedded |
| `oauth.state_ttl` | — | `300` | Seconds a pending install stays valid |
| `oauth.listing_url` | `SHOPIFY_LISTING_URL` | `null` | Where to send someone who hits the install URL with no `shop` |
| `tokens.refresh_buffer` | — | `300` | Refresh this many seconds before expiry |
| `tokens.encrypt` | — | `true` | Encrypt tokens at rest with your app key |
| `routes.enabled` | — | `true` | Set `false` to register the routes yourself |
| `routes.prefix` | — | `shopify` | URL prefix for the package's routes |
| `routes.middleware` | — | `['web']` | Applied to the OAuth routes |
| `routes.webhook_middleware` | — | `['api']` | Applied to the webhook route |
| `webhooks.topics` | — | 5 topics | Topic => job class. See [Webhooks](#webhooks) |
| `webhooks.queue` | `SHOPIFY_WEBHOOK_QUEUE` | `default` | Queue webhook jobs are pushed to |
| `webhooks.log_channel` | `SHOPIFY_WEBHOOK_LOG` | `null` | Log channel for webhook activity |
| `webhooks.deduplicate` | — | `true` | Drop redeliveries of a webhook id already seen |
| `redirects.after_install` | — | `/` | Route name, URL, or closure. Ignored when embedded |
| `redirects.after_reinstall` | — | `/` | Same, for a store that had uninstalled |
| `redirects.on_failure` | — | `/` | Same, when OAuth fails |

Booleans are read the usual Laravel way, so `SHOPIFY_EMBEDDED=true` in `.env`
arrives as boolean `true` — quotes are neither needed nor wanted. Anything that
is not `true` (including an unset or empty value) is false.

> **After changing `.env` on a server that caches config**, run
> `php artisan config:clear` — or `config:cache` again. A cached config file
> has the old values baked in and will ignore the `.env` entirely.

Everything without an env var is edited in `config/shopifyIntegration.php`
directly. Add your own env keys there if you want them environment-driven:

```php
'tokens' => [
    'encrypt' => env('SHOPIFY_ENCRYPT_TOKENS', true),
],
```

---

## The integrations table

One row per connected store. Every column the package owns is prefixed
`integration_`, so the table can hold other platforms alongside Shopify.

The migration is **additive**: it creates `integrations` if you do not have
one, and adds only the missing columns if you do.

| Column | Type | Holds |
| --- | --- | --- |
| `id` | id | |
| `user_id` | foreignId, nullable | Yours to use; the package never writes it |
| `integration_platform` | string | Always `shopify` for these rows |
| `integration_external_id` | string | Shopify's numeric shop id — survives a rename |
| `integration_store_domain` | string | `acme.myshopify.com` |
| `integration_domain` | string | The custom domain, e.g. `acme.com` |
| `integration_access_token` | text | Encrypted at rest |
| `integration_refresh_token` | text | Encrypted at rest |
| `integration_token_expires_at` | timestamp | Null means a legacy permanent token |
| `integration_scopes` | string | What Shopify actually granted |
| `integration_installed_at` | timestamp | |
| `integration_uninstalled_at` | timestamp | Null means installed |
| `integration_name` | string | Shop profile |
| `integration_email` | string | Shop profile |
| `integration_shop_owner` | string | Shop profile |
| `integration_phone` | string | Shop profile |
| `integration_currency` | string | Shop profile |
| `integration_country_code` | string | Shop profile |
| `integration_country_name` | string | Shop profile |
| `integration_primary_locale` | string | Shop profile |
| `integration_plan_name` | string | Shop profile |
| `integration_weight_unit` | string | Shop profile |
| `integration_password_enabled` | boolean | Shop profile |
| `integration_shop_data` | json | The whole `shop.json` payload |
| `integration_shop_data_synced_at` | timestamp | |
| `integration_metadata` | json | Yours. The package never writes it |
| `created_at` / `updated_at` | timestamps | |

Columns are addressed **without** the prefix in code — `$store->access_token`,
not `$store->integration_access_token`.

---

## Quick start — embedded app

**1.** Set `SHOPIFY_EMBEDDED=true`.

**2.** Load App Bridge first in your host view:

```blade
<meta name="shopify-api-key" content="{{ config('shopifyIntegration.client_id') }}">
<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>

@vite(['resources/js/app.jsx'])
```

**3.** Protect your routes:

```php
// Pages loaded inside the Shopify Admin iframe
Route::middleware('shopifyIntegration.embedded')->group(function () {
    Route::get('/shopify/app', fn () => view('shopify'));
});

// XHR from inside the iframe, bearing a session token
Route::middleware('shopifyIntegration.session')->group(function () {
    Route::get('/api/products', [ProductController::class, 'index']);
});
```

**4.** Send the session token from the front end:

```js
const token = await shopify.idToken();

await fetch('/api/products', {
    headers: { Authorization: `Bearer ${token}` },
});
```

**5.** Call the API as that store:

```php
$products = ShopifyIntegration::currentStore()->api()->graphql(<<<'GQL'
    query { products(first: 10) { edges { node { id title } } } }
GQL)->json('data.products.edges');
```

---

## Quick start — standalone app

**1.** Leave `SHOPIFY_EMBEDDED=false` and set your redirects:

```php
'redirects' => [
    'after_install' => 'dashboard',
    'on_failure'    => '/connect-failed',
],
```

**2.** Send merchants to the install URL:

```php
return redirect(ShopifyIntegration::installUrl('acme.myshopify.com'));
```

**3.** Create or log in your user from the install event:

```php
Event::listen(StoreInstalled::class, function (StoreInstalled $event) {
    $user = User::firstOrCreate(
        ['email' => $event->context->uniqueEmail('your-app.com',
            fn ($email) => User::where('email', $email)->exists())],
        ['name' => $event->context->shopOwner()],
    );

    $event->store->update(['user_id' => $user->id]);

    Auth::login($user);
});
```

**4.** Guard your routes:

```php
Route::middleware('shopifyIntegration.installed')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});
```

---

## Routes

Registered under `routes.prefix` (default `shopify`). Set
`routes.enabled` to `false` to register your own instead.

| Method | URI | Name |
| --- | --- | --- |
| `GET` | `/shopify/auth/begin` | `shopifyIntegration.auth.begin` |
| `GET` | `/shopify/auth/callback` | `shopifyIntegration.auth.callback` |
| `POST` | `/shopify/webhooks` | `shopifyIntegration.webhooks` |

---

## Middleware

| Alias | Use on |
| --- | --- |
| `shopifyIntegration.embedded` | Page loads inside the admin iframe |
| `shopifyIntegration.session` | API requests carrying a session token |
| `shopifyIntegration.installed` | Standalone routes needing a working store |
| `shopifyIntegration.hmac` | Your own routes Shopify signs |

All three store-resolving middleware set `ShopifyIntegration::currentStore()`.
None of them touch `Auth::user()` — stack your own after them if you want a
Laravel user resolved:

```php
class AuthenticateStoreOwner
{
    public function handle($request, Closure $next)
    {
        $storeUserId = ShopifyIntegration::currentStore()?->user_id;

        if ($storeUserId && $user = User::find($storeUserId)) {
            Auth::setUser($user);
        }

        return $next($request);
    }
}
```

The package ships no `user()` relation — it does not know your User model.
Add one by subclassing, which is what `config.model` is for:

```php
class Store extends \ShopGPT\ShopifyIntegration\Models\Integration
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

```php
'model' => App\Models\Store::class,
```

### What `shopifyIntegration.session` answers with

| Status | Body | What the client should do |
| --- | --- | --- |
| `401` | `{"error": "session_token_missing"}` | Attach a token |
| `401` | `{"error": "session_token_invalid"}` | Mint a fresh token, retry **once** |
| `403` | `{"error": "reauthorization_required", "url": "…"}` | Break out of the frame to `url` |

Session tokens live about a minute, so a `401` is routine. A `403` means the
app has no usable install behind the token and retrying will not help.

```js
instance.interceptors.response.use(null, async (error) => {
    const { status, data } = error.response ?? {};

    if (status === 401 && !error.config._retried) {
        error.config._retried = true;
        error.config.headers.Authorization = `Bearer ${await shopify.idToken()}`;
        return instance.request(error.config);
    }

    if (status === 403 && data?.error === 'reauthorization_required') {
        window.top.location.href = data.url;
    }

    return Promise.reject(error);
});
```

`shopifyIntegration.embedded` sets `Content-Security-Policy: frame-ancestors`
for the merchant's admin and clears `X-Frame-Options`, so a global
`X-Frame-Options` elsewhere in your stack will not blank the app.

---

## The facade

```php
use ShopGPT\ShopifyIntegration\Facades\ShopifyIntegration;
```

| Method | Returns | Notes |
| --- | --- | --- |
| `currentStore()` | `?Integration` | The store this request is acting as |
| `setCurrentStore(?Integration)` | `void` | |
| `asStore(Integration, callable)` | `mixed` | Runs the callback as that store, then restores |
| `forDomain(string)` | `?Integration` | Look a store up by `acme.myshopify.com` |
| `ensureFreshToken(Integration)` | `Integration` | Refreshes if near expiry |
| `refreshToken(Integration)` | `Integration` | Forces a refresh |
| `verifySessionToken(?string)` | `?array` | Claims, or null if invalid |
| `storeFromClaims(?array)` | `?string` | The shop domain a claims array names |
| `exchangeToken(string, string)` | `?Integration` | Session token → stored access token |
| `installUrl(string)` | `string` | For a "connect your store" button |
| `redirectUri()` | `string` | The callback URL, for your Partner dashboard |
| `model()` | `class-string` | The configured model class |
| `sessionTokenHeaders(…)` | `array` | Test support |
| `webhookHeaders(…)` | `array` | Test support |

`asStore()` is the guard rail for queued jobs and loops over stores:

```php
foreach (Integration::installed()->cursor() as $store) {
    ShopifyIntegration::asStore($store, function ($store) {
        $store->api()->graphql($query);
    });
}
```

---

## The store model

`ShopGPT\ShopifyIntegration\Models\Integration`. All queries are scoped to
Shopify rows automatically.

| Method | Returns | |
| --- | --- | --- |
| `Integration::forDomain($domain)` | `?static` | |
| `Integration::forExternalId($id)` | `?static` | |
| `Integration::resolve($externalId, $domain)` | `?static` | Id first, domain second |
| `Integration::installed()` | scope | |
| `$store->isInstalled()` | `bool` | |
| `$store->hasValidToken()` | `bool` | |
| `$store->tokenExpiresSoon($buffer = null)` | `bool` | |
| `$store->hasRequiredScopes($required = null)` | `bool` | Against `config.scopes` |
| `$store->needsReauthorization()` | `bool` | No valid token, or missing scopes |
| `$store->api()` | `ApiClient` | Token guaranteed fresh |
| `$store->markUninstalled()` | `void` | |
| `$store->redact()` | `void` | Clears the PII columns |

Attributes are read without the `integration_` prefix:

```php
$store->store_domain;      // acme.myshopify.com
$store->domain;            // acme.com
$store->access_token;      // decrypted
$store->scopes;
$store->token_expires_at;  // Carbon|null
$store->shop_data;         // array — the whole shop.json
$store->plan_name;
```

---

## Calling the Admin API

`$store->api()` returns a client bound to that store, with the token refreshed
first. You never pass a token or a domain.

```php
$api = $store->api();

// GraphQL
$api->graphql('query { shop { name } }');
$api->graphql($query, ['first' => 10]);

// REST
$api->get('products.json', ['limit' => 50]);
$api->post('products.json', ['product' => [...]]);
$api->put('products/123.json', ['product' => [...]]);
$api->delete('products/123.json');
```

All five return an `Illuminate\Http\Client\Response`.

GraphQL cost is read from `extensions.cost.throttleStatus` after each response;
when the bucket drops below 20% the client waits for it to refill, capped at
5 seconds.

---

## Errors

| Status | Exception | Store flagged uninstalled |
| --- | --- | --- |
| 401 | `StoreUninstalledException` | Yes — also fires `StoreUninstalled` |
| 402 | `StoreUnavailableException` (`isFrozen()`) | No — unpaid, comes back |
| 423 | `StoreUnavailableException` (`isLocked()`) | No — locked by Shopify |
| 429 | `RateLimitedException` (`$e->retryAfter`) | No |
| other | `ShopifyApiException` | No |

Every one of them carries `$e->store`. `ShopifyApiException` and its subclasses
also carry `$e->body`.

```php
try {
    $store->api()->graphql($query);
} catch (StoreUninstalledException $e) {
    // already flagged; your StoreUninstalled listeners have run
} catch (RateLimitedException $e) {
    $this->release($e->retryAfter);
} catch (StoreUnavailableException $e) {
    // still installed — try again later
}
```

`TokenRefreshException` is thrown when a refresh fails **and** the stored token
has already expired. A failed refresh on a still-valid token is not fatal.

---

## Events

The extension point. Everything your app does beyond Shopify itself hangs off
these.

| Event | Fired when | Carries |
| --- | --- | --- |
| `OAuthStarted` | An install begins | `$shop` |
| `OAuthFailed` | HMAC, state or exchange failed | `$shop`, `$reason`, `$exception` |
| `StoreInstalled` | A store the app had never seen authorised | `$store`, `$context` |
| `StoreReinstalled` | A previously uninstalled store came back | `$store`, `$context` |
| `StoreTokenExchanged` | An access token came from a session token | `$store` |
| `StoreTokenRefreshed` | A token was refreshed | `$store` |
| `TokenRefreshFailed` | A refresh failed | `$store`, `$reason`, `$fatal` |
| `StoreScopesUpdated` | `app/scopes_update` arrived | `$store`, `$previous`, `$current` |
| `StoreProfileUpdated` | `shop/update` refreshed the profile | `$store`, `$changed`, `$previousPlan` |
| `StoreRenamed` | The myshopify domain changed | `$store`, `$previousDomain`, `$currentDomain` |
| `StoreUninstalled` | The webhook or a 401 said so | `$store` |

Three of them carry a helper worth knowing about:

```php
// StoreScopesUpdated — what actually moved
$event->gained();   // ['write_products']
$event->lost();     // ['read_orders'] — calls needing these now 403

// StoreProfileUpdated — the one that matters for billing
$event->planChanged();    // left a development plan?
$event->previousPlan;

// TokenRefreshFailed — false means the current token is still usable and
// the next call retries; true means the merchant must re-authorise
$event->fatal;
```

`$context` is an `InstallContext`:

```php
$context->store;              // Integration
$context->shopData;           // array — raw shop.json
$context->isNewInstall;       // bool
$context->isReinstall;        // bool
$context->viaTokenExchange;   // bool — arrived embedded, not through the redirect
$context->scopes;             // string|null — what Shopify granted
$context->host;               // string|null — the host param, embedded only

$context->domain();           // acme.myshopify.com
$context->shopOwner();
$context->email();
$context->currency();
$context->profile();          // the 11 promoted columns as an array
$context->isDevelopmentStore();
$context->uniqueEmail($fallbackDomain, $isTaken);
```

`uniqueEmail()` matters when one person runs several stores: Shopify gives the
same contact address on each, so a unique constraint on `users.email` would
reject the second. It returns a stable synthetic address when the real one is
taken.

`isDevelopmentStore()` matters for billing — a dev store cannot be charged live.

---

## Webhooks

The endpoint is `POST /shopify/webhooks`. Point Shopify at it, then map topics
to jobs in config:

```php
'topics' => [
    'app/uninstalled'        => HandleAppUninstalled::class,
    'app/scopes_update'      => HandleScopesUpdate::class,
    'shop/update'            => HandleShopUpdate::class,
    'shop/redact'            => HandleShopRedact::class,
    'customers/redact'       => HandleCustomersRedact::class,
    'customers/data_request' => HandleCustomersDataRequest::class,

    // Any other topic you subscribe to, mapped to your own job:
    // 'your/topic'          => App\Jobs\YourHandler::class,
],
```

Those six ship with the package. The three GDPR topics are mandatory for
public apps and the shipped handlers are enough to pass review.

**`app/scopes_update` matters more than it looks.** Under Shopify managed
installation the merchant approves a scope change inside the admin and your
app is never called, so without this webhook the stored scopes go stale:
`hasRequiredScopes()` keeps reporting a shortfall the merchant has already
fixed, and you send them back through an authorisation they do not need.

> **Upgrading from 0.2?** `webhooks.topics` lives in the config you published,
> so this topic will not appear there on its own. Add the line by hand, and
> subscribe to the topic in `shopify.app.toml`.

### Writing a handler

Extend `WebhookJob`:

```php
use ShopGPT\ShopifyIntegration\Jobs\WebhookJob;

class YourHandler extends WebhookJob
{
    public function handle(): void
    {
        $this->store;        // Integration, resolved for you
        $this->topic;        // the topic this was registered under
        $this->payload;      // array
        $this->webhookId;    // X-Shopify-Webhook-Id

        $this->resourceId(); // $payload['id']
    }
}
```

The job carries the whole payload by default. Trim it for high-volume topics:

```php
protected static function payloadForQueue(array $payload): array
{
    return ['id' => $payload['id']];
}
```

A busy store can repeat a large resource payload every few seconds, so a job
that re-fetches the resource anyway should carry only the id. The GDPR topics
need the full payload, which is why keeping it is the default.

Webhook **registration** with Shopify is not built yet — declare your topics in
`shopify.app.toml`:

```toml
[webhooks]
api_version = "2025-07"

  [[webhooks.subscriptions]]
  topics = [ "app/uninstalled", "app/scopes_update", "shop/update" ]
  uri = "https://your-app.com/shopify/webhooks"
```

---

## What you can change

Everything here is meant to be overridden from your app.

| To change | Do this |
| --- | --- |
| Where a merchant lands after install | `redirects.after_install` — route name, URL, or closure receiving the `InstallContext` |
| What happens on install | Listen for `StoreInstalled` / `StoreReinstalled` |
| Add relations to a store | Subclass `Integration`, point `config.model` at it |
| Handle a new webhook topic | Add `topic => YourJob::class` to `webhooks.topics` |
| Replace a shipped webhook handler | Point that topic at your own job class |
| What travels the queue for a topic | Override `payloadForQueue()` on your job |
| Register the routes yourself | `routes.enabled = false` |
| Change the URL prefix | `routes.prefix` |
| Add middleware to the OAuth routes | `routes.middleware` |
| Where an unknown visitor goes | `oauth.listing_url` |

The package deliberately has **no** opinion about users, guards, sessions,
billing or onboarding. If you need one of those, listen for an event.

---

## Testing

The package mints valid session tokens and webhook signatures so you never
hand-roll a JWT or an HMAC:

```php
$this->withHeaders(ShopifyIntegration::sessionTokenHeaders($store))
     ->getJson('/api/products')
     ->assertOk();
```

Override claims to test your own edge cases:

```php
ShopifyIntegration::sessionTokenHeaders($store, ['exp' => time() - 60]);
ShopifyIntegration::sessionTokenHeaders($store, ['aud' => 'another-app']);
```

For webhooks:

```php
ShopifyIntegration::webhookHeaders('app/uninstalled', $store, $payload);
```

Both are checked against the package's own verification in its test suite.

Fake Shopify itself with `Http::fake()` against `https://{shop}/admin/api/*`.
`ShopifyIntegration::fake()` and model factories are planned.

---

## Security

- **Never set `debug` to `true` in production.** HMAC is what proves an install
  request came from Shopify. With it off, anyone who knows a store domain can
  install any store against your app, and nothing about the request looks
  wrong. Every skip is logged as a warning so a forgotten `SHOPIFY_DEBUG=true`
  is visible in your logs.
- **Keep `oauth.state_store` on `cache`** if there is any chance the app runs
  embedded. Sessions do not survive the admin iframe.
- **Tokens are encrypted with your `APP_KEY`.** Rotating it without re-encrypting
  makes every stored token unreadable. Reads fall back to the raw value, so a
  table holding plaintext tokens keeps working and is encrypted on next write.
- **`shop/redact` clears the PII columns and `integration_shop_data`.** Anything
  you copied onto your own tables is yours to redact.

---

## Versioning

Semver. `0.x` while the API settles — require it as `^0.3`.

`0.3.0` added the `app/scopes_update` topic and four events. Nothing breaks,
but a config you published earlier will not have the new topic — add it by
hand, and subscribe to it in `shopify.app.toml`.

`0.2.0` renamed `oauth.skip_hmac_in_debug` to a plain top-level `debug`
key. If you published the config before then, move the value across; the
old key is no longer read.

Repo: `github.com/ghazniali95/shopify-integration` ·
Composer: `shopgpt/shopify-integration` (the vendor prefix does not have to
match the GitHub account; Packagist reads the name from `composer.json`).
