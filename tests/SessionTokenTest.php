<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Services\SessionTokenService;

class SessionTokenTest extends TestCase
{
    private const SHOP = 'acme.myshopify.com';

    private function service(): SessionTokenService
    {
        return $this->app->make(SessionTokenService::class);
    }

    /** Re-sign a tampered claims array so only the claim under test is wrong. */
    private function tokenWith(array $claims): string
    {
        return $this->service()->mint(self::SHOP, $claims);
    }

    #[Test]
    public function a_well_formed_token_verifies(): void
    {
        $claims = $this->service()->verify($this->service()->mint(self::SHOP));

        $this->assertIsArray($claims);
        $this->assertSame('https://'.self::SHOP, $claims['dest']);
        $this->assertSame(self::SHOP, $this->service()->shopFromClaims($claims));
    }

    #[Test]
    public function a_token_signed_with_the_wrong_secret_is_rejected(): void
    {
        $token = $this->service()->mint(self::SHOP);

        config(['shopifyIntegration.client_secret' => 'someone-elses-secret']);

        $this->assertNull($this->service()->verify($token));
    }

    #[Test]
    public function a_tampered_payload_is_rejected(): void
    {
        [$header, $payload, $signature] = explode('.', $this->service()->mint(self::SHOP));

        $forged = rtrim(strtr(base64_encode(json_encode([
            'dest' => 'https://victim.myshopify.com',
        ])), '+/', '-_'), '=');

        $this->assertNull($this->service()->verify("{$header}.{$forged}.{$signature}"));
    }

    #[Test]
    public function an_expired_token_is_rejected(): void
    {
        $this->assertNull($this->service()->verify($this->tokenWith([
            'exp' => time() - 60,
            'nbf' => time() - 120,
        ])));
    }

    #[Test]
    public function clock_skew_within_the_leeway_is_tolerated(): void
    {
        $this->assertIsArray($this->service()->verify($this->tokenWith([
            'exp' => time() - 5,
        ])));
    }

    #[Test]
    public function a_not_yet_valid_token_is_rejected(): void
    {
        $this->assertNull($this->service()->verify($this->tokenWith([
            'nbf' => time() + 120,
        ])));
    }

    /**
     * Shopify signs every app's tokens with that app's own secret, so this is
     * belt and braces — but it is the claim that makes a valid signature mean
     * "minted for you" rather than merely "minted by Shopify".
     */
    #[Test]
    public function a_token_for_a_different_app_is_rejected(): void
    {
        $this->assertNull($this->service()->verify($this->tokenWith([
            'aud' => 'a-different-apps-client-id',
        ])));
    }

    #[Test]
    public function a_forged_issuer_is_rejected(): void
    {
        $this->assertNull($this->service()->verify($this->tokenWith([
            'iss' => 'https://attacker.example.com/admin',
        ])));
    }

    #[Test]
    public function a_non_shopify_destination_is_rejected(): void
    {
        $this->assertNull($this->service()->verify($this->tokenWith([
            'dest' => 'https://attacker.example.com',
        ])));
    }

    /**
     * The check hand-rolled implementations miss. The store is resolved from
     * `dest`, so a token issued for one shop naming another as its
     * destination must never authenticate.
     */
    #[Test]
    public function a_token_whose_issuer_and_destination_disagree_is_rejected(): void
    {
        $this->assertNull($this->service()->verify($this->tokenWith([
            'iss'  => 'https://attacker.myshopify.com/admin',
            'dest' => 'https://victim.myshopify.com',
        ])));
    }

    #[Test]
    public function the_none_algorithm_is_rejected(): void
    {
        $encode = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        $header  = $encode(['alg' => 'none', 'typ' => 'JWT']);
        $payload = $encode([
            'iss'  => 'https://'.self::SHOP.'/admin',
            'dest' => 'https://'.self::SHOP,
            'aud'  => 'test-client-id',
            'exp'  => time() + 60,
        ]);

        $this->assertNull($this->service()->verify("{$header}.{$payload}."));
    }

    #[Test]
    public function malformed_input_is_rejected(): void
    {
        foreach ([null, '', 'not-a-jwt', 'a.b', 'a.b.c.d', '...', 'x.y.z'] as $input) {
            $this->assertNull($this->service()->verify($input), "accepted: ".var_export($input, true));
        }
    }

    #[Test]
    public function a_token_arrives_as_a_bearer_header_or_an_id_token_parameter(): void
    {
        $service = $this->service();

        $bearer = \Illuminate\Http\Request::create('/x', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer abc',
        ]);
        $this->assertSame('abc', $service->fromRequest($bearer));

        $query = \Illuminate\Http\Request::create('/x', 'GET', ['id_token' => 'xyz']);
        $this->assertSame('xyz', $service->fromRequest($query));

        $this->assertNull($service->fromRequest(\Illuminate\Http\Request::create('/x')));
    }
}
