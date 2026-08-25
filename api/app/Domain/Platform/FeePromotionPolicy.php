<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Models\FeePromotion;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH promotional platform fee is in force, for whom, and until when
 * (owner, 2026-08-25).
 *
 * The single source of the promotion rules. TermsResolver asks it one
 * question at the fee seam; the banner endpoints ask it another. Nothing
 * else in the codebase is allowed to learn that fee promotions exist.
 *
 * ## The two kinds, and how a merchant's window is found
 *
 * INTRODUCTORY. The clock is `merchants.approved_at` — the stamp that says
 * the store could actually trade — and the window is X whole BUSINESS days
 * (§13: UTC storage, Malé rules), half-open:
 *
 *     start = start of the Malé day approved_at fell on
 *     end   = start + X days   (EXCLUSIVE)
 *
 * so day 0 is approval day, day X-1 is the last day inside, and the first
 * instant of day X is outside. Starting at the START of the approval day
 * rather than at the approval INSTANT is deliberate: a sale rung up at 10am
 * and a store approved at 3pm the same afternoon are the same business day,
 * and a backdated credit for that morning must not fall out of the window on
 * a technicality.
 *
 * THERE IS NO ENROLMENT RECORD, and that is the whole answer to "what about
 * merchants who joined before the promotion existed?". A window is a pure
 * function of a store's own approval date, so switching the promotion on
 * today gives a store approved 200 days ago exactly nothing: their first X
 * days are over. A store approved 3 days ago gets the remaining X-3. Nothing
 * is retro-enrolled and nothing is backdated.
 *
 * PLATFORM-WIDE. A half-open [from, to) window that covers every merchant,
 * whatever their age.
 *
 * ## When both apply, the MERCHANT WINS
 *
 * The LOWER fee prices the sale. On an exact tie the introductory kind is
 * reported, because the fee is identical either way and the merchant-specific
 * banner ("your first 30 days") is the more useful sentence — the money is
 * the same, so the choice is purely about what the merchant is told.
 *
 * ## Freshness
 *
 * The settings row is cached for 60 seconds, exactly as TaxPolicy caches the
 * GST terms and for the same reason: this is read on every credit, and a
 * per-sale round trip to the same single row is a query that can only ever
 * repeat itself. Writes call `forget()`, so a superadmin who ends a
 * promotion sees the next sale priced without it rather than up to a minute
 * later.
 *
 * It is NEVER consulted about a sale that has already been priced. That row
 * carries its own stamp (`fee_promo_kind` / `fee_promo_fee_bp` /
 * `list_fee_bp`), and reading this instead would re-price history — the one
 * thing this feature must never do.
 *
 * ## The one asymmetry, stated rather than left to be found
 *
 * A fee promotion lives on a single MUTABLE settings row, not on an
 * effective-dated history the way cashback promotions (`promotions`) and fee
 * tier schedules (`fee_tier_schedules`) do. Both windows above are therefore
 * tested against the sale's own instant, but only for as long as the row
 * still describes them: once a superadmin switches a promotion OFF, it prices
 * nothing at all any more — including a BACKDATED credit or an approved claim
 * for a date inside the window it used to describe.
 *
 * That is deliberate, and it errs the safe way: a finished campaign stops
 * giving money away, rather than continuing to through the back door of a
 * late-keyed sale. Rows already priced keep their stamp regardless, which is
 * what makes the guarantee that matters — nothing is ever re-priced — hold
 * either way. ClaimApprovalStampTest asserts both halves.
 */
final class FeePromotionPolicy
{
    private const string CACHE_KEY = 'fee_promotions.current';

    private const int CACHE_TTL_SECONDS = 60;

    /**
     * The deploy-order probe, answered ONCE per instance — the same probe
     * TaxPolicy and TermsResolver use, and for the same reason: this code
     * can reach production a moment before its migration runs, and it
     * executes INSIDE the credit's database transaction where a failed
     * query would abort the sale. No table means no promotion, which is
     * also the correct answer.
     */
    private ?bool $tableExists = null;

    /** @var array<int, CarbonImmutable|null> */
    private array $approvedAt = [];

    /** The settings in force right now. */
    public function settings(): FeePromotionSettings
    {
        $this->tableExists ??= Schema::hasTable('fee_promotions');

        if ($this->tableExists === false) {
            return FeePromotionSettings::off();
        }

        /** @var array<string, mixed> $cached */
        $cached = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => FeePromotionSettings::fromRow(FeePromotion::current())->toCache(),
        );

