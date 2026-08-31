<?php

namespace ShopGPT\ShopifyIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ShopGPT\ShopifyIntegration\Facades\ShopifyIntegration;
use ShopGPT\ShopifyIntegration\Http\Middleware\Concerns\ResolvesEmbeddedStore;
use ShopGPT\ShopifyIntegration\Services\SessionTokenService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates XHR coming from inside the Shopify Admin iframe.
 *
 * Alias: shopifyIntegration.session
 *
 * The two failure modes are answered with different statuses on purpose,
 * because the front end can only recover from one of them by itself:
 *
 *   401 — the token is missing, malformed or expired. Session tokens last
 *         about a minute, so this is routine. Mint a fresh one and retry.
 *   403 — the token was good but the app has no usable install for that
 *         store. No retry will fix it; the merchant has to be sent through
 *         OAuth, at the top level, out of the frame.
 *
 * Collapsing both into 401 is what produces the retry loop that embedded apps
 * are notorious for: the client keeps minting perfectly valid tokens for a
 * store that was uninstalled.
 */
class VerifySessionToken
{
    use ResolvesEmbeddedStore;

    public function __construct(
        private readonly SessionTokenService $sessions,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->sessions->fromRequest($request);

        if ($token === null) {
            return $this->deny('session_token_missing', 401);
        }

        $claims = $this->sessions->verify($token);

        if ($claims === null) {
            return $this->deny('session_token_invalid', 401);
        }

        $shop = $this->sessions->shopFromClaims($claims);

        if ($shop === null) {
            return $this->deny('session_token_invalid', 401);
        }

        $store = $this->storeFor($shop, $token);

        if ($store === null || ! $store->hasRequiredScopes()) {
            return $this->reauthorize($shop);
        }

        ShopifyIntegration::setCurrentStore($store);

        // The claims carry `sub` (the staff member) and `sid` (their browser
        // session). The package has no use for either, but an app doing
        // per-user auditing does, so they are left where a handler can read
        // them rather than being dropped on the floor.
        $request->attributes->set('shopifyIntegration.claims', $claims);

        return $next($request);
    }

    private function deny(string $error, int $status): Response
    {
        return response()->json(['error' => $error], $status);
    }

    private function reauthorize(string $shop): Response
    {
        $prefix = trim((string) config('shopifyIntegration.routes.prefix', 'shopify'), '/');

        return response()->json([
            'error' => 'reauthorization_required',
            'url'   => rtrim((string) config('app.url'), '/')."/{$prefix}/auth/begin?shop={$shop}",
        ], 403);
    }
}
