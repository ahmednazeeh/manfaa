<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Models\PlatformBankAccount;
use App\Models\TransferSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * "Is the server actually watching the bank for this transfer, right now?"
 * — asked by the merchant-facing progress endpoints, answered from the same
 * three facts the pollers themselves obey (owner, 2026-08-25).
 *
 * THE HONESTY RULE THIS CLASS EXISTS FOR: a screen must never animate a
 * progress bar over nothing. The apps therefore do not INFER whether a poll
 * is running — they are TOLD, by this class, and when the answer is no they
 * are told which of the four reasons it is so the sentence they print is
 * true ("our team will confirm your transfer shortly", not "checking with
 * your bank…").
 *
 * The three gates are exactly the ones a poll passes through, in the order
 * the poll meets them, which is why the reason is meaningful and not merely
 * a label:
 *
 *   1. TERMINAL — the row is already matched or rejected. Nothing is being
 *      watched because there is nothing left to find. The outcome is what
 *      the screen shows.
 *   2. auto_verify_enabled — the platform-wide switch
 *      ({@see PollSettlementPayment::handle}, {@see PollWalletTopUp::handle},
 *      and both verifiers' first line). Off means the whole feature ships
 *      dark and every transfer waits for a person.
 *   3. THE DESTINATION IS ROUTED — the platform bank account the merchant
 *      paid into must have a verify_profile_id pointing at an ACTIVE
 *      profile, and an account number to read. This mirrors
 *      {@see SettlementPaymentVerifier::destination()} and
 *      {@see WalletTopUpVerifier::destination()} rule for rule: a bank
 *      nobody watches is never auto-verified, and a screen must not pretend
 *      otherwise while the merchant stares at it.
 *   4. A WATCH WAS ACTUALLY STARTED — poll_until is stamped. The window is
 *      written by {@see SettlementAllocator::watchTheBank} and
 *      {@see WalletTopUps::watchTheBank} in the same breath as the poll job
 *      is dispatched, so an unstamped row is the record of a transfer that
 *      arrived while the switch was down and that no job will ever read.
 *      That is `never_watched`, and it must NOT be worded as a check that
 *      ran and found nothing.
 *   5. THE WINDOW IS STILL OPEN — poll_until is in the FUTURE. Past it the
 *      job returns without looking and the claim belongs to the admin queue.
 *
 * Order matters and is deliberate: it is the pollers' own short-circuit
 * order. A row on an unrouted bank whose window has also lapsed reports
 * `no_verify_profile`, because that — not the clock — is why nothing ever
 * looked at it.
 *
 * ONE COUPLING THIS CLASS DEPENDS ON. Gate 2 is read at REQUEST time while
 * the chain behind gate 4 was dispatched at UPLOAD time, and switching the
 * platform flag off does not pause a chain, it ends it. So the flag coming
 * back on re-dispatches the pollers for every still-open window
 * ({@see BankWatchResumer}, called by TransferSettingsController) — without
 * that, this class would answer `watching: true` over rows nobody is reading.
 */
final class BankWatch
{
    /** The platform switch is off: nothing auto-verifies anywhere today. */
    public const string REASON_AUTO_VERIFY_OFF = 'auto_verify_off';

    /** The account this transfer went to has no active read profile. */
    public const string REASON_NO_VERIFY_PROFILE = 'no_verify_profile';

    /** The watch window opened and has since lapsed; a person takes it now. */
    public const string REASON_WINDOW_EXPIRED = 'window_expired';

    /**
     * No watch was ever started on this row — it was uploaded while the
     * switch was down, so no poll job exists for it and none ever will.
     * Distinct from `window_expired` on purpose: nothing ran, and telling a
     * merchant that "the automatic check ran and did not find your transfer"
     * would be a second lie stacked on the first.
     */
    public const string REASON_NEVER_WATCHED = 'never_watched';

    /** Already matched or rejected — there is an outcome, not a wait. */
    public const string REASON_TERMINAL = 'terminal';

    /**
     * @param  string  $state  the row's own pending|matched|rejected
     * @param  ?CarbonInterface  $pollUntil  the row's poll_until
     * @param  ?int  $platformBankAccountId  the account the merchant paid into
     * @return array{bool, ?string} watching, and the machine reason when it is not
     */
    public function on(string $state, ?CarbonInterface $pollUntil, ?int $platformBankAccountId): array
    {
        if ($state !== 'pending') {
            return [false, self::REASON_TERMINAL];
        }

        if (! TransferSetting::current()->auto_verify_enabled) {
            return [false, self::REASON_AUTO_VERIFY_OFF];
        }

        if (! $this->routed($platformBankAccountId)) {
            return [false, self::REASON_NO_VERIFY_PROFILE];
        }

        // No window on the row = no poll was ever dispatched for it. The
        // window and the dispatch are written together, so its absence is
        // evidence, not an unknown.
        if ($pollUntil === null) {
            return [false, self::REASON_NEVER_WATCHED];
        }

        if (CarbonImmutable::now()->greaterThanOrEqualTo($pollUntil)) {
            return [false, self::REASON_WINDOW_EXPIRED];
        }

        return [true, null];
    }

    /**
     * The destination test, in ONE query: the account exists, names a
     * verify profile, that profile is active, and there is an account
     * number to read the history of.
     *
     * Deliberately not a call into either verifier — they hand back the
     * profile MODEL for a job that is about to read the bank, and this is a
     * merchant-facing GET that must touch as little as possible. What is
     * shared is the rule, restated here and asserted by the tests against
     * the same four conditions.
     */
    private function routed(?int $platformBankAccountId): bool
    {
        if ($platformBankAccountId === null) {
            return false;
        }

        $accountNo = PlatformBankAccount::query()
            ->whereKey($platformBankAccountId)
            ->whereNotNull('verify_profile_id')
            ->whereHas('verifyProfile', fn ($query) => $query->where('active', true))
            ->value('account_no');

        return trim((string) $accountNo) !== '';
    }
}
