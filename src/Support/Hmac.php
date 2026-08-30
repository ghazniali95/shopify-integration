<?php

namespace ShopGPT\ShopifyIntegration\Support;

use Illuminate\Http\Request;

class Hmac
{
    /**
     * Verify the HMAC on an OAuth entry-point request.
     *
     * Shopify signs the query string as key=value pairs sorted by key and
     * joined with '&'. This is NOT http_build_query(), which percent-encodes
     * values and produces a different digest for any parameter containing a
     * space, slash or comma — the classic reason a hand-rolled check passes
     * in testing and fails on a real install.
     */
    public static function verifyRequest(Request $request, string $secret): bool
    {
        $provided = $request->query('hmac');

        if (! is_string($provided) || $provided === '' || $secret === '') {
            return false;
        }

        $params = $request->query();
        unset($params['hmac'], $params['signature']);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key.'='.(is_array($value) ? implode(',', $value) : $value);
        }

        $computed = hash_hmac('sha256', implode('&', $pairs), $secret);

        return hash_equals($computed, $provided);
    }

    /**
     * Verify a webhook signature against the RAW request body. Re-encoding a
     * decoded payload changes the bytes and the digest with it.
     */
    public static function verifyWebhook(string $rawBody, ?string $header, string $secret): bool
    {
        if (! is_string($header) || $header === '' || $secret === '') {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($computed, $header);
    }
}
