<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\Cashback\TransactionState;
use App\Domain\Customers\BalanceQuery;
use App\Domain\Customers\CustomerAvatar;
use App\Domain\Customers\PayoutWindow;
use App\Domain\MerchantAccess\Permission;
use App\Domain\Money\MerchantMoneyCache;
use App\Domain\Payout\EligibilityQuery;
use App\Domain\Settlement\OutstandingSummary;
use App\Domain\Settlement\SettlementState;
use App\Http\Controllers\Controller;
use App\Http\Responses\CacheableJson;
use App\Models\Customer;
use App\Models\MerchantUser;
use App\Models\Settlement;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first screen of each app, in ONE request.
 *
 * WHY THIS EXISTS: opening the app used to cost four or five sequential
 * round trips — identity, balance, payout window, outstanding, settlement
 * state — each waiting on the last. On a Maldives mobile connection that is
 * the difference between one paint and a staircase of spinners, and latency,
 * not bandwidth, is what a phone here actually pays.
 *
 * These compose the SAME domain services the panels use — BalanceQuery,
 * PayoutWindow, OutstandingSummary. No rule is restated here; if a figure
 * disagrees with the panel, the bug is in this file, not in the domain.
 *
 * Deliberately NOT included: transaction history. It changes on every
 * purchase, and folding a volatile list into an aggregate would churn the
 * ETag on every read and destroy the 304s that make calling this on every
 * resume cheap. History has its own cursor-paged endpoint.
 */
final class HomeController extends Controller
{
    public function __construct(private readonly MerchantMoneyCache $moneyCache) {}

    public function customer(Request $request, BalanceQuery $balances): Response
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $now = CarbonImmutable::now('UTC');
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');

        $sums = $balances->balances($customer, $now, $timezone);

        return CacheableJson::respond($request, response()->json([
            'data' => [
                'customer' => [
                    'name' => $customer->name,
                    'name_dv' => $customer->name_dv,
                    // The code and its QR are the app's whole job at a till.
                    'customer_code' => $customer->customer_code,
                    // Null until a picture is set; the top bar's circle.
                    // Content-addressed, so a change also changes this
                    // response's ETag and the app repaints on next resume.
                    'avatar_url' => CustomerAvatar::url($customer),
                ],
                'balance' => [
                    'currency' => 'MVR',
                    // §10, non-negotiable: Confirmed is the HEADLINE and is
                    // never summed with pending.
                    'confirmed_laari' => $sums['confirmed_laari'],
                    // Conditional money. Separate figure, always.
                    'pending_laari' => $sums['pending_laari'],
                    'paid_this_month_laari' => $sums['paid_this_month_laari'],
                ],
                'payout' => [
                    'minimum_laari' => EligibilityQuery::MINIMUM_PAYOUT_LAARI,
                    'next_window' => PayoutWindow::next($now, $timezone),
                    'has_account' => filled($customer->payout_bank)
                        && filled($customer->payout_account)
                        && filled($customer->payout_account_name),
                ],
            ],
        ]));
    }

    public function merchant(Request $request, OutstandingSummary $summary): Response
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $merchant = $user->merchant;
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');

        // "Today" is the business day the cashier is standing in, not a UTC
        // one — a sale at 9pm Malé must not land on tomorrow's tally.
        $now = CarbonImmutable::now($timezone);
        $startOfDay = $now->startOfDay()->utc();

        // Version-keyed money cache (phase 2, owner 2026-08-23): any landed
        // credit bumps the version, so the tallies are event-fresh; the
        // date in the name makes midnight cut over instantly. Only the
        // merchant-scoped sums are cached — never the whole payload, which
        // varies by the CALLER's permissions.
        $tally = fn (CarbonImmutable $since): array => (array) DB::table('transactions')
            ->selectRaw('count(*) as credit_count')
            ->selectRaw('coalesce(sum(eligible_laari), 0) as eligible_laari')
            ->selectRaw('coalesce(sum(cashback_laari), 0) as cashback_laari')
            ->where('merchant_id', $merchant->getKey())
            ->where('occurred_at', '>=', $since)
            // A reversed or written-off sale is not a sale. Counting either
            // would have the till disagree with the receipt roll by the end
            // of a shift.
            ->whereNotIn('state', [
                TransactionState::Reversed->value,
                TransactionState::WrittenOff->value,
            ])
            ->first();

        $tallies = $this->moneyCache->remember(
            (int) $merchant->getKey(),
            'home-tallies:'.$now->toDateString(),
            fn (): array => [
                'today' => $tally($startOfDay),
                'month' => $tally($now->startOfMonth()->utc()),
            ],
        );
        $today = (object) $tallies['today'];

        // "This month" answers the question the rest of the dashboard does
        // not: what Manfaa is GENERATING, not only what it costs (owner
        // report 2026-08-18). Same business-timezone boundary and the same
        // reversed/written-off exclusions, computed in the one cached
        // closure above.
        $month = (object) $tallies['month'];

        $maySeeSettlements = $user->can(Permission::SettlementsView);

        /** @var Settlement|null $open */
        $open = ! $maySeeSettlements ? null : $merchant->settlements()
            ->whereNotIn('state', [
                SettlementState::Settled->value,
                SettlementState::Cancelled->value,
            ])
            ->orderByDesc('id')
            ->first();

        return CacheableJson::respond($request, response()->json([
            'data' => [
                'merchant' => [
                    'name' => $merchant->name,
                    // The till should say "we are not trading yet" rather
                    // than let a cashier find out on a refused credit.
                    'status' => $merchant->status,
                ],
                'today' => [
                    'credit_count' => (int) $today->credit_count,
                    'eligible_laari' => (int) $today->eligible_laari,
                    'cashback_laari' => (int) $today->cashback_laari,
                ],
                'month' => [
                    'credit_count' => (int) $month->credit_count,
                    'eligible_laari' => (int) $month->eligible_laari,
                    'cashback_laari' => (int) $month->cashback_laari,
                    // The average sale, computed here so every surface
                    // shows the SAME number — integer laari, floor: a
                    // rounded-up average would overstate the takings.
                    'average_eligible_laari' => (int) $month->credit_count === 0
                        ? 0
                        : intdiv((int) $month->eligible_laari, (int) $month->credit_count),
                ],
                // Ages, totals and pending adjustments, straight from the
                // service the panel's own screen reads — but only for
                // accounts the PANEL would show them to. `outstanding` and
                // the open batch answer to settlements.view there
                // (routes/api/settlements.php), and a credits-only cashier
                // must not learn the store's commercial standing merely
                // because they opened the app instead of the panel. Null,
                // not absent: the till renders a stable shape either way.
                //
                // `as_of` is stripped because it is a per-second timestamp,
                // and leaving it in the body makes this response's ETag
                // change on every read — which would defeat the 304 that
                // makes calling home on each resume cheap. The panel keeps
                // it; only this projection drops it.
                'outstanding' => $maySeeSettlements
                    ? Arr::except($summary->forMerchant($merchant), ['as_of'])
                    : null,
                'open_settlement' => ! $maySeeSettlements || $open === null ? null : [
                    'id' => $open->getKey(),
                    'reference' => $open->reference,
                    'state' => $open->state,
                    'amount_due_laari' => (int) $open->amount_due_laari,
                    'due_at' => $open->due_at?->toIso8601ZuluString(),
                ],
            ],
        ]));
    }
}
