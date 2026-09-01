<?php

namespace ShopGPT\ShopifyIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ShopGPT\ShopifyIntegration\Facades\ShopifyIntegration;
use ShopGPT\ShopifyIntegration\Http\Middleware\Concerns\ResolvesEmbeddedStore;
use ShopGPT\ShopifyIntegration\Services\SessionTokenService;
use ShopGPT\ShopifyIntegration\Support\ShopDomain;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the page loads that render inside the Shopify Admin iframe.
 *
 * Alias: shopifyIntegration.embedded
 *
 * Shopify appends `id_token` to the iframe URL, so the document request can
 * usually authenticate before App Bridge has booted. When it cannot, a valid
 * `shop` parameter with an install behind it is enough to render the host
 * page; App Bridge then mints tokens for everything the page fetches.
 */
class EnsureEmbedded
{
    use ResolvesEmbeddedStore;

    public function __construct(
        private readonly SessionTokenService $sessions,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token  = $this->sessions->fromRequest($request);
        $claims = $token !== null ? $this->sessions->verify($token) : null;

        $shop = $claims !== null
            ? $this->sessions->shopFromClaims($claims)
            : ShopDomain::normalise($request->query('shop'));

        if ($shop === null) {
            // No token, no shop: almost always a person who opened the app
            // URL directly rather than through the admin.
            $listing = config('shopifyIntegration.oauth.listing_url');

            return $listing
                ? redirect()->away($listing)
                : response('This page opens inside the Shopify admin.', 400);
        }

        $store = $claims !== null && $token !== null
            ? $this->storeFor($shop, $token)
            : $this->installed($shop);

        if ($store === null || ! $store->hasRequiredScopes()) {
            return $this->beginOAuth($request, $shop);
        }

        ShopifyIntegration::setCurrentStore($store);

        if ($claims !== null) {
            $request->attributes->set('shopifyIntegration.claims', $claims);
        }

        return $this->allowFraming($next($request), $shop);
    }

    /** A store good enough to render the page for, without a session token. */
    private function installed(string $shop)
    {
        $store = app(\ShopGPT\ShopifyIntegration\Contracts\ShopifyStoreRepository::class)->findByDomain($shop);

        return $this->usable($store) ? $store : null;
    }

    /**
     * Shopify's authorize page refuses to be framed, so a plain redirect from
     * inside the iframe renders an empty box with a console error and nothing
     * else. The app has to break out to the top window itself.
     */
    private function beginOAuth(Request $request, string $shop): Response
    {
        $prefix = trim((string) config('shopifyIntegration.routes.prefix', 'shopify'), '/');
        $url    = rtrim((string) config('app.url'), '/')."/{$prefix}/auth/begin?shop={$shop}";

        // A request Shopify framed carries either of these; a direct browser
        // hit carries neither and can simply be redirected.
        $framed = $request->query('embedded') === '1' || $request->query('host') !== null;

        return $framed ? $this->exitIframe($url) : redirect()->to($url);
    }

    /**
     * The smallest page that escapes the frame. Deliberately dependency-free:
     * it has to work before App Bridge has loaded, which is exactly the case
     * where the app is not installed and no bundle is worth booting.
     */
    private function exitIframe(string $url): Response
    {
        $js   = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
        <!doctype html>
        <meta charset="utf-8">
        <title>Redirecting…</title>
        <script>
            var target = {$js};
            if (window.top === window.self) { window.location.href = target; }
            else { window.top.location.href = target; }
        </script>
        <noscript><a href="{$href}" target="_top">Continue to install this app</a></noscript>
        HTML;

        // Not a redirect status: the browser would follow it inside the frame,
        // which is the thing being worked around.
        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Shopify requires an embedded app to name its framers explicitly. Laravel
     * sends no CSP of its own, but a global X-Frame-Options — from a security
     * package, or a reverse proxy — silently blanks the app inside the admin,
     * so it is cleared here rather than left to chance.
     */
    private function allowFraming(Response $response, string $shop): Response
    {
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors https://{$shop} https://admin.shopify.com;",
        );

        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
