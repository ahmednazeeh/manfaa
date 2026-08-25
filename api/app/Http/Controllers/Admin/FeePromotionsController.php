<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Money\Percent;
use App\Domain\Platform\FeePromotionKind;
use App\Domain\Platform\FeePromotionPolicy;
use App\Domain\Platform\TierScheduleService;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\FeePromotion;
use App\Rules\PercentRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PLATFORM FEE PROMOTIONS (owner, 2026-08-25) — "I intend to use this
 * feature during initial merchant acquisition."
 *
 * Read by any admin, WRITTEN by a superadmin only: the same gating the
 * platform's own bank accounts, the transfer settings and the GST switch
 * carry, and for the same reason — one save here changes what every
 * merchant on the platform is charged on every sale from the moment it is
 * thrown. Mirrors TaxSettingsController line for line.
 *
 * TWO KINDS ON ONE ROW:
 *
 *   INTRODUCTORY   every merchant pays `intro_fee_percent` for their first
 *                  `intro_days` days from `merchants.approved_at`.
 *   PLATFORM-WIDE  every merchant pays `wide_fee_percent` between
 *                  `wide_from` and `wide_to`, whatever their age.
 *
 * When both apply the merchant gets the LOWER fee (FeePromotionPolicy).
 *
 * FIVE REFUSALS, all of them the point of the feature. Each is evaluated
 * against the row AS IT WOULD BE SAVED, so a single request may supply the
 * fee, the copy and the switch together, and a request that supplies only
 * the switch on an empty row is refused:
 *
 *  1. NO FEE, NO PROMOTION. Enabling with no promotional fee set is refused
 *     rather than silently pricing at zero. `0.00` is a legal, deliberate
 *     value — it is the free-for-X-days case — and "not set" must not be
 *     able to become it by accident.
 *  2. A ZERO-DAY INTRODUCTORY WINDOW is a banner that lies: nobody is ever
 *     inside it. At least one day.
 *  3. A PLATFORM-WIDE PROMOTION NEEDS BOTH EDGES. A promotion with no end is
 *     a price cut, and the banner has to be able to say when it stops.
 *  4. AN END THAT PRECEDES ITS START describes no window at all. This one is
 *     judged on EVERY save rather than only when the switch is on, because
 *     the table's own CHECK constraint is unconditional: a draft saved with
 *     the dates the wrong way round has to answer 422, not 500.
 *  5. A FEE ABOVE THE CHEAPEST TIER IT COULD REPLACE. The merchant sitting
 *     on the active schedule's cheapest band would pay MORE under this
 *     "promotion", and a promotion that costs the merchant more is a
 *     mistake, not a promotion. (The seam still takes min(promotion, tier)
 *     per sale, so no merchant can ever actually be overcharged — but a
 *     superadmin typing 1.50% into a promotion field has made an error, and
 *     the honest thing is to say so instead of storing a number that will
 *     silently do nothing for most of the platform.)
 *
 * And one more, which is a rule about MERCHANT-FACING WORDS rather than
 * about money: a promotion may not be enabled without its banner copy in
 * BOTH English and Dhivehi. The banner is how a merchant learns they are
 * being charged less; a promotion nobody can be told about is an accounting
 * event, not a marketing one. The copy lives on this row precisely so a
 * campaign's wording can change without a deploy.
 *
 * NOTHING HERE TOUCHES AN EXISTING SALE. Every row carries the promotion it
 * was priced under (`transactions.fee_promo_kind` / `fee_promo_fee_bp` /
 * `list_fee_bp` / `fee_forgone_laari`), and every report, settlement and
 * journal reads that stamp. Enabling, re-rating, ending and moving a window
 * price NEW sales only.
 *
 * Wire grammar (PLAN §1): fees travel as `*_fee_percent`, 2-decimal percent
 * strings. Basis points never appear in a request or a response.
 */
