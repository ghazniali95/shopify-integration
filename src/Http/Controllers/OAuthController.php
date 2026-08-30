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

        if (! $this->hmacVerified($request)) {
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
     * Shopify signs both the install and the callback. The only reason to skip
     * it is hitting the URL by hand in local development, so the escape hatch
     * requires APP_DEBUG as well — it can never be switched on in production
     * by config alone.
     */
    private function hmacVerified(Request $request): bool
    {
        if (config('shopifyIntegration.oauth.skip_hmac_in_debug') && config('app.debug')) {
            Log::warning('shopifyIntegration: HMAC verification skipped (debug only).');

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
        // own domain — otherwise the merchant ends up outside Shopify.
        if (config('shopifyIntegration.embedded.enabled')) {
            $entry = '/'.ltrim((string) config('shopifyIntegration.embedded.entry', '/shopify/app'), '/');

            return redirect()->to($entry.'?'.http_build_query(array_filter([
                'shop' => $context->domain(),
                'host' => $context->host,
            ])));
        }

        $target = $context->isReinstall
            ? config('shopifyIntegration.redirects.after_reinstall', '/')
            : config('shopifyIntegration.redirects.after_install', '/');

        return $this->to($target, $context);
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
