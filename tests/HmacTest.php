<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Support\Hmac;

class HmacTest extends TestCase
{
    private function request(array $params): Request
    {
        return Request::create('/shopify/auth/begin', 'GET', $params);
    }

    private function sign(array $params, string $secret = 'test-secret'): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }

        return hash_hmac('sha256', implode('&', $pairs), $secret);
    }

    #[Test]
    public function it_verifies_a_correctly_signed_request(): void
    {
        $params = ['shop' => 'acme.myshopify.com', 'timestamp' => '1700000000'];
        $params['hmac'] = $this->sign($params);

        $this->assertTrue(Hmac::verifyRequest($this->request($params), 'test-secret'));
    }

    #[Test]
    public function it_rejects_a_tampered_parameter(): void
    {
        $params = ['shop' => 'acme.myshopify.com', 'timestamp' => '1700000000'];
        $params['hmac'] = $this->sign($params);
        $params['shop'] = 'attacker.myshopify.com';

        $this->assertFalse(Hmac::verifyRequest($this->request($params), 'test-secret'));
    }

    #[Test]
    public function it_rejects_a_missing_hmac(): void
    {
        $this->assertFalse(Hmac::verifyRequest($this->request(['shop' => 'acme.myshopify.com']), 'test-secret'));
    }

    /**
     * The regression that matters: http_build_query() would percent-encode the
     * slash and space here and produce a different digest from Shopify's.
     */
    #[Test]
    public function it_signs_values_raw_rather_than_url_encoded(): void
    {
        $params = [
            'shop'   => 'acme.myshopify.com',
            'path'   => 'admin/products',
            'note'   => 'hello world',
        ];
        $params['hmac'] = $this->sign($params);

        $this->assertTrue(Hmac::verifyRequest($this->request($params), 'test-secret'));

        // Prove the encoded form really is different, so this test has teeth.
        $encoded = hash_hmac('sha256', http_build_query([
            'note' => 'hello world', 'path' => 'admin/products', 'shop' => 'acme.myshopify.com',
        ]), 'test-secret');

        $this->assertNotSame($encoded, $params['hmac']);
    }

    #[Test]
    public function it_verifies_a_webhook_against_the_raw_body(): void
    {
        $body = '{"id":123,"title":"A product"}';
        $sig  = base64_encode(hash_hmac('sha256', $body, 'test-secret', true));

        $this->assertTrue(Hmac::verifyWebhook($body, $sig, 'test-secret'));
        $this->assertFalse(Hmac::verifyWebhook($body.' ', $sig, 'test-secret'));
        $this->assertFalse(Hmac::verifyWebhook($body, null, 'test-secret'));
    }
}
