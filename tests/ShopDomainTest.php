<?php

namespace ShopGPT\ShopifyIntegration\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ShopGPT\ShopifyIntegration\Support\ShopDomain;

class ShopDomainTest extends TestCase
{
    #[Test]
    #[DataProvider('validDomains')]
    public function it_accepts_real_shopify_hosts(string $input, string $expected): void
    {
        $this->assertSame($expected, ShopDomain::normalise($input));
    }

    public static function validDomains(): array
    {
        return [
            'bare'           => ['acme.myshopify.com', 'acme.myshopify.com'],
            'https'          => ['https://acme.myshopify.com', 'acme.myshopify.com'],
            'trailing slash' => ['https://acme.myshopify.com/', 'acme.myshopify.com'],
            'admin path'     => ['acme.myshopify.com/admin', 'acme.myshopify.com'],
            'mixed case'     => ['ACME.MyShopify.com', 'acme.myshopify.com'],
            'hyphens'        => ['my-test-store.myshopify.com', 'my-test-store.myshopify.com'],
        ];
    }

    #[Test]
    #[DataProvider('attackerDomains')]
    public function it_rejects_anything_that_is_not_a_shopify_host(?string $input): void
    {
        $this->assertNull(ShopDomain::normalise($input));
    }

    public static function attackerDomains(): array
    {
        return [
            'null'              => [null],
            'empty'             => [''],
            'plain evil'        => ['evil.com'],
            'suffix trick'      => ['evil-myshopify.com'],
            'subdomain trick'   => ['acme.myshopify.com.evil.com'],
            'prefix trick'      => ['evil.com/acme.myshopify.com'],
            'at trick'          => ['acme.myshopify.com@evil.com'],
            'leading dot'       => ['.myshopify.com'],
            'leading hyphen'    => ['-acme.myshopify.com'],
            'underscore'        => ['acme_store.myshopify.com'],
        ];
    }
}
