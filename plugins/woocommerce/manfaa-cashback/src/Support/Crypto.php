<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Support;

/**
 * The token and the webhook secret at rest: `sodium_crypto_secretbox`.
 *
 * Key, in order of preference: `MANFAA_CASHBACK_KEY` from wp-config.php;
 * an HKDF of AUTH_KEY.AUTH_SALT when those are real constants (not the
 * "put your unique phrase here" placeholders); else `wp_salt()` — which
 * WordPress stores in the database, the very table this protects against,
 * so that case raises an admin notice. A fingerprint of the key is stored
 * beside the ciphertext: rotated salts then show "Reconnect Manfaa" instead
 * of retrying with garbage.
 */
final class Crypto
{
    private const PLACEHOLDER = 'put your unique phrase here';

    public static function encrypt(string $plain): string
    {
        $key = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);

        return self::fingerprint($key).':'.base64_encode($nonce.$cipher);
    }

    /** Null when the stored value cannot be opened with the current key. */
    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || ! str_contains($stored, ':')) {
            return null;
        }

        [$fingerprint, $payload] = explode(':', $stored, 2);
        $key = self::key();

        if (! hash_equals(self::fingerprint($key), $fingerprint)) {
            return null;
        }

        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);

        return $plain === false ? null : $plain;
    }

    /** True when a stored value exists but no longer opens — salts rotated. */
    public static function keyMismatch(?string $stored): bool
    {
        if ($stored === null || $stored === '' || ! str_contains($stored, ':')) {
            return false;
        }

        [$fingerprint] = explode(':', $stored, 2);

        return ! hash_equals(self::fingerprint(self::key()), $fingerprint);
    }

    /** Where the key comes from, for the settings page. */
    public static function keySource(): string
    {
        if (defined('MANFAA_CASHBACK_KEY') && is_string(MANFAA_CASHBACK_KEY) && MANFAA_CASHBACK_KEY !== '') {
            return 'constant';
        }

        if (self::realSalt('AUTH_KEY') && self::realSalt('AUTH_SALT')) {
            return 'salts';
        }

        return 'database';
    }

    private static function key(): string
    {
        $material = match (self::keySource()) {
            'constant' => MANFAA_CASHBACK_KEY,
            'salts' => AUTH_KEY.AUTH_SALT,
            default => wp_salt('auth').wp_salt('secure_auth'),
        };

        // HKDF-style expansion to the secretbox key length.
        return hash_hkdf('sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'manfaa-cashback');
    }

    private static function fingerprint(string $key): string
    {
        return substr(hash('sha256', 'fp|'.$key), 0, 12);
    }

    private static function realSalt(string $name): bool
    {
        return defined($name)
            && is_string(constant($name))
            && strlen(constant($name)) >= 32
            && ! str_contains(constant($name), self::PLACEHOLDER);
    }
}
