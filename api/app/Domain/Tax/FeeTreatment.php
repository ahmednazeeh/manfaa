<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * Which side of the platform fee the GST sits on (owner, 2026-08-24).
 *
 * ON TOP — the quoted fee is exclusive of tax. The merchant owes
 * cashback + fee + GST, so the amount due goes UP by the tax and Manfaa's
 * fee income is exactly what it was.
 *
 * INCLUSIVE — the quoted fee already contained the tax. The merchant owes
 * the same total they always did, and the GST share is carved OUT of
 * Manfaa's own revenue: at 8% the platform keeps fee - ceil(fee*800/10800).
 *
 * The choice is a platform-wide policy, stamped onto every transaction at
 * creation so a later switch can never re-price a sale that has already
 * been quoted.
 */
enum FeeTreatment: string
{
    case OnTop = 'on_top';

    case Inclusive = 'inclusive';

    public function label(): string
    {
        return match ($this) {
            self::OnTop => 'Added on top of the fee',
            self::Inclusive => 'Included in the fee',
        };
    }

    /**
     * How the change lands on a merchant's bill, in the words the
     * "GST now applies" notification uses.
     */
    public function merchantEffect(): string
    {
        return match ($this) {
            self::OnTop => 'added to your platform fee',
            self::Inclusive => 'already included in your platform fee',
        };
    }
}
