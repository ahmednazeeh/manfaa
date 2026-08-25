<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Domain\Money\Percent;
use Carbon\CarbonImmutable;

/**
 * ONE promotional offer, in the shape a CLIENT renders a banner from — the
 * merchant panel, the till app and the public merchant landing.
 *
 * PLAN §1 wire grammar: the fee travels as `platform_fee_percent`, a
 * 2-decimal percent string. Basis points never appear in a response.
 *
 * TWO SHAPES, because two audiences:
 *
 *   toMerchantArray()  for an AUTHENTICATED merchant. Carries `ends_at` —
 *                      for an introductory offer that is THIS merchant's own
 *                      window end, computed from their approval date — and
 *                      `days_remaining`, so the panel can say "9 days left".
 *   toPublicArray()    for a STRANGER on the landing page. Carries the OFFER
 *                      and nothing else: for the introductory kind, the
 *                      number of days on offer (`intro_days`), never a date,
 *                      because a visitor has no merchant and there is no
 *                      merchant window to leak. The platform-wide window's
 *                      end IS public — it is the platform's own campaign
 *                      deadline, printed on the poster on purpose.
 */
final readonly class FeePromotionOffer
{
    public function __construct(
        public FeePromotionKind $kind,
        public int $feeBp,
        /** Exclusive: the first instant the offer no longer prices a sale. */
        public ?CarbonImmutable $endsAt,
        /** Only set for the introductory kind: the length of the offer. */
        public ?int $introDays,
        public ?string $bannerEn,
        public ?string $bannerDv,
    ) {}

    public function relief(): FeeRelief
    {
        return FeeRelief::of($this->kind, $this->feeBp);
    }

    /**
     * Whole days left before the offer stops applying, floored at 0 and
     * counted the way §13 counts every other day: business-timezone
     * calendar days, so a merchant checking against their own clock agrees.
     * The last day of a window reads 1, not 0 — there is still a day of it
     * to use.
     */
    public function daysRemaining(CarbonImmutable $at): ?int
    {
        if ($this->endsAt === null) {
            return null;
        }

        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $today = $at->setTimezone($timezone)->startOfDay();
        $last = $this->endsAt->setTimezone($timezone)->startOfDay();

        return max(0, (int) $today->diffInDays($last));
    }

    /**
     * @return array<string, mixed>
     */
    public function toMerchantArray(CarbonImmutable $at): array
    {
        return [
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'platform_fee_percent' => Percent::format($this->feeBp),
            'ends_at' => $this->endsAt?->utc()->toIso8601String(),
            'days_remaining' => $this->daysRemaining($at),
            'banner_en' => $this->bannerEn,
            'banner_dv' => $this->bannerDv,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'platform_fee_percent' => Percent::format($this->feeBp),
            // An introductory offer's dates belong to a MERCHANT, and a
            // stranger has no merchant: the public shape says how long the
            // offer runs for, never when anybody's runs out.
            'intro_days' => $this->kind === FeePromotionKind::Introductory ? $this->introDays : null,
            'ends_at' => $this->kind === FeePromotionKind::PlatformWide
                ? $this->endsAt?->utc()->toIso8601String()
                : null,
            'banner_en' => $this->bannerEn,
            'banner_dv' => $this->bannerDv,
        ];
    }
}
