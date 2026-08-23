<?php

declare(strict_types=1);

namespace App\Domain\Referrals;

use App\Models\Customer;
use App\Models\CustomerDevice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Device identity for the self-referral defence (owner, 2026-08-24 —
 * NO TOLERANCE).
 *
 * Each surface offers whatever sanctioned identifier it has — Android
 * SSAID, iOS identifierForVendor plus a Keychain-persisted UUID, or the
 * web's long-lived `mfa_did` browser cookie — and the server stores ONLY
 * HMAC-SHA256(app key, raw). Equality of hashes is the single question the
 * defence ever asks ("have these two customers touched the same device?"),
 * so nothing more than the keyed hash is kept, and the table names no real
 * device even if it leaks.
 *
 * KEYED to APP_KEY deliberately: rotating APP_KEY orphans every stored hash
 * (old rows stop matching new sightings). That is ACCEPTED — the defence
 * degrades to collecting afresh from that day, exactly as if the store were
 * new, and no raw ids exist anywhere to re-hash.
 *
 * KNOWN-OPEN PATHS — inherent to client-supplied identity, on the record so
 * nobody believes the defence tighter than it is (surfaced in review,
 * 2026-08-24; owner sign-off pending):
 * - Fresh browser, app-only referrer: hashes only ever match EXACTLY, and a
 *   browser ref can never equal an SSAID/IFV hash. A second account signed
 *   up in any browser the referrer's accounts never touched collides with
 *   nothing — and spend happens at merchant POS, so account #2 need never
 *   send a device-bearing request at all.
 * - Stripped header: a scripted client simply omits X-Device-Id; the
 *   middleware deliberately records nothing rather than fail the request.
 * - Second Android user profile / app clone: a distinct SSAID, no collision.
 * - iOS reinstall: identifierForVendor rotates when the last vendor app is
 *   deleted; the Keychain-persisted kc: ref (sent as X-Device-Ref) is the
 *   leg that survives it.
 * - Evidence after payout: sharesDevice() is consulted exactly once, at
 *   award time; a collision first seen later is forgiven by the owner's
 *   explicit no-clawback rule.
 */
final class DeviceIdentity
{
    /** The app's device header, read on every authed mobile customer request. */
    public const string HEADER_ID = 'X-Device-Id';

    public const string HEADER_PLATFORM = 'X-Device-Platform';

    /**
     * A SECOND identity the same request may carry — iOS sends its
     * Keychain-persisted `kc:` UUID here alongside the `ifv:` primary, so
     * the one identifier that survives a reinstall is actually on record.
     * Recorded exactly like HEADER_ID; older app builds simply omit it.
     */
    public const string HEADER_REF = 'X-Device-Ref';

    /**
     * The web signup's first-party browser ref: a random UUID minted by the
     * signup-flow responses, ~400 days, httpOnly. Deliberately excluded
     * from cookie encryption (bootstrap/app.php) so its value survives the
     * stateful/stateless split of the api routes — it is a random ref, not
     * a secret, and its integrity is best-effort by nature.
     */
    public const string WEB_COOKIE = 'mfa_did';

    public const int WEB_COOKIE_MINUTES = 400 * 24 * 60;

    /**
     * A raw id longer than any sanctioned identifier is garbage, not
     * identity. The longest sanctioned value is the FCM sighting —
     * 'fcm:' + a push token PushTokenController accepts at up to 512
     * chars — so the bound sits just above that. (Everything is hashed
     * to 64 chars before storage; this only rejects obvious junk.)
     */
    private const int MAX_RAW_BYTES = 520;

    /**
     * Hard per-customer ceiling on DISTINCT device hashes — far beyond any
     * honest household's phones/browsers/reinstalls, and the lid on a
     * scripted client inserting one row per rotated X-Device-Id forever.
     * At the cap, known devices still bump last_seen_at; new ones are
     * silently dropped (header-churn past 30 devices is itself evasion
     * behaviour, and dropped evidence only ever hurts the fraudster).
     */
    private const int MAX_DEVICES_PER_CUSTOMER = 30;

    private const array PLATFORMS = ['android', 'ios', 'web'];

    /** How often last_seen_at is worth a write. Repeat requests inside the window cost one cache hit. */
    private const int SEEN_BUMP_SECONDS = 3600;