        return FeePromotionSettings::fromCache($cached);
    }

    /** Called by the settings write path; the next read re-queries. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * THE PRICING QUESTION, and the only one TermsResolver asks: what
     * promotional fee, if any, does this merchant get on a sale that
     * happened at this instant?
     *
     * Returns FeeRelief::none() when nothing applies, which is the identity
     * — the tier's own fee then prices the sale exactly as it always has.
     */
    public function reliefFor(int $merchantId, CarbonImmutable $at): FeeRelief
    {
        return $this->offerFor($merchantId, $at)?->relief() ?? FeeRelief::none();
    }

    /**
     * The winning offer for one merchant at one instant — the same
     * resolution the fee seam prices from, with the dates and copy a banner
     * needs attached. Null when nothing applies.
     */
    public function offerFor(int $merchantId, CarbonImmutable $at): ?FeePromotionOffer
    {
        $settings = $this->settings();
        $candidates = [];

        if ($settings->introLive()) {
            $approvedAt = $this->approvedAt($merchantId);

            if ($approvedAt !== null) {
                [$start, $end] = self::introWindow($approvedAt, $settings->introDays);

                if ($at >= $start && $at < $end) {
                    $candidates[] = new FeePromotionOffer(
                        kind: FeePromotionKind::Introductory,
                        feeBp: (int) $settings->introFeeBp,
                        endsAt: $end,
                        introDays: $settings->introDays,
                        bannerEn: $settings->introBannerEn,
                        bannerDv: $settings->introBannerDv,
                    );
                }
            }
        }

        if ($settings->wideLiveAt($at)) {
            $candidates[] = new FeePromotionOffer(
                kind: FeePromotionKind::PlatformWide,
                feeBp: (int) $settings->wideFeeBp,
                endsAt: $settings->wideTo,
                introDays: null,
                bannerEn: $settings->wideBannerEn,
                bannerDv: $settings->wideBannerDv,
            );
        }

        // THE MERCHANT WINS: the lower fee. The introductory candidate is
        // first in the list, so a strict `<` leaves it holding a tie.
        $winner = null;

        foreach ($candidates as $candidate) {
            if ($winner === null || $candidate->feeBp < $winner->feeBp) {
                $winner = $candidate;
            }
        }

        return $winner;
    }

    /**
     * What a STRANGER on the merchant landing page may be told: every offer
     * currently on the table, with no merchant attached to any of them.
     *
     * The introductory offer is listed whenever it is switched on — it is an
     * offer to whoever signs up next, and there is no window to be inside or
     * outside of until somebody is a merchant. The platform-wide offer is
     * listed only while its window is actually open.
     *
     * @return list<FeePromotionOffer>
     */
    public function publicOffers(CarbonImmutable $at): array
    {
        $settings = $this->settings();
        $offers = [];

        if ($settings->introLive()) {
            $offers[] = new FeePromotionOffer(
                kind: FeePromotionKind::Introductory,
                feeBp: (int) $settings->introFeeBp,
                // No date. A visitor has no approval stamp, so there is no
                // window end that means anything to them — and inventing one
                // from "now" would be a promise we cannot keep.
                endsAt: null,
                introDays: $settings->introDays,
                bannerEn: $settings->introBannerEn,
                bannerDv: $settings->introBannerDv,
            );
        }

        if ($settings->wideLiveAt($at)) {
            $offers[] = new FeePromotionOffer(
                kind: FeePromotionKind::PlatformWide,
                feeBp: (int) $settings->wideFeeBp,
                endsAt: $settings->wideTo,
                introDays: null,
                bannerEn: $settings->wideBannerEn,
                bannerDv: $settings->wideBannerDv,
            );
        }

        return $offers;
    }

    /**
     * The introductory window for a store approved at $approvedAt, in
     * business time: [start of the Malé day it was approved on, +X days).
     * The end is EXCLUSIVE.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function introWindow(CarbonImmutable $approvedAt, int $days): array
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $start = $approvedAt->setTimezone($timezone)->startOfDay();

        return [$start->utc(), $start->addDays(max(0, $days))->utc()];
    }

    /**
     * The store's go-live stamp, read once per merchant per instance. A
     * draft or pending store has none, and a store that has never been
     * approved has never had a first day.
     */
    private function approvedAt(int $merchantId): ?CarbonImmutable
    {
        if (array_key_exists($merchantId, $this->approvedAt)) {
            return $this->approvedAt[$merchantId];
        }

        $merchant = Merchant::query()->whereKey($merchantId)->first(['id', 'approved_at']);

        return $this->approvedAt[$merchantId] = $merchant?->approved_at?->toImmutable()->utc();
    }
}
