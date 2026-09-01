<?php

namespace ShopGPT\ShopifyIntegration\Contracts;

use DateTimeInterface;

/**
 * One connected store, as this package needs to read it.
 *
 * Implemented by whatever your app already uses to hold a Shopify connection.
 * The package never learns where these values are stored, what the columns
 * are called, or what else the row carries — only how to ask for the six
 * facts it cannot work without.
 *
 * Methods are prefixed because implementations are usually existing Eloquent
 * models with relations and attributes of their own; `shopifyScopes()` cannot
 * collide with anything, `scopes()` might.
 */
interface ShopifyStore
{
    /** Primary key. Queued webhook jobs carry this rather than the object. */
    public function getKey();

    /** The myshopify.com domain. The store's address to Shopify. */
    public function shopifyDomain(): string;

    /** Shopify's own numeric shop id, if known. Survives a domain rename. */
    public function shopifyExternalId(): ?string;

    /** Decrypted, ready to put in an X-Shopify-Access-Token header. */
    public function shopifyAccessToken(): ?string;

    /** Decrypted. Null for a legacy permanent token. */
    public function shopifyRefreshToken(): ?string;

    /** Null for a legacy permanent token, which never expires. */
    public function shopifyTokenExpiresAt(): ?DateTimeInterface;

    /** Comma-separated, as Shopify returns them. */
    public function shopifyScopes(): ?string;

    /** False once the merchant has removed the app. */
    public function shopifyIsInstalled(): bool;
}
