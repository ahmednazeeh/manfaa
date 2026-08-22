<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Api;

use Manfaa\Cashback\Support\Crypto;
use Manfaa\Cashback\Support\Log;
use Manfaa\Cashback\Support\Options;
use Manfaa\Cashback\Webhooks\Receiver;

/**
 * "Connect with Manfaa" — OAuth 2.0 authorization code with PKCE, as a
 * PUBLIC client: no secret, the callback is this site's own settings page,
 * and the shopkeeper sees "This will connect shop.example.mv" on Manfaa's
 * consent screen before approving.
 *
 * Sequence: {@see beginUrl()} stores the PKCE verifier under a one-time
 * state and sends the browser to the consent screen → Manfaa sends it back
 * to {@see callbackUrl()} with `code` + `state` → {@see complete()} swaps
 * the code for the token, reads `/v1/me`, syncs the rate card and registers
 * this site's webhook. A complete setup with nothing copied by hand.
 */
final class Connect
{
    public const SCOPES = ['transactions:write', 'transactions:reverse', 'rates:read', 'customers:lookup', 'webhooks:manage'];

    public const PROFILE_OPTION = 'manfaa_cashback_profile';

    private const STATE_TTL = 10 * MINUTE_IN_SECONDS;

    public static function callbackUrl(): string
    {
        return admin_url('admin.php?page=manfaa-cashback&manfaa-callback=1');
    }

    /** Start: mints state + verifier, returns the consent-screen URL. */
    public static function beginUrl(int $userId): string
    {
        $state = wp_generate_password(32, false);
        $verifier = self::base64url(random_bytes(48)); // 64 chars, within 43–128

        set_transient('manfaa_cashback_pkce_'.$state, ['verifier' => $verifier, 'user' => $userId], self::STATE_TTL);

        $challenge = self::base64url(hash('sha256', $verifier, true));

        return rtrim(Options::string('panel_base_url'), '/').'/connect?'.http_build_query([
            'client_id' => Options::string('client_id'),
            'redirect_uri' => self::callbackUrl(),
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Finish: the browser is back with `code` and `state`.
     *
     * @return array<string, mixed>  the `/v1/me` profile
     *
     * @throws ConnectException
     */
    public static function complete(string $code, string $state, int $userId): array
    {
        $pkce = get_transient('manfaa_cashback_pkce_'.$state);
        delete_transient('manfaa_cashback_pkce_'.$state);

        if (! is_array($pkce) || (int) ($pkce['user'] ?? 0) !== $userId) {
            throw new ConnectException(__('This connection attempt has expired or was started by someone else. Please press Connect with Manfaa again.', 'manfaa-cashback'));
        }

        try {
            $issued = Client::postPublic('v1/connect/token', [
                'grant_type' => 'authorization_code',
                'client_id' => Options::string('client_id'),
                'code' => $code,
                'redirect_uri' => self::callbackUrl(),
                'code_verifier' => (string) $pkce['verifier'],
            ]);
        } catch (ApiException $e) {
            Log::warning('Connect exchange refused', ['code' => $e->errorCode]);
            throw new ConnectException(sprintf(
                /* translators: %s: the reason Manfaa gave */
                __('Manfaa did not accept the connection: %s', 'manfaa-cashback'),
                $e->getMessage(),
            ));
        }

        $token = (string) ($issued['access_token'] ?? '');

        if ($token === '') {
            throw new ConnectException(__('Manfaa answered without a token. Please try again.', 'manfaa-cashback'));
        }

        Client::storeToken($token);

        return self::refreshProfile(new Client($token));
    }

    /** Paste-a-token path: store it, then prove it with `/v1/me`. */
    public static function adoptToken(string $token): array
    {
        $client = new Client(trim($token));
        $profile = $client->get('v1/me');

        Client::storeToken(trim($token));

        return self::refreshProfile($client, $profile);
    }

    /**
     * `/v1/me` → stored profile (store name, abilities, connected_from), plus
     * a rate-card sync and the webhook registration — each best-effort, so a
     * store missing an ability still ends up connected and told what it lacks.
     *
     * @param  array<string, mixed>|null  $profile
     * @return array<string, mixed>
     */
    public static function refreshProfile(Client $client, ?array $profile = null): array
    {
        $profile ??= $client->get('v1/me');

        $abilities = array_values(array_map('strval', (array) ($profile['credential']['abilities'] ?? [])));

        $stored = [
            'merchant_id' => (int) ($profile['merchant']['id'] ?? 0),
            'merchant_name' => (string) ($profile['merchant']['name'] ?? ''),
            'merchant_status' => (string) ($profile['merchant']['status'] ?? ''),
            'currency' => (string) ($profile['merchant']['currency'] ?? 'MVR'),
            'abilities' => $abilities,
            'connected_from' => $profile['credential']['connected_from'] ?? null,
            'label' => $profile['credential']['label'] ?? null,
            'connected_at' => time(),
            'rate' => $profile['rate'] ?? null,
        ];

        update_option(self::PROFILE_OPTION, $stored, false);

        if (in_array('rates:read', $abilities, true)) {
            try {
                RateCard::sync($client);
            } catch (ApiException $e) {
                Log::warning('Rate sync after connect failed', ['code' => $e->errorCode]);
            }
        }

        if (in_array('webhooks:manage', $abilities, true)) {
            try {
                Receiver::register($client);
            } catch (ApiException $e) {
                Log::warning('Webhook registration after connect failed', ['code' => $e->errorCode]);
            }
        }

        return $stored;
    }

    /** @return array<string, mixed>|null */
    public static function profile(): ?array
    {
        $profile = get_option(self::PROFILE_OPTION);

        return is_array($profile) ? $profile : null;
    }

    public static function hasAbility(string $ability): bool
    {
        return in_array($ability, (array) (self::profile()['abilities'] ?? []), true);
    }

    /** Forget the token, the profile, the webhook secret. Manfaa-side revocation is the merchant's, from the panel. */
    public static function disconnect(): void
    {
        $client = Client::fromSettings();

        if ($client->connected() && self::hasAbility('webhooks:manage')) {
            try {
                Receiver::unregister($client);
            } catch (ApiException) {
                // Best effort: the endpoint dies with the credential when
                // the merchant revokes it in the panel anyway.
            }
        }

        Client::storeToken(null);
        delete_option(self::PROFILE_OPTION);
        Receiver::forgetSecret();
        RateCard::forget();
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
