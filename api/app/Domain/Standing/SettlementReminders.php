<?php

declare(strict_types=1);

namespace App\Domain\Standing;

use App\Domain\Cashback\TransactionState;
use App\Domain\MerchantAccess\Permission;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\NotEligibleForSettlementException;
use App\Domain\Settlement\SettlementPreview;
use App\Domain\Settlement\TransactionAge;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The MR4 deadline reminders — the owner's "1 day left" case, run daily at
 * 09:00 business time beside the §7 escalation ladder.
 *
 * Per merchant with outstanding payables, position is the age in whole
 * business-timezone days of the OLDEST payable_unfunded clock_start_at —
 * the same measure, over the same rows, as EscalationLadder. Two moments:
 *
 * - `prompt_discount_expiring`, the morning of the LAST day the prompt
 *   discount is still earnable: age == prompt_discount_max_age_days − 1
 *   (day 9 on defaults — eligibility dies when the oldest line REACHES the
 *   max age, PLAN §1). The saving quoted is the settle-all preview's own
 *   `discount_laari` — the server figure the settlement screen shows — and
 *   a preview that grants nothing sends nothing.
 * `settlement_due_soon` used to live here too, on days 13 and 14 — the same
 * two mornings EscalationLadder already sends `urgent_day13` on. Merchants
 * were getting two pushes and two texts a day from two schedulers saying
 * much the same thing (owner, 2026-08-20). The ladder is the single
 * escalation authority now; this file keeps only the discount deadline,
 * which the ladder has no equivalent for.
 *
 * The threshold comes from PlatformConfig at run time, never a constant.
 *
 * Dedupe is EscalationLadder's mechanism — an evidenced merchant_notices
 * row consulted before sending — scoped per BUSINESS DAY. Within a cycle
 * each calendar day maps to exactly one age, so "already sent this business
 * day" IS "already sent for this cycle-day": re-running the command the
 * same day sends nothing new.
 */
final readonly class SettlementReminders
{
    public function __construct(
        private PlatformConfig $config,
        private SettlementPreview $preview,
        private NoticeRecorder $notices,
        private NotificationService $notifications,
    ) {}

    /**
     * @return int the number of reminders sent
     */
    public function run(): int
    {
        $timezone = TransactionAge::timezone();
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone($timezone)->startOfDay();

        // Read once per run, not per merchant: one consistent ruler for the
        // whole walk even if an admin edits a setting mid-run.
        $discountMaxAgeDays = $this->config->promptDiscountMaxAgeDays();
        $dueDays = $this->config->settlementDueDays();

        $rows = DB::table('transactions')
            ->where('state', TransactionState::PayableUnfunded->value)
            ->whereNotNull('clock_start_at')
            ->groupBy('merchant_id')
            ->selectRaw(<<<'SQL'
                merchant_id,
                MIN(clock_start_at) AS oldest_clock_start_at,
                COALESCE(SUM(cashback_laari + fee_laari + fee_gst_laari), 0) AS outstanding_laari
                SQL)
            ->get();

        $sent = 0;

        foreach ($rows as $row) {
            $clockStart = CarbonImmutable::parse($row->oldest_clock_start_at)->utc();
            $age = TransactionAge::days($clockStart, $now, $timezone);

            if ($age === $discountMaxAgeDays - 1
                && $this->remindDiscountExpiring((int) $row->merchant_id, $age, $today)) {
                $sent++;
            }

        }

        return $sent;
    }

    /**
     * "Settle today and keep your discount — you save MVR X."
     *
     * X is the settle-all preview's `discount_laari`: the number the same
     * merchant sees on the settlement screen, computed by the same
     * SettlementBuilder rules submit() re-runs for real (§10: the copy
     * quotes a server figure, it never derives one). A merchant whose
     * preview refuses the discount — something frozen on an open batch, the
     * incentive switched off, nothing eligible at all — gets silence, not a
     * promise the submit path would break.
     */
    private function remindDiscountExpiring(int $merchantId, int $age, CarbonImmutable $today): bool
    {
        $key = NotificationTemplateKey::PromptDiscountExpiring;

        if ($this->alreadySentToday($merchantId, $key->value, $today)) {
            return false;
        }

        $merchant = Merchant::query()->find($merchantId);

        if ($merchant === null) {
            return false;
        }

        try {
            $preview = $this->preview->for($merchant, null);
        } catch (NotEligibleForSettlementException) {
            return false; // nothing this store could settle right now
        }

        $saving = (int) $preview['discount_laari'];

        if ($saving <= 0) {
            return false;
        }

        $this->notices->record($merchantId, $key->value, [
            'age_days' => $age,
            'discount_laari' => $saving,
            'amount_due_laari' => (int) $preview['amount_due_laari'],
        ], channel: 'push');

        $this->notifications->sendToMerchantStaff($key, $merchant, [
            'amount' => NotificationService::money($saving),
            'rate' => self::rate((string) $preview['discount']['rate_percent']),
        ], Permission::SettlementsView);

        return true;
    }

    /**
     * At most one send per merchant per template per business day — the
     * merchant_notices row written alongside every send is the ladder's own
     * dedupe evidence, consulted the ladder's own way.
     */
    private function alreadySentToday(int $merchantId, string $type, CarbonImmutable $today): bool
    {
        return MerchantNotice::query()
            ->where('merchant_id', $merchantId)
            ->where('type', $type)
            // ->utc(): sent_at is stored UTC and a bound Carbon is formatted
            // as its own wall clock, so binding business-tz midnight directly
            // would slide the day boundary five hours late.
            ->where('sent_at', '>=', $today->utc())
            ->exists();
    }

    /**
     * "5.00" reads like an invoice; "5%" reads like a sentence. Copy only —
     * the rate never prices anything here (§10).
     */
    private static function rate(string $percent): string
    {
        $trimmed = rtrim(rtrim($percent, '0'), '.');

        return ($trimmed === '' ? '0' : $trimmed).'%';
    }
}
