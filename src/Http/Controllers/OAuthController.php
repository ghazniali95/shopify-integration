<?php

namespace ShopGPT\ShopifyIntegration\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use ShopGPT\ShopifyIntegration\Events\OAuthFailed;
use ShopGPT\ShopifyIntegration\Events\OAuthStarted;
use ShopGPT\ShopifyIntegration\Events\StoreInstalled;
use ShopGPT\ShopifyIntegration\Events\StoreReinstalled;
use ShopGPT\ShopifyIntegration\Exceptions\OAuthException;
use ShopGPT\ShopifyIntegration\Services\OAuthService;
use ShopGPT\ShopifyIntegration\Services\StoreWriter;
use ShopGPT\ShopifyIntegration\Support\Hmac;
use ShopGPT\ShopifyIntegration\Support\InstallContext;
use ShopGPT\ShopifyIntegration\Support\OAuthState;
use ShopGPT\ShopifyIntegration\Support\ShopDomain;
use Throwable;

class OAuthController extends Controller
{
    public function __construct(
        private readonly OAuthService $oauth,
        private readonly StoreWriter $writer,
    ) {
    }

    /**
     * GET /shopify/auth/begin
     */
    public function begin(Request $request)
    {
        $shop = ShopDomain::normalise($request->query('shop'));

        if ($shop === null) {
            // Usually a person who found the URL rather than an install.
            // Sending them to the listing beats a bare 400 page.
            $listing = config('shopifyIntegration.oauth.listing_url');

            return $listing
                ? redirect()->away($listing)
                : response('A valid ?shop=your-store.myshopify.com parameter is required.', 400);
        }

        // Signed only when Shopify sent the merchant here. A "connect your
        // store" button on your own site, and every reauthorisation URL this
        // package hands out, carry no signature and cannot invent one.
        //
        // Nothing is lost by admitting them: this route only redirects to
        // Shopify's own authorize page, where the merchant still has to be
        // logged in and still has to approve. The install is secured on the
        // way back, where the signature, the single-use state and the code
        // exchange all have to line up.
        if (Hmac::isSigned($request) && ! $this->hmacVerified($request)) {
            OAuthFailed::dispatch($shop, 'hmac');

            return response('HMAC verification failed.', 401);
        }

        OAuthStarted::dispatch($shop);

        return redirect()->away($this->oauth->authorizeUrl($shop));
    }

    /**
     * GET /shopify/auth/callback
     */
    public function callback(Request $request)
    {
        $shop = ShopDomain::normalise($request->query('shop'));
        $code = $request->query('code');

        // The merchant pressed Cancel on the authorize screen. A normal
        // outcome, and a listener wants to tell it apart from a malformed
        // request — one is a person changing their mind, the other is a bug
        // or an attack.
        if (is_string($error = $request->query('error')) && $error !== '') {
            return $this->fail($shop, $error === 'access_denied' ? 'access_denied' : 'oauth error: '.$error);
        }

        if ($shop === null || ! is_string($code) || $code === '') {
            return $this->fail($shop, 'missing shop or code');
        }

        // Verified again on the way back: the callback is a separate,
        // equally public request, and the code is only useful with a
        // signature we trust.
        if (! $this->hmacVerified($request)) {
            return $this->fail($shop, 'hmac');
        }

        if (! OAuthState::matches($shop, $request->query('state'))) {
            return $this->fail($shop, 'state');
        }

        try {
            $token    = $this->oauth->exchangeCode($shop, $code);
            $shopData = $this->oauth->fetchShopData($shop, $token['access_token']);

            [$store, $isNew, $isReinstall] = $this->writer->write($shop, $token, $shopData);
        } catch (OAuthException $e) {
            return $this->fail($shop, $e->getMessage(), $e);
        } catch (Throwable $e) {
            report($e);

            return $this->fail($shop, 'unexpected error', $e);
        }

        $context = new InstallContext(
            store: $store,
            shopData: $shopData,
            isNewInstall: $isNew,
            isReinstall: $isReinstall,
            scopes: $token['scope'] ?? null,
            host: $request->query('host'),
        );

        // Your listener runs here: create the user, log them in, seed a plan.
        $isReinstall
            ? StoreReinstalled::dispatch($store, $context)
            : StoreInstalled::dispatch($store, $context);

        return $this->redirectAfterInstall($context);
    }

