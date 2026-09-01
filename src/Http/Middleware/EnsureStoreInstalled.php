<?php

namespace ShopGPT\ShopifyIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ShopGPT\ShopifyIntegration\Facades\ShopifyIntegration;
use ShopGPT\ShopifyIntegration\Support\ShopDomain;
use ShopGPT\ShopifyIntegration\Support\StoreState;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards routes that need a working store behind them.
 *
 * Sends the merchant back through OAuth for three separate reasons, and the
 * third is the one that is easy to miss: adding a scope to config does not
 * change tokens Shopify has already issued. Without this check the merchant
 * keeps a token that lacks the new scope and hits a 403 at whichever call
 * site happened to need it — which reads as a random bug rather than
 * "this app needs re-authorising".
 */
class EnsureStoreInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = ShopifyIntegration::currentStore()
            ?? $this->fromRequest($request);

        if (! $store || ! $store->shopifyIsInstalled() || ! StoreState::hasValidToken($store)) {
            return $this->reauthorize($request, $store?->shopifyDomain());
        }

        if (! StoreState::hasRequiredScopes($store)) {
            return $this->reauthorize($request, $store->shopifyDomain());
        }

        ShopifyIntegration::setCurrentStore($store);

        return $next($request);
    }

    private function fromRequest(Request $request)
    {
        $shop = ShopDomain::normalise($request->query('shop'));

        return $shop ? ShopifyIntegration::forDomain($shop) : null;
    }

    private function reauthorize(Request $request, ?string $shop): Response
    {
        $shop ??= ShopDomain::normalise($request->query('shop'));

        if (! $shop) {
            return response('No Shopify store for this request.', 403);
        }

        $prefix = trim((string) config('shopifyIntegration.routes.prefix', 'shopify'), '/');
        $target = "/{$prefix}/auth/begin?shop={$shop}";

        // An XHR cannot follow a redirect out of the admin iframe — the front
        // end has to break out of the frame itself, so tell it plainly.
        if ($request->expectsJson()) {
            return response()->json(['error' => 'reauthorization_required', 'url' => $target], 401);
        }

        return redirect()->to($target);
    }
}
