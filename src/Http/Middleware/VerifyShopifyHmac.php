<?php

namespace ShopGPT\ShopifyIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ShopGPT\ShopifyIntegration\Support\Hmac;
use Symfony\Component\HttpFoundation\Response;

/**
 * Query-string HMAC verification for any route Shopify links to directly.
 * The package's own OAuth routes verify internally; this alias is for your
 * routes.
 */
class VerifyShopifyHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Hmac::verifyRequest($request, (string) config('shopifyIntegration.client_secret'))) {
            return response('HMAC verification failed.', 401);
        }

        return $next($request);
    }
}
