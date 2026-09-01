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

        if (! self::timestampFresh($request)) {
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
     * Whether Shopify signed this request at all.
     *
     * Shopify signs every request it sends to an app, but a merchant-initiated
     * install — a "connect your store" button on your own site — is a request
     * Shopify never saw and therefore cannot have signed. Telling the two
     * apart is what lets a route demand a valid signature when there is one
     * without rejecting the requests that legitimately have none.
     */
    public static function isSigned(Request $request): bool
    {
        $hmac = $request->query('hmac');

        return is_string($hmac) && $hmac !== '';
    }

    /**
     * Reject a signature that is correct but old.
     *
     * The digest stays valid forever, so without this a signed install URL
     * captured from a log or a browser history keeps working indefinitely.
     * Shopify stamps every signed request with `timestamp`; anything outside
     * the window is a replay, not a merchant.
     *
     * A request with no timestamp at all is left to the signature alone —
     * older Shopify surfaces omit it, and failing those closed would break
     * installs to fix a replay window that the callback's single-use state
     * already closes.
     */
    public static function timestampFresh(Request $request, ?int $maxAge = null): bool
    {
        $timestamp = $request->query('timestamp');

        if (! is_string($timestamp) && ! is_int($timestamp)) {
            return true;
        }

        if (! ctype_digit((string) $timestamp)) {
            return false;
        }

        $maxAge ??= (int) config('shopifyIntegration.oauth.hmac_ttl', 300);

        if ($maxAge <= 0) {
            return true;
        }

        // Symmetric: a timestamp far in the future is as much a forgery signal
        // as one far in the past, and clock skew accounts for neither.
        return abs(time() - (int) $timestamp) <= $maxAge;
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
