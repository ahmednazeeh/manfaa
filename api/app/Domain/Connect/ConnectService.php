<?php

declare(strict_types=1);

namespace App\Domain\Connect;

use App\Domain\Credentials\CredentialCapReachedException;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\IssuedCredential;
use App\Domain\Credentials\VendorAbility;
use App\Domain\Webhooks\EndpointUrlGuard;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\OauthAuthorizationCode;
use App\Models\PosVendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The connect handshake: "IsleBooks would like to … Authorise / Deny".
 *
 * A shopkeeper approves a platform on a Manfaa screen, and that platform's
 * server collects the token from us directly. Nobody copies a key between
 * two windows — which is worth more than it sounds, because a key that is
 * never displayed cannot be pasted into a support chat or a screenshot.
 *
 * Only a superadmin-registered platform can start this. That gate is the
 * whole difference between "an app the merchant chose" and "anyone who can
 * write a consent screen".
 *
 * The token does NOT expire (owner decision). Revocation is therefore the
 * only control, so it is built to be the good one: a single live grant per
 * platform-and-merchant pair, replaced rather than stacked on
 * re-authorisation, and visible to the merchant.
 *
 * PUBLIC CLIENTS (owner decision 2026-08-22, for the WooCommerce plugin):
 * the same handshake for software that cannot keep a secret. No secret is
 * checked — PKCE is the proof — and no callback list exists: the plugin
 * sends its own callback, the shopkeeper sees its host on the consent
 * screen, and their approval binds that exact URL into the code. The grant
 * remembers the callback's origin (`connected_from`), and "one live grant
 * per pair" becomes one per pair AND origin, so a merchant with two stores
 * keeps both.
 */