    /** HMAC-SHA256 of the raw id under the app key — 64 lowercase hex chars. */
    public function hash(string $raw): string
    {
        return hash_hmac('sha256', $raw, self::key());
    }

    /**
     * Record that this customer was seen on this device. Silently ignores
     * empty or oversize ids — a malformed header must never fail a request.
     * Upserts one row per (customer, device) and bumps last_seen_at at most
     * once per hour, so the per-request cost on a warm cache is zero DB work.
     */
    public function record(Customer $customer, ?string $raw, string $platform): void
    {
        $raw = trim((string) $raw);

        if ($raw === '' || strlen($raw) > self::MAX_RAW_BYTES || ! in_array($platform, self::PLATFORMS, true)) {
            return;
        }

        $hash = $this->hash($raw);

        // The hourly guard: add() wins at most once per window, so repeat
        // requests from the same device skip the upsert entirely.
        if (! Cache::add(sprintf('customer-device:%d:%s', (int) $customer->getKey(), $hash), 1, self::SEEN_BUMP_SECONDS)) {
            return;
        }

        // The cap: a customer already holding MAX distinct hashes gets no
        // NEW rows (known hashes still bump last_seen_at below). Both
        // queries run at most once per (customer, hash, hour) thanks to the
        // cache guard above, so honest traffic never pays for them twice.
        $known = CustomerDevice::query()
            ->where('customer_id', (int) $customer->getKey())
            ->where('device_hash', $hash)
            ->exists();

        if (! $known && CustomerDevice::query()
            ->where('customer_id', (int) $customer->getKey())
            ->count() >= self::MAX_DEVICES_PER_CUSTOMER) {
            return;
        }

        $now = CarbonImmutable::now('UTC');

        CustomerDevice::query()->upsert(
            [[
                'customer_id' => (int) $customer->getKey(),
                'device_hash' => $hash,
                'platform' => $platform,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]],
            ['customer_id', 'device_hash'],
            ['last_seen_at'],
        );
    }

    /**
     * The web flow's recording half: the `mfa_did` cookie value, accepted in
     * UUID v4 shape ONLY — anything else (a stale encrypted blob, a typed-in
     * novelty) records nothing.
     */
    public function recordBrowserRef(Customer $customer, mixed $cookie): void
    {
        if (self::isBrowserRef($cookie)) {
            $this->record($customer, $cookie, 'web');
        }
    }

    /**
     * Have these two customers EVER shared a device hash — or do they
     * currently share an FCM push token in device_tokens? Two indexed
     * EXISTS checks.
     *
     * The customer_devices leg carries the real weight for FCM too:
     * PushTokenController::update() records each registration's token hash
     * here, and since device_tokens.token is globally UNIQUE (a handover
     * destroys the old owner's row), that permanent trail is the only place
     * a token seen on BOTH accounts still shows. The direct device_tokens
     * intersection is kept as the belt-and-braces the spec asks for — it
     * fires the day two live rows ever do coexist.
     */
    public function sharesDevice(Customer $a, Customer $b): bool
    {
        $sharedHash = DB::table('customer_devices', 'da')
            ->join('customer_devices as db', 'da.device_hash', '=', 'db.device_hash')
            ->where('da.customer_id', (int) $a->getKey())
            ->where('db.customer_id', (int) $b->getKey())
            ->exists();

        if ($sharedHash) {
            return true;
        }

        // tokenable morph, customers only — a merchant till sharing a
        // handset with its owner is not this defence's business.
        $morph = $a->getMorphClass();

        return DB::table('device_tokens', 'ta')
            ->join('device_tokens as tb', 'ta.token', '=', 'tb.token')
            ->where('ta.tokenable_type', $morph)
            ->where('ta.tokenable_id', (int) $a->getKey())
            ->where('tb.tokenable_type', $morph)
            ->where('tb.tokenable_id', (int) $b->getKey())
            ->exists();
    }

    /** Strictly a UUID v4 — the only shape the web cookie is ever minted in. */
    public static function isBrowserRef(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /** A fresh browser-ref cookie: httpOnly, secure, SameSite=Lax, ~400 days. */
    public static function mintBrowserCookie(): Cookie
    {
        return cookie(
            self::WEB_COOKIE,
            (string) Str::uuid(),
            self::WEB_COOKIE_MINUTES,
            path: '/',
            secure: true,
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /** The app key, base64-decoded the way Laravel's encrypter reads it. */
    private static function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return $key;
    }
}
