<?php

declare(strict_types=1);

namespace App\Domain\Customers;

/**
 * A Maldivian mobile number, in the one shape the platform stores.
 *
 * Nobody in the Maldives says their number with the country code — it is
 * seven digits, and the forms now ask for seven digits. Storage stays E.164
 * (+960XXXXXXX) because that is what an SMS gateway takes and what makes a
 * number comparable to itself, so the two representations have to meet
 * somewhere: here, at the edge, BEFORE the number is used as a key.
 *
 * That ordering is the whole point. Phone is an identity — it keys the OTP
 * record, the per-phone throttle and the customer row — so normalising after
 * a lookup would let "7712345" and "+9607712345" become two accounts for one
 * person, and let someone slip a throttle by alternating between the forms.
 *
 * Accepted: seven digits starting 7 or 9, with or without +960 / 960 / 00960,
 * and with any spaces or dashes a person naturally types. Anything else is
 * null — the caller refuses it rather than guessing at a number.
 */
final class Msisdn
{
    /** What a stored, sendable number looks like. */
    public const string PATTERN = '/^\+960[79]\d{6}$/';

    /** What a person types into a seven-digit field. */
    public const string LOCAL_PATTERN = '/^[79]\d{6}$/';

    public static function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Spaces and dashes are how people write numbers, not part of them.
        $digits = preg_replace('/[\s\-()]/', '', trim($raw)) ?? '';

        // Strip whichever way the country code was written. 00960 first:
        // it contains 960, so testing the shorter prefix first would leave
        // a stray "00" behind.
        foreach (['+960', '00960', '960'] as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                $digits = substr($digits, strlen($prefix));
                break;
            }
        }

        return preg_match(self::LOCAL_PATTERN, $digits) === 1
            ? '+960'.$digits
            : null;
    }

    /** The seven digits a form should show, from a stored E.164 number. */
    public static function local(?string $stored): ?string
    {
        $normalised = self::normalise($stored);

        return $normalised === null ? null : substr($normalised, 4);
    }
}