final readonly class ConnectService
{
    /** Long enough for a redirect and a server round trip; no longer. */
    private const int CODE_TTL_SECONDS = 60;

    public function __construct(private CredentialService $credentials) {}

    /** The platform asking, resolved from its public id. */
    public function client(string $clientId): PosVendor
    {
        $vendor = PosVendor::query()->where('client_id', $clientId)->first();

        if ($vendor === null || ! $vendor->canConnect()) {
            throw ConnectException::unknownClient();
        }

        return $vendor;
    }

    /**
     * The permissions being asked for, checked against BOTH the closed set
     * of abilities and the ceiling a superadmin set for this platform.
     *
     * Resolved before a shopkeeper is shown anything: an application asking
     * for something it may not have is the platform's bug, and nobody
     * should be invited to approve it.
     *
     * @param  list<string>  $scopes
     * @return list<VendorAbility>
     */
    public function abilities(PosVendor $vendor, array $scopes): array
    {
        $permitted = (array) ($vendor->allowed_abilities ?? []);
        $resolved = [];

        foreach ($scopes as $scope) {
            $ability = VendorAbility::tryFrom($scope)
                ?? throw ConnectException::unknownScope($scope);

            if (! in_array($ability->value, $permitted, true)) {
                throw ConnectException::scopeNotPermitted($ability->value);
            }

            $resolved[$ability->value] = $ability;
        }

        if ($resolved === []) {
            throw ConnectException::noScope();
        }

        return array_values($resolved);
    }

    /**
     * Whether this shop can take on another connection.
     *
     * Re-authorising an app it is ALREADY connected to replaces rather than
     * adds (see `exchange()`), so that case is never blocked — only a new
     * platform arriving at a store already holding its maximum.
     */
    public function assertCapacity(PosVendor $vendor, Merchant $merchant, ?string $origin = null): void
    {
        if ($this->liveCredentials($vendor, $merchant, $origin)->isNotEmpty()) {
            return;
        }

        $active = ApiCredential::query()
            ->where('merchant_id', $merchant->getKey())
            ->whereNull('revoked_at')
            ->count();

        if ($active >= CredentialService::MAX_ACTIVE_PER_MERCHANT) {
            throw ConnectException::storeAtCredentialCap(CredentialService::MAX_ACTIVE_PER_MERCHANT);
        }
    }

    /**
     * Confidential client: exact match against the registered list — never
     * a prefix or pattern. Public client: no list to match, so the URL
     * itself must be safe to send a browser to — https on a public host
     * (the webhook SSRF guard, same reasons), no fragment, bounded length.
     * The exact string is then bound into the code, so the exchange must
     * present it back unchanged.
     */
    public function assertRedirect(PosVendor $vendor, string $redirectUri): void
    {
        if (! $vendor->isPublicClient()) {
            if (! in_array($redirectUri, (array) ($vendor->redirect_uris ?? []), true)) {
                throw ConnectException::badRedirect();
            }

            return;
        }

        if (strlen($redirectUri) > 255) {
            throw ConnectException::badPublicRedirect('it is longer than 255 characters.');
        }

        if (str_contains($redirectUri, '#')) {
            throw ConnectException::badPublicRedirect('it must not carry a fragment.');
        }

        if (($why = EndpointUrlGuard::violation($redirectUri)) !== null) {
            throw ConnectException::badPublicRedirect(lcfirst($why));
        }
    }

    /**
     * The origin a public-client callback belongs to — scheme, host and
     * explicit port, nothing else. What `connected_from` stores and what
     * the panel shows as "Connected from shop.example.mv".
     */
    public static function originOf(string $redirectUri): string
    {
        $parts = parse_url($redirectUri);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.$host.$port;
    }

    /**
     * The shopkeeper pressed Authorise. Mints the one-time code their
     * platform will exchange.
     *
     * @param  list<VendorAbility>  $abilities
     */
    public function approve(
        PosVendor $vendor,
        Merchant $merchant,
        MerchantUser $approver,
        array $abilities,
        string $redirectUri,
        string $codeChallenge,
    ): string {
        $this->assertRedirect($vendor, $redirectUri);

        // Checked HERE, before a code exists, so a store at its ceiling is
        // told plainly instead of being redirected into an exchange that
        // cannot succeed.
        $this->assertCapacity($vendor, $merchant, $this->originFor($vendor, $redirectUri));

        // Raw once, hashed at rest: a code is a bearer value for its minute.
        $code = Str::random(64);

        OauthAuthorizationCode::query()->create([
            'pos_vendor_id' => $vendor->getKey(),
            'merchant_id' => $merchant->getKey(),
            'merchant_user_id' => $approver->getKey(),
            'code_hash' => hash('sha256', $code),
            'abilities' => array_map(fn (VendorAbility $a): string => $a->value, $abilities),
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'expires_at' => CarbonImmutable::now()->addSeconds(self::CODE_TTL_SECONDS),
        ]);

        return $code;
    }

    /**
     * The platform exchanges its code for a token.
     *
     * Everything is checked before anything is issued: the client's secret
     * (confidential clients only — a public client must NOT send one), that
     * the code is theirs, unspent and unexpired, that the redirect is the
     * one consented to, and that the verifier hashes to the challenge.
     */
    public function exchange(
        string $clientId,
        ?string $clientSecret,
        string $code,
        string $redirectUri,
        string $codeVerifier,
    ): IssuedCredential {
        $vendor = $this->client($clientId);

        if ($vendor->isPublicClient()) {
            // A secret arriving from a public client is not "extra proof";
            // it is a sign the caller is not the software it claims to be,
            // or has been configured as if it could keep one.
            if ($clientSecret !== null && $clientSecret !== '') {
                throw ConnectException::secretFromPublicClient();
            }
        } elseif ($clientSecret === null || ! Hash::check($clientSecret, (string) $vendor->client_secret_hash)) {
            throw ConnectException::badSecret();
        }

        return DB::transaction(function () use ($vendor, $code, $redirectUri, $codeVerifier): IssuedCredential {
            /** @var OauthAuthorizationCode|null $row */
            $row = OauthAuthorizationCode::query()
                ->where('code_hash', hash('sha256', $code))
                ->lockForUpdate()
                ->first();

            if ($row === null
                || (int) $row->pos_vendor_id !== (int) $vendor->getKey()
                || $row->used_at !== null
                || $row->redirect_uri !== $redirectUri
                || CarbonImmutable::now()->greaterThan($row->expires_at)) {
                throw ConnectException::badCode();
            }

            // PKCE: the verifier is the secret a stolen code does not carry.
            $expected = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

            if (! hash_equals($row->code_challenge, $expected)) {
                throw ConnectException::badVerifier();
            }

            // Spent under the lock, BEFORE the token exists — a code raced
            // against itself must never produce two live tokens.
            $row->forceFill(['used_at' => CarbonImmutable::now()])->save();

            $merchant = Merchant::query()->findOrFail($row->merchant_id);
            $approver = MerchantUser::query()->findOrFail($row->merchant_user_id);

            $origin = $this->originFor($vendor, $row->redirect_uri);

            // Re-authorising REPLACES. Without this a shop reconnecting an
            // app every few months accumulates live tokens it has
            // forgotten — and with no expiry, those live forever. For a
            // public client the unit is the store that connected: a second
            // store of the same merchant is a second grant, not a
            // replacement.
            $this->revokeExisting($vendor, $merchant, $approver, $origin);

            try {
                return $this->credentials->issueForConnect(
                    $merchant,
                    $vendor,
                    $row->abilities,
                    $approver,
                    connectedFrom: $origin,
                );
            } catch (CredentialCapReachedException $e) {
                // `approve()` already refused this case, so reaching it means
                // the shop filled its last slot in the sixty seconds since.
                // Rare, but the platform gets a stated refusal rather than a
                // 500 — and the rollback leaves the code unspent, so the
                // merchant can revoke something and finish the same flow.
                throw ConnectException::storeAtCredentialCap(
                    CredentialService::MAX_ACTIVE_PER_MERCHANT,
                );
            }
        });
    }

    /** Cut every live grant this platform holds for this shop (and origin, for public clients). */
    public function revokeExisting(PosVendor $vendor, Merchant $merchant, MerchantUser $by, ?string $origin = null): int
    {
        $live = $this->liveCredentials($vendor, $merchant, $origin);

        foreach ($live as $credential) {
            $this->credentials->revoke($credential, $by);
        }

        return $live->count();
    }

    /**
     * This platform's unrevoked credentials for this shop — narrowed to one
     * connecting store when an origin is given.
     *
     * @return Collection<int, ApiCredential>
     */
    public function liveCredentials(PosVendor $vendor, Merchant $merchant, ?string $origin = null)
    {
        return ApiCredential::query()
            ->where('pos_vendor_id', $vendor->getKey())
            ->where('merchant_id', $merchant->getKey())
            ->whereNull('revoked_at')
            ->when($origin !== null, fn ($q) => $q->where('connected_from', $origin))
            ->get();
    }

    /** The origin that scopes a grant: only public clients have one. */
    public function originFor(PosVendor $vendor, string $redirectUri): ?string
    {
        return $vendor->isPublicClient() ? self::originOf($redirectUri) : null;
    }
}
