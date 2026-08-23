<?php

declare(strict_types=1);

namespace App\Domain\Customers;

/**
 * The one masking idiom for showing a customer's name to someone who is not
 * that customer: keep a short leading fragment, star the rest, per name part
 * — "Aisha Mohamed" → "Ais*** Moh***".
 *
 * Extracted from HoldResource and the V1 lookup so the referral friends list
 * (and anything after it) masks the same way those surfaces always have.
 * This is a PRESENTATION, not a protection — every caller still owes its own
 * access control; see V1\CustomerLookupController for that argument in full.
 */
final class MaskedName
{
    public static function of(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return implode(' ', array_map(
            fn (string $part): string => mb_substr($part, 0, 3).'***',
            $parts,
        ));
    }
}
