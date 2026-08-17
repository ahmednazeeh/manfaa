<?php

declare(strict_types=1);

namespace App\Domain\Standing;

use App\Domain\Cashback\TransactionState;
use App\Domain\MerchantAccess\Permission;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The §7 escalation ladder — day 10 reminder, day 13 urgent reminder, day 15
 * payment due.
 *
 * The rule, exactly as implemented:
 *
 * - A merchant's position on the ladder is measured from the OLDEST
 *   clock_start_at among their payable_unfunded transactions — the debt
 *   that has been running longest drives the escalation.
 * - Whole days elapsed since that clock start select at most ONE notice type
 *   (the current bucket): 10–12 days → reminder_day10, 13–14 → urgent_day13,
 *   15+ → due_day15. The scheduler runs this daily, so under normal operation
 *   each type fires exactly once per cycle as the days tick past.
 * - Dedupe: the notice is skipped when a notice of the same type already
 *   exists with sent_at on or after that oldest clock_start_at. Once the
 *   merchant clears their debt and a NEW cycle starts, its clock_start_at is
 *   later than every old notice, so the ladder fires afresh.
 *
 * Day counting is CALENDAR days in the business timezone — the §7 table's
 * "day 10 / 13 / 15" means the Nth business day after the day the clock
 * started, not clock_start + N×24h as an instant. A pure duration measured
 * at the 09:00 run would land one day late for any clock started after
 * 09:00, and the day-15 "payment due" notice would then fire AFTER the
 * day-16 00:15 automatic suspension — the evidenced notice trail must show
 * the demand for payment preceding the suspension, always. Maldives has no
 * DST, so calendar arithmetic here is exact.
 */
final readonly class EscalationLadder
{
    public function __construct(
        private NoticeRecorder $notices,
        private NotificationService $notifications,
    ) {}

    /**
     * @return int the number of notices sent
     */
    public function run(): int
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $today = CarbonImmutable::now('UTC')->setTimezone($timezone)->startOfDay();

        $rows = DB::table('transactions')
            ->where('state', TransactionState::PayableUnfunded->value)
            ->whereNotNull('clock_start_at')
            ->groupBy('merchant_id')
            ->selectRaw(<<<'SQL'
                merchant_id,
                MIN(clock_start_at) AS oldest_clock_start_at,
                MIN(due_at) AS oldest_due_at,
                COUNT(*) AS open_count,
                COALESCE(SUM(cashback_laari + fee_laari + fee_gst_laari), 0) AS outstanding_laari
                SQL)
            ->get();

        $sent = 0;

        foreach ($rows as $row) {
            $clockStart = CarbonImmutable::parse($row->oldest_clock_start_at)->utc();
            $elapsedDays = (int) $clockStart->setTimezone($timezone)->startOfDay()->diffInDays($today);

            $type = match (true) {
                $elapsedDays >= 15 => 'due_day15',
                $elapsedDays >= 13 => 'urgent_day13',
                $elapsedDays >= 10 => 'reminder_day10',
                default => null,
            };

            if ($type === null) {
                continue;
            }

            $alreadySent = MerchantNotice::query()
                ->where('merchant_id', $row->merchant_id)
                ->where('type', $type)
                ->where('sent_at', '>=', $clockStart)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $oldestDueAt = CarbonImmutable::parse($row->oldest_due_at)->utc();

            $this->notices->record((int) $row->merchant_id, $type, [
                'days_since_clock_start' => $elapsedDays,
                'oldest_clock_start_at' => $clockStart->toIso8601String(),
                'oldest_due_at' => $oldestDueAt->toIso8601String(),
                'open_transactions' => (int) $row->open_count,
                'outstanding_laari' => (int) $row->outstanding_laari,
            ]);

            // MR4: the rung reaches phones as well as the notice table. Same
            // moment, same data — the outstanding total is the row's summed
            // stored integers (§10) and the date is the recorded oldest
            // due_at as a business-tz day. This sits BEHIND the alreadySent
            // gate above, so the ladder's own dedupe is the push's dedupe: a
            // second run the same day records nothing and pushes nothing.
            $merchant = Merchant::query()->find((int) $row->merchant_id);

            if ($merchant !== null) {
                $this->notifications->sendToMerchantStaff(
                    NotificationTemplateKey::from($type),
                    $merchant,
                    [
                        'amount' => NotificationService::money((int) $row->outstanding_laari),
                        'date' => $oldestDueAt->setTimezone($timezone)->format('j M Y'),
                    ],
                    Permission::SettlementsView,
                );
            }

            $sent++;
        }

        return $sent;
    }
}
