<?php

namespace ShopGPT\ShopifyIntegration\Services;

use Illuminate\Http\Request;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Support\ShopDomain;

/**
 * App Bridge session tokens (HS256 JWTs).
 *
 * A session token is the only thing an embedded app has to prove who is
 * calling: the iframe has no usable cookie, so this signature *is* the
 * authentication. Every claim below is therefore mandatory — a partial check
 * here is an authentication bypass, not a missing nicety.
 *
 * A session token never talks to the Admin API. It proves a merchant is
 * looking at your app inside their admin; the access token does the work.
 */
class SessionTokenService
{
    /** Tokens live about a minute; the leeway only absorbs clock skew. */
    private const LEEWAY = 10;

    private const ISSUER  = '#^https://([a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com)/admin$#';
    private const DEST    = '#^https://([a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com)$#';

    /**
     * Where a session token can arrive from.
     *
     * XHR sends it as a bearer token. The document request that boots the
     * iframe carries it as `id_token` on the query string instead, which is
     * how a page load can authenticate before App Bridge has even booted.
     */
    public function fromRequest(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $idToken = $request->query('id_token');

        return is_string($idToken) && $idToken !== '' ? $idToken : null;
    }

    /**
     * Verify a session token and return its claims, or null if anything at
     * all is wrong. Null is deliberately undifferentiated: the caller has no
     * decision to make beyond "not authenticated", and saying *why* a token
     * failed tells an attacker which part of the forgery to fix.
     *
     * @return array<string, mixed>|null
     */
    public function verify(?string $jwt): ?array
    {
        if (! is_string($jwt) || substr_count($jwt, '.') !== 2) {
            return null;
        }

        [$header, $payload, $signature] = explode('.', $jwt);

        // Pin the algorithm. The signature comparison below would already
        // reject `alg: none`, but stating the requirement is what stops a
        // later refactor from quietly reintroducing the classic JWT bypass.
        if (($this->decodeSegment($header)['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $expected = $this->sign("{$header}.{$payload}");

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $claims = $this->decodeSegment($payload);

        if (! is_array($claims) || $claims === []) {
            return null;
        }

        return $this->claimsValid($claims) ? $claims : null;
    }

    /**
     * The store a verified token speaks for, as a bare domain.
     *
     * Read from `dest`, never `iss` — see the same-shop check in claimsValid().
     */
    public function shopFromClaims(?array $claims): ?string
    {
        if (! is_array($claims) || ! preg_match(self::DEST, (string) ($claims['dest'] ?? ''), $m)) {
            return null;
        }

        return ShopDomain::normalise($m[1]);
    }

    /**
     * Mint a token the way Shopify does — test support, so an app consuming
     * this package can exercise its own embedded routes without stubbing out
     * the verification it most wants covered.
     */
    public function mint(string $shop, array $overrides = []): string
    {
        $now = time();

        $claims = array_merge([
            'iss'  => "https://{$shop}/admin",
            'dest' => "https://{$shop}",
            'aud'  => (string) config('shopifyIntegration.client_id'),
            'sub'  => '1',
            'exp'  => $now + 60,
            'nbf'  => $now,
            'iat'  => $now,
            'jti'  => bin2hex(random_bytes(16)),
            'sid'  => bin2hex(random_bytes(16)),
        ], $overrides);

        $header  = $this->encodeSegment(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $this->encodeSegment($claims);

        return "{$header}.{$payload}.".$this->sign("{$header}.{$payload}");
    }

    /** Headers an authenticated XHR from the admin iframe would carry. */
    public function headersFor(ShopifyStore|string $store, array $overrides = []): array
    {
        $shop = $store instanceof ShopifyStore ? $store->shopifyDomain() : $store;

        return ['Authorization' => 'Bearer '.$this->mint($shop, $overrides)];
    }

    private function claimsValid(array $claims): bool
    {
        $now = time();

        // Expired. Routine rather than hostile — tokens last about a minute,
        // so a client that sat on one just needs to mint a fresh one.
        if (! isset($claims['exp']) || (int) $claims['exp'] < ($now - self::LEEWAY)) {
            return false;
        }

        if (isset($claims['nbf']) && (int) $claims['nbf'] > ($now + self::LEEWAY)) {
            return false;
        }

        // Minted for a different app. Shopify signs every app's tokens with
        // that app's own secret, so this rarely fires — but it is the claim
        // that makes the signature mean "for you" and not merely "by Shopify".
        if (! hash_equals((string) config('shopifyIntegration.client_id'), (string) ($claims['aud'] ?? ''))) {
            return false;
        }

        if (! preg_match(self::ISSUER, (string) ($claims['iss'] ?? ''), $iss)) {
            return false;
        }

        if (! preg_match(self::DEST, (string) ($claims['dest'] ?? ''), $dest)) {
            return false;
        }

        // The check hand-rolled implementations miss. The store is resolved
        // from `dest`, so a token whose `iss` and `dest` name different shops
        // would let a merchant authenticated for store A act on store B.
        return $iss[1] === $dest[1];
    }

    private function sign(string $signingInput): string
    {
        return $this->base64UrlEncode(hash_hmac(
            'sha256',
            $signingInput,
            (string) config('shopifyIntegration.client_secret'),
            true,
        ));
    }

    private function decodeSegment(string $segment): ?array
    {
        $json = base64_decode(strtr($segment, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function encodeSegment(array $data): string
    {
        return $this->base64UrlEncode((string) json_encode($data));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
