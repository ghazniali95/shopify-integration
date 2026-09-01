<?php

namespace ShopGPT\ShopifyIntegration\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ShopGPT\ShopifyIntegration\Events\OAuthFailed;
use ShopGPT\ShopifyIntegration\Events\StoreInstalled;
use ShopGPT\ShopifyIntegration\Events\StoreReinstalled;
use ShopGPT\ShopifyIntegration\Events\StoreTokenExchanged;
use ShopGPT\ShopifyIntegration\Contracts\ShopifyStore;
use ShopGPT\ShopifyIntegration\Support\InstallContext;
use Throwable;

/**
 * Turns a session token into an access token, with no merchant-visible
 * redirect.
 *
 * This is what lets a modern embedded app skip the OAuth round trip on
 * install, and it doubles as the recovery path: when a token is revoked, the
 * next request quietly obtains a new one instead of bouncing the merchant out
 * of the admin to re-approve an app they never uninstalled.
 */
class TokenExchangeService
{
    public function __construct(
        private readonly OAuthService $oauth,
        private readonly StoreWriter $writer,
    ) {
    }

    /**
     * Exchange a *verified* session token for an offline access token and
     * persist the store.
     *
     * The caller must have verified the session token first — this method
     * trusts the shop it is handed. Returns null when Shopify declines, which
     * means the app is genuinely not installed for that store and the merchant
     * has to go through OAuth.
     */
    public function exchange(string $shop, string $sessionToken): ?ShopifyStore
    {
        $token = $this->requestToken($shop, $sessionToken);

        if ($token === null) {
            OAuthFailed::dispatch($shop, 'token_exchange');

            return null;
        }

        $shopData = $this->oauth->fetchShopData($shop, $token['access_token']);

        [$store, $isNewInstall, $isReinstall] = $this->writer->write($shop, $token, $shopData);

        StoreTokenExchanged::dispatch($store);

        // An exchange that was also the install has to raise the same events
        // the redirect flow raises, or an app's onboarding would simply never
        // run for merchants who installed from the Shopify App Store.
        if ($isNewInstall || $isReinstall) {
            $context = new InstallContext(
                store: $store,
                shopData: $shopData,
                isNewInstall: $isNewInstall,
                isReinstall: $isReinstall,
                viaTokenExchange: true,
                scopes: $token['scope'] ?? null,
            );

            $isReinstall
                ? StoreReinstalled::dispatch($store, $context)
                : StoreInstalled::dispatch($store, $context);
        }

        return $store;
    }

    /**
     * `expiring=1` asks for a rotating offline token, so the response carries
     * a refresh_token and expires_in. Offline is the only token type worth
     * requesting here: an online token dies with the staff member's session
     * and would be useless to a queued job.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, scope: ?string}|null
     */
    private function requestToken(string $shop, string $sessionToken): ?array
    {
        try {
            $response = Http::asForm()
                ->timeout(15)
                ->retry(2, 1000, throw: false)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id'            => config('shopifyIntegration.client_id'),
                    'client_secret'        => config('shopifyIntegration.client_secret'),
                    'grant_type'           => 'urn:ietf:params:oauth:grant-type:token-exchange',
                    'subject_token'        => $sessionToken,
                    'subject_token_type'   => 'urn:ietf:params:oauth:token-type:id_token',
                    'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                    'expiring'             => '1',
                ]);
        } catch (Throwable $e) {
            Log::warning('shopifyIntegration: token exchange failed', [
                'store'  => $shop,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed() || ! $response->json('access_token')) {
            Log::warning('shopifyIntegration: token exchange rejected', [
                'store'  => $shop,
                'status' => $response->status(),
                // Shopify names the reason here — invalid_subject_token for a
                // stale session token, invalid_grant when the app really is
                // not installed. Worth having in the log; never in a response.
                'error'  => $response->json('error'),
            ]);

            return null;
        }

        return [
            'access_token'  => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in'    => $response->json('expires_in'),
            'scope'         => $response->json('scope'),
        ];
    }
}
