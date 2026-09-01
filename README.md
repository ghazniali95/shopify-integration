# Shopify Integration for Laravel

Multi-tenant Shopify authentication for Laravel apps — embedded and standalone.

**Design rule:** the package authenticates *Shopify*. It never touches your
users, guards or sessions. It stores the store and its tokens, fires an event,
and gets out of the way.

---

## Contents

- [Build status](#build-status)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Storage: the table is yours](#storage-the-table-is-yours)
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

**Built** — OAuth install and callback (HMAC, state), expiring offline tokens
with refresh, the storage contracts and the shipped Eloquent repository,
session token verification and token exchange for embedded apps, webhook
receipt with dedupe and the six shipped topics, the Admin API client (GraphQL
and REST), and the events, exceptions and facade around all of it.

**Planned** — webhook registration with Shopify (`webhooks:sync`), Artisan
commands, `$api->paginate()` and REST call-limit headers,
`ShopifyIntegration::fake()` and model factories.

154 tests, 355 assertions, green on Laravel 10.50, 11.56 and 12.68.

---

## Requirements

| Package | PHP | Laravel |
| --- | --- | --- |
| `0.5.x` | `^8.1` | `10.x`, `11.x`, `12.x` |

Requires a cache store (OAuth state) and a queue worker (webhook handling).
Any driver works.

---

## Installation

```bash
composer require shopgpt/shopify-integration
```

```bash
php artisan vendor:publish --tag=shopifyIntegration-config
```

The service provider is auto-discovered. Config lands at
`config/shopifyIntegration.php`.

**There is no migration to publish.** The package does not own a table — see
[Storage](#storage-the-table-is-yours) for the one thing you do have to set
up.

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

Every key in `config/shopifyIntegration.php`. Nine of them read from the
environment; the rest are edited in the config file.

| Key | Env var | Default | What it does |
| --- | --- | --- | --- |
| `client_id` | `SHOPIFY_CLIENT_ID` | — | Your app's API key |
| `client_secret` | `SHOPIFY_CLIENT_SECRET` | — | Signs and verifies everything |
| `api_version` | `SHOPIFY_API_VERSION` | `2025-07` | Admin API version used for every call |
| `scopes` | `SHOPIFY_SCOPES` | `write_products` | Comma-separated. Changing this forces re-auth |
| `debug` | `SHOPIFY_DEBUG` | `false` | Skips HMAC verification on the OAuth routes. Local only |
| `embedded.enabled` | `SHOPIFY_EMBEDDED` | `false` | Runs inside the Shopify Admin iframe |
| `store.repository` | — | `EloquentStoreRepository::class` | Every read and write. Override to own the INSERT |
| `store.model` | — | `Integration::class` | The Eloquent model the default repository uses |
| `store.table` | `SHOPIFY_STORE_TABLE` | `integrations` | Only used by the shipped default model |
| `store.columns` | — | `[]` | Logical field => your column name. See [Storage](#storage-the-table-is-yours) |
| `store.platform` | — | `shopify` | Written to, and scoped by, the `platform` column when mapped |
| `store.encrypt_tokens` | `SHOPIFY_ENCRYPT_TOKENS` | `false` | Encrypt tokens at rest. Off: your model may already |
| `store.pii` | — | 4 fields | Logical fields a `shop/redact` clears |
| `oauth.state_store` | — | `cache` | `cache` or `session`. Keep `cache` if embedded |
| `oauth.state_ttl` | — | `300` | Seconds a pending install stays valid |
| `oauth.hmac_ttl` | — | `300` | Seconds a signed Shopify request stays acceptable. `0` checks the signature only |
| `oauth.listing_url` | `SHOPIFY_LISTING_URL` | `null` | Where to send someone who hits the install URL with no `shop` |
| `tokens.refresh_buffer` | — | `300` | Refresh this many seconds before expiry |
| `routes.enabled` | — | `true` | Set `false` to register the routes yourself |
| `routes.prefix` | — | `shopify` | URL prefix for the package's routes |
| `routes.middleware` | — | `['web']` | Applied to the OAuth routes |
| `routes.webhook_middleware` | — | `['api']` | Applied to the webhook route |
| `webhooks.topics` | — | 6 topics | Topic => job class. See [Webhooks](#webhooks) |
| `webhooks.queue` | `SHOPIFY_WEBHOOK_QUEUE` | `default` | Queue webhook jobs are pushed to |
| `webhooks.log_channel` | `SHOPIFY_WEBHOOK_LOG` | `null` | Log channel for webhook activity |
| `webhooks.deduplicate` | — | `true` | Drop redeliveries of a webhook id already seen |
| `redirects.after_install` | — | `/` | Route name, URL, or closure. Ignored when embedded |
| `redirects.after_reinstall` | — | `/` | Same, for a store that had uninstalled |
| `redirects.on_failure` | — | `/` | Same, when OAuth fails |

Everything without an env var is edited in `config/shopifyIntegration.php`
directly. Add your own env keys there if you want them environment-driven:

```php
'oauth' => [
    'state_ttl' => env('SHOPIFY_STATE_TTL', 300),
],
```

---

## Storage: the table is yours

The package ships no migration, defines no columns, and never assumes what a
store row looks like beyond six facts it cannot work without. Everything it
reads or writes goes through two interfaces.

**`ShopifyStore`** — one connected store, as the package needs to read it:

```php
public function getKey();
public function shopifyDomain(): string;
public function shopifyExternalId(): ?string;
public function shopifyAccessToken(): ?string;
public function shopifyRefreshToken(): ?string;
public function shopifyTokenExpiresAt(): ?DateTimeInterface;
public function shopifyScopes(): ?string;
public function shopifyIsInstalled(): bool;
```

**`ShopifyStoreRepository`** — every read and write, including the INSERT.

### The short version

Add the trait to the model you already have, and tell the package what your
columns are called:

```php
use ShopGPT\ShopifyIntegration\Concerns\InteractsWithShopifyStore;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;

class Integration extends Model implements ShopifyStore
{
    use InteractsWithShopifyStore;
}
```

```php
// config/shopifyIntegration.php
'store' => [
    'model'   => App\Models\Integration::class,
    'columns' => [
        'store_domain' => 'domain',
        'access_token' => 'token',
        'external_id'  => 'integration_id',
        'platform'     => 'type',
    ],
],
```

Anything you omit defaults to its own name. Map a field to `null` and the
package stops writing it — the value still reaches your listeners on the
events, it just is not persisted. Only `store_domain` and `access_token` are
genuinely required.

Your migration, your column names, your indexes, your encryption.

### When the INSERT needs something the package cannot know

A store table often has a column Shopify has no opinion about — an owning user,
a tenant, a plan — and it is often `NOT NULL`. The package fires
`StoreInstalled` **after** the row is written, which is too late to fill one.

The repository is the seam. Extend the shipped one and override a single
method:

```php
class AppStoreRepository extends EloquentStoreRepository
{
    protected function newStore(string $shop, array $shopData): Model
    {
        $store = parent::newStore($shop, $shopData);

        $store->user_id = Auth::id()
            ?? User::firstOrCreate(['email' => $shopData['email']], [...])->id;

        return $store;
    }
}
```

```php
'store' => ['repository' => App\Repositories\AppStoreRepository::class],
```

`newStore()` runs before anything is saved, with the full `shop.json` in hand,
so a required column stays required. Nothing else about the package changes:
token refresh, the API client, the middleware and the webhook handlers all keep
working, because they talk to the interfaces rather than to columns.

For total control — a different ORM, a remote service, an existing service
layer — implement `ShopifyStoreRepository` yourself and bind it.

### Fields the package will use if you give it a column

`platform`, `external_id`, `store_domain`, `access_token`, `refresh_token`,
`token_expires_at`, `scopes`, `installed_at`, `uninstalled_at` — and, for the
profile promoted out of `shop.json`: `domain`, `name`, `email`, `shop_owner`,
`phone`, `currency`, `country_code`, `country_name`, `primary_locale`,
`plan_name`, `weight_unit`, `password_enabled`, `shop_data`,
`shop_data_synced_at`.

Two are worth knowing about:

- **`uninstalled_at`** is how the package tells an installed store from a
  removed one. With no such column every store reads as installed; if you track
  that with a boolean instead, map it to `null` and override
  `shopifyIsInstalled()`.
- **`token_expires_at`** null means a legacy permanent token, which is valid
  and never refreshed.

### Token encryption

Off by default, because storage is your business and a model that already casts
its token column would otherwise be encrypted twice. Turn it on only when
nothing else is:

```php
'store' => ['encrypt_tokens' => true],
```

---

## Quick start — embedded app

**1.** Set `SHOPIFY_EMBEDDED=true`. A completed install then hands the browser
back to Shopify (`admin.shopify.com/store/…/apps/…`), which loads the App URL
from your Partner dashboard inside the admin frame — set that App URL to the
route in step 3.

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
    Auth::login($event->store->user);
});
```

The user itself is resolved in your repository, not here — the row is already
written by the time this event fires, so a `NOT NULL user_id` has to be filled
before it:

```php
class AppStoreRepository extends EloquentStoreRepository
{
    protected function newStore(string $shop, array $shopData): Model
    {
        $store = parent::newStore($shop, $shopData);

        $store->user_id = User::firstOrCreate(
            ['email' => $shopData['email']],
            ['name'  => $shopData['shop_owner'] ?? null],
        )->id;

        return $store;
    }
}
```

`InstallContext::uniqueEmail()` is there for the case one merchant runs several
stores and gives Shopify the same contact address on each — a plain unique
constraint on `users.email` would reject the second one:

```php
$email = $event->context->uniqueEmail(
    'your-app.com',
    fn ($email) => User::where('email', $email)->exists(),
);
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

**`begin` verifies a signature when there is one.** Shopify signs the requests
it sends, and those are checked — wrong signature, or one older than
`oauth.hmac_ttl`, is a 401. A merchant arriving from a "connect your store"
button on your own site carries no signature and cannot invent one, so an
unsigned request is admitted: this route only redirects to Shopify's own
authorize page, where the merchant still has to log in and approve.

**`callback` always requires one.** That is where the install is actually
granted, and the signature, the single-use `state` and the code exchange all
have to line up.

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

The package ships no `user()` relation — it does not know your User model, and
the store model is yours anyway. Put the relation where it belongs:

```php
class Integration extends Model implements ShopifyStore
{
    use InteractsWithShopifyStore;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

```php
'store' => ['model' => App\Models\Integration::class],
```

Filling `user_id` on a *new* install is the repository's job, not the model's —
see [Storage](#storage-the-table-is-yours).

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
| `currentStore()` | `?ShopifyStore` | The store this request is acting as |
| `setCurrentStore(?ShopifyStore)` | `void` | |
| `asStore(ShopifyStore, callable)` | `mixed` | Runs the callback as that store, then restores |
| `stores()` | `ShopifyStoreRepository` | Every read and write |
| `forDomain(string)` | `?ShopifyStore` | Look a store up by `acme.myshopify.com` |
| `api(ShopifyStore)` | `ApiClient` | Token guaranteed fresh. Works without the trait |
| `ensureFreshToken(ShopifyStore)` | `ShopifyStore` | Refreshes if near expiry |
| `refreshToken(ShopifyStore)` | `ShopifyStore` | Forces a refresh |
| `verifySessionToken(?string)` | `?array` | Claims, or null if invalid |
| `storeFromClaims(?array)` | `?string` | The shop domain a claims array names |
| `exchangeToken(string, string)` | `?ShopifyStore` | Session token → stored access token |
| `installUrl(string)` | `string` | For a "connect your store" button |
| `redirectUri()` | `string` | The callback URL, for your Partner dashboard |
| `model()` | `class-string` | The configured store model class |
| `sessionTokenHeaders(…)` | `array` | Test support |
| `webhookHeaders(…)` | `array` | Test support |

`asStore()` is the guard rail for queued jobs and loops over stores:

```php
foreach (Integration::query()->installed()->cursor() as $store) {
    ShopifyIntegration::asStore($store, function ($store) {
        ShopifyIntegration::api($store)->graphql($query);
    });
}
```

---

## The store model

Lookups and writes go through the repository, not through a model static — the
model is yours, and the package cannot put methods on it.

```php
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository;

$stores = app(ShopifyStoreRepository::class);   // or ShopifyIntegration::stores()
```

| Method | Returns | |
| --- | --- | --- |
| `$stores->findByDomain($domain)` | `?ShopifyStore` | |
| `$stores->findByExternalId($id)` | `?ShopifyStore` | |
| `$stores->findByKey($key)` | `?ShopifyStore` | |
| `$stores->persistInstall($existing, $shop, $token, $shopData)` | `ShopifyStore` | The INSERT seam |
| `$stores->updateTokens($store, $token)` | `ShopifyStore` | |
| `$stores->updateProfile($store, $shopData)` | `array{changed, previous}` | |
| `$stores->updateScopes($store, $scopes)` | `ShopifyStore` | |
| `$stores->markUninstalled($store)` | `void` | Drops the credentials too |
| `$stores->redact($store)` | `void` | Clears the mapped PII fields |

State questions work on any `ShopifyStore`, wherever it is stored:

```php
use ShopGPT\ShopifyIntegration\Support\StoreState;

StoreState::hasValidToken($store);
StoreState::tokenExpiresSoon($store, $buffer = null);
StoreState::hasRequiredScopes($store, $required = null);   // against config.scopes
StoreState::needsReauthorization($store);                  // no token, or missing scopes
```

A model using `InteractsWithShopifyStore` gets those as methods, plus an
`installed()` query scope and `$store->api()` with the token guaranteed fresh:

```php
$store->isInstalled();
$store->hasValidToken();
$store->hasRequiredScopes();
$store->needsReauthorization();
$store->api();

Integration::query()->installed()->get();
```

Read the six facts through the contract when you want to be storage-agnostic —
in a listener that any app might wire up, say:

```php
$store->shopifyDomain();          // acme.myshopify.com
$store->shopifyAccessToken();     // decrypted
$store->shopifyScopes();
$store->shopifyTokenExpiresAt();  // DateTimeInterface|null
```

Everything else — `$store->plan_name`, your relations, your accessors — is your
model's own business, exactly as it was before.

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
| `StoreProfileUpdated` | `shop/update` refreshed the profile | `$store`, `$changed`, `$previousPlan`, `$currentPlan` |
| `StoreRenamed` | The myshopify domain changed | `$store`, `$previousDomain`, `$currentDomain` |
| `StoreUninstalled` | The webhook or a 401 said so | `$store` |

Three of them carry a helper worth knowing about:

```php
// StoreScopesUpdated — what actually moved
$event->gained();   // ['write_products']
$event->lost();     // ['read_orders'] — calls needing these now 403

// StoreProfileUpdated — the one that matters for billing
$event->planChanged();    // the plan is not what it was — dev store went live?
$event->previousPlan;     // and $event->currentPlan

// TokenRefreshFailed — false means the current token is still usable and
// the next call retries; true means the merchant must re-authorise
$event->fatal;
```

`$context` is an `InstallContext`:

```php
$context->store;              // ShopifyStore
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
$context->profile();          // the 11 profile fields, read from shop.json
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
        $this->store();      // ?ShopifyStore, resolved through your repository
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

`handle()` is called through the container, so type-hint anything you need —
including `ShopifyStoreRepository` when the handler has to write to the store.

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
| Use your own store table | `store.columns` + `store.model` — see [Storage](#storage-the-table-is-yours) |
| Fill a required column on install | Override `newStore()` on `EloquentStoreRepository` |
| Replace storage entirely | Implement `ShopifyStoreRepository` and bind it |
| Handle a new webhook topic | Add `topic => YourJob::class` to `webhooks.topics` |
| Replace a shipped webhook handler | Point that topic at your own job class |
| What travels the queue for a topic | Override `payloadForQueue()` on your job |
| Register the routes yourself | `routes.enabled = false` |
| Change the URL prefix | `routes.prefix` |
| Add middleware to the OAuth routes | `routes.middleware` |
| Where an unknown visitor goes | `oauth.listing_url` |

The package deliberately has **no** opinion about users, guards, sessions,
billing, onboarding — or your schema. If you need one of those, listen for an
event or implement the repository.

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

- **Never set `debug` to `true` in production.** It skips HMAC verification on
  the callback, which is where the install is granted — with it off, anyone who
  knows a store domain can write a store row and a token into your app, and
  nothing about the request looks wrong. Every skip is logged as a warning so a
  forgotten `SHOPIFY_DEBUG=true` is visible in your logs.
- **A signature is checked whenever one is present, and expires.** A correct
  HMAC stays correct forever, so `oauth.hmac_ttl` bounds how long a signed
  Shopify URL kept in a log or a browser history is still worth anything.
- **Keep `oauth.state_store` on `cache`** if there is any chance the app runs
  embedded. Sessions do not survive the admin iframe. The nonce is single-use
  and forms part of its own storage key, so two installs started for the same
  store at once do not invalidate each other.
- **`host` is validated before it is redirected to.** It arrives inside the
  HMAC-verified query string, but it decides where the merchant lands after an
  embedded install, so it is checked against the two shapes Shopify actually
  sends and otherwise derived from the store domain.
- **Token encryption is off by default.** Storage is your app's, and a model
  already casting its token column would otherwise be encrypted twice — so
  check that *something* is encrypting those columns before you ship. With
  `store.encrypt_tokens` on, the package uses your `APP_KEY`: rotating it
  without re-encrypting makes every stored token unreadable. Reads fall back
  to the raw value, so a table holding plaintext tokens keeps working and is
  encrypted on next write.
- **`shop/redact` clears the PII fields you mapped, `shop_data` included.** Anything
  you copied onto your own tables is yours to redact.

---

## Versioning

Semver. `0.x` while the API settles — require it as `^0.5`.

### `0.5.0`

**The package no longer owns a table.** It shipped a migration and a fixed set
of `integration_`-prefixed columns; both are gone. Storage is now defined by two
interfaces, and the shipped Eloquent repository is a default you can replace.

This is the change that lets an app keep a `NOT NULL` owning column — a
`user_id`, a tenant — on its store table. The package writes the row through
your repository, so `newStore()` runs before the INSERT with the whole
`shop.json` in hand.

**Breaking**

- **The migration is removed.** `vendor:publish --tag=shopifyIntegration-migrations`
  no longer exists. Bring your own table; nothing about its shape is assumed
  beyond what `store.columns` says.
- **The `integration_` column prefix is gone.** Column names come from
  `store.columns`, defaulting to the bare logical name. Existing installs keep
  their schema by mapping each field to the column it already uses.
- **`config.model` moved to `store.model`**, and `tokens.encrypt` to
  `store.encrypt_tokens` — **now `false` by default**, because a model that
  already casts its token column would otherwise be encrypted twice.
- **The model's lookups and mutations moved to the repository.**
  `Integration::forDomain()`, `forExternalId()`, `resolve()`,
  `markUninstalled()` and `redact()` are now
  `ShopifyStoreRepository::findByDomain()`, `findByExternalId()`,
  `markUninstalled()`, `redact()`. `StoreWriter::resolve()` covers the
  id-then-domain lookup.
- **Package internals type-hint `ShopifyStore`, not the model.** Events,
  exceptions, middleware, `InstallContext` and the facade all changed
  signature. `$store->access_token` becomes `$store->shopifyAccessToken()` in
  code that must work against any implementation; a model using
  `InteractsWithShopifyStore` keeps its own attributes as they were.
- **Platform scoping moved off the model.** It was a global scope; it is now
  applied by the repository, and only when a `platform` column is mapped.
- **`InstallContext` profile accessors read `shop.json`, not the store.**
  `profile()`, `email()`, `shopOwner()`, `currency()` and
  `isDevelopmentStore()` answer from the payload, so they still work on a
  table that persists no profile at all.
- **`StoreProfileUpdated` gained `$currentPlan`.** `planChanged()` compared
  against a model attribute that is no longer guaranteed to exist.
- **`WebhookJob::handle()` is resolved through the container.** The shipped
  handlers type-hint `ShopifyStoreRepository`; calling `handle()` with no
  arguments in a test now needs `app()->call([$job, 'handle'])`.

**Added**

- **`ShopifyStore`** and **`ShopifyStoreRepository`** contracts.
- **`InteractsWithShopifyStore`** — satisfies the contract on an Eloquent model
  through the column map, so an existing model needs one trait and no code.
- **`EloquentStoreRepository`** — the shipped default. Override `newStore()`
  to own the INSERT.
- **`StoreState`** — `hasValidToken()`, `tokenExpiresSoon()`,
  `hasRequiredScopes()`, `needsReauthorization()` against any `ShopifyStore`.
- **`ShopifyIntegration::api($store)`** and **`::stores()`**.

### `0.4.0`

Six fixes in the OAuth path, three of which change behaviour you may be
relying on.

**Breaking**

- **`auth/begin` no longer requires a signature.** It verifies one when
  Shopify sent one — a wrong signature, or one older than `oauth.hmac_ttl`,
  is still a 401 — but a request carrying none is now admitted. Refusing
  those meant `ShopifyIntegration::installUrl()` and all three
  reauthorisation URLs the package generates returned 401 against its own
  endpoint, which dead-ended the scope-upgrade flow. The route only redirects
  to Shopify's authorize page, where the merchant still has to log in and
  approve; `auth/callback` is unchanged and still requires a signature.
- **An embedded install returns to Shopify, not to a path of your own.** The
  callback is a top-level navigation, so redirecting to the app's own entry
  path rendered it outside the admin entirely. It now redirects to
  `admin.shopify.com/store/…/apps/…` and Shopify loads the app in the frame.
  **`embedded.entry` is removed** — set your entry point as the App URL in
  the Partner dashboard instead.
- **`markUninstalled()` clears the stored tokens.** `access_token`,
  `refresh_token` and `token_expires_at` are nulled alongside
  `uninstalled_at`. A listener on `StoreUninstalled` can no longer read them
  back off the model; a reinstall issues a fresh pair either way.

**Fixed**

- **A declined authorisation is reported as `access_denied`.** Pressing
  Cancel on the authorize screen used to reach `OAuthFailed` as
  `'missing shop or code'`, indistinguishable from a malformed request.
- **Concurrent installs for one store no longer invalidate each other.** The
  state nonce is keyed by nonce as well as shop, so opening the install in a
  second tab no longer breaks the first tab's callback.

**Added**

- **`oauth.hmac_ttl`** (default `300`) bounds how long a signed Shopify
  request stays acceptable. A correct HMAC stays correct forever, so without
  a window a signed URL kept in a log or a browser history worked
  indefinitely. Set `0` to check the signature only.

**Security**

- `host` is validated against the shapes Shopify actually sends before it is
  used as a redirect target, and the state nonce is shape-checked before it
  reaches the cache as key material.

### `0.3.0`

Added the `app/scopes_update` topic and four events. Nothing breaks, but a
config you published earlier will not have the new topic — add it by hand, and
subscribe to it in `shopify.app.toml`.

### `0.2.0`

Renamed `oauth.skip_hmac_in_debug` to a plain top-level `debug` key. If you
published the config before then, move the value across; the old key is no
longer read.

Repo: `github.com/ghazniali95/shopify-integration` ·
Composer: `shopgpt/shopify-integration` (the vendor prefix does not have to
match the GitHub account; Packagist reads the name from `composer.json`).
