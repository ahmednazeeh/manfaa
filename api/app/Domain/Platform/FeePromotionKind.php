<?php

declare(strict_types=1);

namespace App\Domain\Platform;

/**
 * The two ways the platform fee can be promotional (owner, 2026-08-25).
 *
 * NOT to be confused with App\Models\Promotion, which is a CASHBACK
 * promotion — a merchant paying their customers more. This enum is about
 * the fee MANFAA charges the merchant, and the two features never meet:
 * one lifts the reward, the other lowers our cut.
 */
enum FeePromotionKind: string
{
    /** Every merchant's first X days on the platform, from approved_at. */
    case Introductory = 'introductory';

    /** A window that covers every merchant, whatever their age. */
    case PlatformWide = 'platform_wide';

    public function label(): string
    {
        return match ($this) {
            self::Introductory => 'Introductory offer',
            self::PlatformWide => 'Platform-wide offer',
        };
    }
}