final class FeePromotionsController extends Controller
{
    public function __construct(private readonly TierScheduleService $schedules) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->payload(FeePromotion::current())]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'intro_enabled' => ['sometimes', 'boolean'],
            'intro_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            // 0%–20%: zero is the whole point of the feature, and the
            // ceiling is the same structural bound §4 puts on every rate.
            'intro_fee_percent' => ['sometimes', 'nullable', PercentRate::between(0, Percent::MAX_BP)],
            'intro_banner_en' => ['sometimes', 'nullable', 'string', 'max:240'],
            'intro_banner_dv' => ['sometimes', 'nullable', 'string', 'max:240'],

            'wide_enabled' => ['sometimes', 'boolean'],
            'wide_from' => ['sometimes', 'nullable', 'date'],
            'wide_to' => ['sometimes', 'nullable', 'date'],
            'wide_fee_percent' => ['sometimes', 'nullable', PercentRate::between(0, Percent::MAX_BP)],
            'wide_banner_en' => ['sometimes', 'nullable', 'string', 'max:240'],
            'wide_banner_dv' => ['sometimes', 'nullable', 'string', 'max:240'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        // Read BEFORE the transaction opens and used for both refusals, so
        // the two kinds are judged against the same price list.
        $cheapestTierFeeBp = $this->schedules->activeCheapestFeeBp();

        $settings = DB::transaction(function () use ($validated, $admin, $cheapestTierFeeBp): FeePromotion {
            /** @var FeePromotion $settings */
            $settings = FeePromotion::query()->lockForUpdate()->first() ?? FeePromotion::current();

            foreach (['intro_fee_percent' => 'intro_fee_bp', 'wide_fee_percent' => 'wide_fee_bp'] as $wire => $column) {
                if (array_key_exists($wire, $validated)) {
                    $settings->{$column} = $validated[$wire] === null
                        ? null
                        : Percent::toBasisPointsWithin($validated[$wire], 0, Percent::MAX_BP);
                }
            }

            foreach ([
                'intro_enabled', 'intro_days', 'intro_banner_en', 'intro_banner_dv',
                'wide_enabled', 'wide_banner_en', 'wide_banner_dv',
            ] as $field) {
                if (array_key_exists($field, $validated)) {
                    $settings->{$field} = $validated[$field];
                }
            }

            foreach (['wide_from', 'wide_to'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $settings->{$field} = $validated[$field] === null
                        ? null
                        : CarbonImmutable::parse($validated[$field])->utc();
                }
            }

            $this->assertCoherent($settings, $cheapestTierFeeBp);

            $settings->updated_by = $admin->getKey();
            $settings->save();

            return $settings;
        });

        // The next sale prices under the new terms rather than up to a cache
        // TTL later — the same immediacy TaxPolicy::forget() buys the GST
        // switch. Ending a promotion is the case that matters: a superadmin
        // who pulls it must not watch another minute of free sales go past.
        FeePromotionPolicy::forget();

        return new JsonResponse(['data' => $this->payload($settings->refresh())]);
    }

    /**
     * The five money refusals plus the copy refusal, in the order an
     * operator would discover them, and worded for a PERSON — the panel
     * renders these verbatim.
     */
    private function assertCoherent(FeePromotion $settings, int $cheapestTierFeeBp): void
    {
        if ($settings->intro_enabled) {
            if ($settings->intro_fee_bp === null) {
                abort(422, 'The introductory offer cannot be switched on without a promotional platform fee. '
                    .'Set one first (or in the same request) — 0.00% is a valid choice, and is what "free for the first X days" means.');
            }

            if ((int) $settings->intro_days < 1) {
                abort(422, 'The introductory offer cannot be switched on for zero days — no merchant would ever be inside it. '
                    .'Set how many days a new merchant gets.');
            }

            $this->assertBelowCheapestTier((int) $settings->intro_fee_bp, $cheapestTierFeeBp, 'introductory offer');
            $this->assertCopy($settings->intro_banner_en, $settings->intro_banner_dv, 'introductory offer');
        }

        // THE ORDER REFUSAL IS JUDGED ON EVERY SAVE, SWITCH OR NO SWITCH,
        // and it is the one refusal here that is not merely a policy: the
        // table's own `fee_promotions_window_order_check` is UNCONDITIONAL
        // (`wide_to > wide_from` whenever both are set), so a superadmin
        // DRAFTING a window with the dates the wrong way round — the switch
        // still off, the copy still being written — would otherwise sail
        // past this method's early return and die inside the transaction as
        // an unhandled QueryException. A 500 carrying a raw SQLSTATE dump
        // instead of the sentence below, on a screen whose Save button is
        // gated on format alone. So it is hoisted above the switch, where
        // the constraint's own scope already is.
        if ($settings->wide_from !== null && $settings->wide_to !== null && $settings->wide_to <= $settings->wide_from) {
            abort(422, 'The platform-wide offer ends before — or exactly when — it starts, which is no offer at all. '
                .'Check the two dates.');
        }

        if (! $settings->wide_enabled) {
            return;
        }

        if ($settings->wide_fee_bp === null) {
            abort(422, 'The platform-wide offer cannot be switched on without a promotional platform fee. '
                .'Set one first (or in the same request) — 0.00% is a valid choice.');
        }

        // Only the BOTH-EDGES rule is a property of being switched ON: a
        // half-drawn window is storable (the constraint permits a null
        // edge), an inverted one is not (checked above, for every save).
        if ($settings->wide_from === null || $settings->wide_to === null) {
            abort(422, 'A platform-wide offer needs a start AND an end. An offer with no end is a price change, '
                .'and merchants have to be told when it stops.');
        }

        $this->assertBelowCheapestTier((int) $settings->wide_fee_bp, $cheapestTierFeeBp, 'platform-wide offer');
        $this->assertCopy($settings->wide_banner_en, $settings->wide_banner_dv, 'platform-wide offer');
    }

    private function assertBelowCheapestTier(int $feeBp, int $cheapestTierFeeBp, string $what): void
    {
        if ($feeBp > $cheapestTierFeeBp) {
            abort(422, sprintf(
                'A promotional fee of %s%% is HIGHER than the cheapest platform fee on the active tier schedule (%s%%), '
                    .'so a merchant on that tier would pay MORE during the %s. A promotion that costs the merchant more '
                    .'is a mistake, not a promotion — set the fee at or below %s%%.',
                Percent::format($feeBp),
                Percent::format($cheapestTierFeeBp),
                $what,
                Percent::format($cheapestTierFeeBp),
            ));
        }
    }

    private function assertCopy(?string $en, ?string $dv, string $what): void
    {
        $missing = [];

        if (trim((string) $en) === '') {
            $missing[] = 'English';
        }

        if (trim((string) $dv) === '') {
            $missing[] = 'Dhivehi';
        }

        if ($missing !== []) {
            abort(422, sprintf(
                'The %s cannot be switched on without banner wording in %s. Merchants are shown this sentence on the '
                    .'panel, in the till app and on the public landing page — a discount nobody can be told about is not a promotion.',
                $what,
                implode(' and ', $missing),
            ));
        }
    }

    /**
     * The whole row, plus what each switch is still waiting on so a panel can
     * say it before the 422 does, plus the bound the fees are judged against
     * so the form can show it above the input.
     *
     * There is no identity to withhold here (compare TaxSettingsController):
     * a promotion is marketing, and every field on it is destined for a
     * merchant's screen anyway. So any admin who may read this reads all of
     * it.
     *
     * @return array<string, mixed>
     */
    private function payload(FeePromotion $settings): array
    {
        $cheapestTierFeeBp = $this->schedules->activeCheapestFeeBp();

        return [
            'intro' => [
                'kind' => FeePromotionKind::Introductory->value,
                'enabled' => (bool) $settings->intro_enabled,
                'days' => (int) $settings->intro_days,
                'platform_fee_percent' => Percent::formatOrNull($settings->intro_fee_bp),
                'banner_en' => $settings->intro_banner_en,
                'banner_dv' => $settings->intro_banner_dv,
                'blockers' => $this->blockers(
                    $settings->intro_fee_bp,
                    (int) $settings->intro_days < 1 ? 'days' : null,
                    $settings->intro_banner_en,
                    $settings->intro_banner_dv,
                    $cheapestTierFeeBp,
                ),
            ],
            'platform_wide' => [
                'kind' => FeePromotionKind::PlatformWide->value,
                'enabled' => (bool) $settings->wide_enabled,
                'from' => $settings->wide_from?->utc()->toIso8601String(),
                'to' => $settings->wide_to?->utc()->toIso8601String(),
                'platform_fee_percent' => Percent::formatOrNull($settings->wide_fee_bp),
                'banner_en' => $settings->wide_banner_en,
                'banner_dv' => $settings->wide_banner_dv,
                'blockers' => $this->blockers(
                    $settings->wide_fee_bp,
                    match (true) {
                        $settings->wide_from === null || $settings->wide_to === null => 'window',
                        $settings->wide_to <= $settings->wide_from => 'window_order',
                        default => null,
                    },
                    $settings->wide_banner_en,
                    $settings->wide_banner_dv,
                    $cheapestTierFeeBp,
                ),
            ],
            // The bound both fees are checked against, in the wire's own
            // grammar, so a form can print "must be 0.25% or less" rather
            // than waiting for a merchant-facing refusal.
            'max_promotional_fee_percent' => Percent::format($cheapestTierFeeBp),
            // WHAT A PROMOTION DOES NOT COVER, said out loud in the payload
            // so an admin screen can say it too. Marketplace order fees are
            // a separate price list (see TermsResolver / CheckoutService).
            'applies_to' => ['cashback_platform_fee'],
            'excludes' => ['marketplace_order_fee'],
            'updated_at' => $settings->updated_at?->toIso8601String(),
        ];
    }

    /**
     * What still stands between this switch and being turned on — machine
     * slugs a panel can map to its own inputs, empty when it is ready.
     *
     * @return list<string>
     */
    private function blockers(?int $feeBp, ?string $windowIssue, ?string $en, ?string $dv, int $cheapestTierFeeBp): array
    {
        $blockers = [];

        if ($feeBp === null) {
            $blockers[] = 'fee_not_set';
        } elseif ($feeBp > $cheapestTierFeeBp) {
            $blockers[] = 'fee_above_cheapest_tier';
        }

        if ($windowIssue !== null) {
            $blockers[] = $windowIssue;
        }

        if (trim((string) $en) === '') {
            $blockers[] = 'banner_en_missing';
        }

        if (trim((string) $dv) === '') {
            $blockers[] = 'banner_dv_missing';
        }

        return $blockers;
    }
}