    /**
     * Shopify signs both the install and the callback, and the only reason to
     * skip that is opening the URL by hand in local development.
     *
     * Logged as a warning every time, because the failure mode is silent:
     * with this on, anyone who knows a store domain can install any store
     * against the app, and nothing about the request looks wrong.
     */
    private function hmacVerified(Request $request): bool
    {
        if (config('shopifyIntegration.debug')) {
            Log::warning('shopifyIntegration: HMAC verification skipped — shopifyIntegration.debug is on.');

            return true;
        }

        return Hmac::verifyRequest($request, (string) config('shopifyIntegration.client_secret'));
    }

    private function fail(?string $shop, string $reason, ?Throwable $e = null)
    {
        Log::warning('shopifyIntegration: OAuth failed', ['shop' => $shop, 'reason' => $reason]);

        OAuthFailed::dispatch($shop, $reason, $e);

        return $this->to(config('shopifyIntegration.redirects.on_failure', '/'), null);
    }

    private function redirectAfterInstall(InstallContext $context)
    {
        // An embedded app must land back inside the admin frame, not on your
        // own domain. The callback is a top-level navigation, so redirecting
        // to the app's own entry path here renders it standalone, outside
        // Shopify — the merchant installs the app and lands somewhere that
        // looks nothing like the admin they started in.
        //
        // Handing the browser back to Shopify is what puts the app in the
        // frame: Shopify loads the App URL inside the admin itself.
        if (config('shopifyIntegration.embedded.enabled')) {
            return redirect()->away($this->adminAppUrl($context));
        }

        $target = $context->isReinstall
            ? config('shopifyIntegration.redirects.after_reinstall', '/')
            : config('shopifyIntegration.redirects.after_install', '/');

        return $this->to($target, $context);
    }

    /**
     * Where the app lives inside the Shopify admin.
     *
     * `host` is Shopify's own base64url of that location and is the value to
     * prefer — it is correct for admin.shopify.com and for the older
     * per-store admin alike. It arrives on a request whose whole query string
     * is HMAC-verified above, so it is trustworthy, but it is still validated
     * before being redirected to: an unvalidated host would turn the callback
     * into an open redirect the moment the signature check is relaxed.
     */
    private function adminAppUrl(InstallContext $context): string
    {
        $clientId = (string) config('shopifyIntegration.client_id');
        $host     = $this->decodedHost($context->host) ?? $this->hostFromDomain($context->domain());

        return "https://{$host}/apps/{$clientId}";
    }

    /** @return string|null The decoded host, or null if it is not one Shopify would send. */
    private function decodedHost(?string $host): ?string
    {
        if (! is_string($host) || $host === '') {
            return null;
        }

        $decoded = base64_decode(strtr($host, '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        $shop = '[a-zA-Z0-9][a-zA-Z0-9\-]*';

        return preg_match("#^(admin\.shopify\.com/store/{$shop}|{$shop}\.myshopify\.com/admin)$#", $decoded)
            ? $decoded
            : null;
    }

    /** The fallback when `host` is absent or unusable: derive it from the store domain. */
    private function hostFromDomain(?string $domain): string
    {
        $handle = explode('.', (string) $domain)[0];

        return "admin.shopify.com/store/{$handle}";
    }

    /**
     * A redirect target may be a closure, a named route, or a plain URL.
     */
    private function to(mixed $target, ?InstallContext $context)
    {
        if ($target instanceof \Closure) {
            return $target($context);
        }

        if (is_string($target) && \Illuminate\Support\Facades\Route::has($target)) {
            return redirect()->route($target);
        }

        return redirect()->to(is_string($target) && $target !== '' ? $target : '/');
    }
}
