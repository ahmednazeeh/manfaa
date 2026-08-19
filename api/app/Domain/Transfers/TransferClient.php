<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Models\TransferProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The bank transfer API (owner spec 2026-08-19).
 *
 * Three upstream behaviours this exists to get right, because each one pays
 * somebody twice if it is read wrong.
 *
 * **A repeated `internal_ref` answers 409**, carrying
 * `existing.{status,trx_id,error_code,attempts}`. When that existing row is
 * a SUCCESS the money already moved: we adopt its `trx_id` and report Sent.
 * Treating that 409 as a failure and retrying is the textbook way to double
 * pay.
 *
 * **Dual control answers 200 with `pending_approval`**, an empty trx_id and
 * an `approval_id`. That transfer is PARKED, not failed. The approval_id is
 * a queue record id and must never be recorded as a transaction reference.
 *
 * **`from_account` is ignored on /bml/transfer**, a different upstream. We
 * send it anyway when the profile has one — being explicit about which
 * account is debited is what makes a bank statement reconcilable — and
 * simply accept that one upstream disregards it.
 *
 * The API key lives in the environment, never in the database: `x-api-key`
 * is the whole of the upstream's authentication.
 */
final readonly class TransferClient
{
    /**
     * A transfer can legitimately take two minutes (owner 2026-08-19), so we
     * wait three.
     *
     * This was 30 seconds, which was actively dangerous: a slow but perfectly
     * successful transfer would be abandoned on our side and filed as "the
     * bank did not answer" — the one outcome we treat as needing review
     * precisely because the money may have moved. Hanging up early on a call
     * that is still working MANUFACTURES that uncertainty on every slow
     * transfer, rather than suffering it rarely.
     */
    private const int TIMEOUT_SECONDS = 180;

    /**
     * How long to wait for the far end to pick up at all.
     *
     * Deliberately short and separate from the ceiling above: with the
     * WireGuard peer down, every call fails here in seconds instead of
     * hanging a worker for three minutes each.
     */
    private const int CONNECT_TIMEOUT_SECONDS = 10;

    public function send(
        TransferProfile $profile,
        string $account,
        int $amountLaari,
        string $internalRef,
        ?string $beneficiaryName = null,
        string $remarks = 'Manfaa payout',
    ): TransferResult {
        $key = (string) config('services.transfer.api_key');

        if ($key === '') {
            return new TransferResult(
                TransferOutcome::FailedRetryable,
                errorCode: 'no_api_key',
                message: 'No transfer API key is configured.',
            );
        }

        $payload = array_filter([
            'account' => $account,
            'amount' => self::amount($amountLaari),
            // The idempotency key. A stable business key we can regenerate
            // is what makes a retry safe.
            'internal_ref' => $internalRef,
            'remarks' => $remarks,
            'benef_name' => $beneficiaryName,
            // Ignored by design on /bml/transfer, which is a different
            // upstream. Sent anyway for MIB, where "whatever that profile's
            // default account is today" is not a thing to reconcile a bank
            // statement against.
            'from_account' => $profile->isBml() ? null : $profile->from_account,
            // BML picks the debited account by profile name instead.
            'profile' => $profile->isBml() ? $profile->upstreamProfile() : null,
        ], fn ($value): bool => $value !== null && $value !== '');

        try {
            $response = Http::withHeaders(['x-api-key' => $key])
                ->timeout(self::TIMEOUT_SECONDS)
                // Connecting is not the same as working. A host that is not
                // there refuses or fails to route in moments, so waiting the
                // full ceiling for it buys nothing — while a transfer that is genuinely working needs its three minutes. Two limits,
                // because they answer two different questions.
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->post($profile->endpoint(), $payload);
        } catch (Throwable $e) {
            // A timeout is the dangerous case: the bank may well have moved
            // the money while we stopped listening. NEVER retryable on its
            // own — the next attempt re-sends the same internal_ref, and the
            // upstream's 409 is what will tell us the truth.
            Log::warning('Transfer call did not complete', [
                'internal_ref' => $internalRef,
                'error' => $e->getMessage(),
            ]);

            return new TransferResult(
                TransferOutcome::FailedNeedsReview,
                errorCode: 'no_response',
                message: 'The bank did not answer. Check before sending again.',
            );
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        // A repeat. What matters is what the FIRST attempt did.
        if ($response->status() === 409) {
            return self::interpretDuplicate($body);
        }

        if (! $response->successful()) {
            $errorCode = (string) ($body['error_code'] ?? $response->status());

            // The SAME allow-list the duplicate path uses. A bank that
            // refuses an account number outright refused it before moving
            // anything, whether we are hearing that for the first time or
            // the second — and treating those two differently would leave
            // every bad account number in a batch stuck pending forever
            // instead of failing and re-queueing into the next run.
            return new TransferResult(
                self::provesNoDebit($errorCode)
                    ? TransferOutcome::FailedRetryable
                    : TransferOutcome::FailedNeedsReview,
                errorCode: $errorCode,
                message: (string) ($body['error'] ?? $body['message'] ?? 'The transfer was refused.'),
            );
        }

        $status = (string) ($body['status'] ?? '');

        if ($status === 'pending_approval') {
            return new TransferResult(
                TransferOutcome::PendingApproval,
                // Deliberately NOT trxId: an approvals-queue record id is
                // not a transaction reference, and filing it as one would
                // report an unmade payment as made.
                approvalId: (string) ($body['approval_id'] ?? ''),
                message: 'Waiting for a second approver.',
            );
        }

        $trxId = (string) ($body['trx_id'] ?? '');

        if ($status === 'success' && $trxId !== '') {
            return new TransferResult(TransferOutcome::Sent, trxId: $trxId);
        }

        // A 200 we do not recognise. Not obviously a failure, so not
        // obviously safe to repeat.
        return new TransferResult(
            TransferOutcome::FailedNeedsReview,
            errorCode: (string) ($body['error_code'] ?? 'unrecognised_response'),
            message: 'The bank answered in a way we do not recognise.',
        );
    }

    /**
     * The 409 path: this internal_ref has been seen before.
     *
     * @param  array<string, mixed>  $body
     */
    private static function interpretDuplicate(array $body): TransferResult
    {
        $existing = is_array($body['existing'] ?? null) ? $body['existing'] : [];
        $status = (string) ($existing['status'] ?? '');
        $trxId = (string) ($existing['trx_id'] ?? '');

        if ($status === 'success') {
            // Already paid. Adopt the reference rather than sending again —
            // this single branch is the difference between idempotent and
            // double-paying.
            return new TransferResult(
                TransferOutcome::Sent,
                trxId: $trxId,
                wasDuplicate: true,
            );
        }

        if ($status === 'pending_approval') {
            return new TransferResult(
                TransferOutcome::PendingApproval,
                approvalId: (string) ($existing['approval_id'] ?? ''),
                wasDuplicate: true,
                message: 'Already waiting for a second approver.',
            );
        }

        // A failed prior attempt. Only an error code that PROVES no debit
        // occurred may pass through again; anything else is a person's
        // decision, not ours.
        $errorCode = (string) ($existing['error_code'] ?? '');

        return new TransferResult(
            self::provesNoDebit($errorCode)
                ? TransferOutcome::FailedRetryable
                : TransferOutcome::FailedNeedsReview,
            errorCode: $errorCode,
            wasDuplicate: true,
            message: 'A previous attempt with this reference failed.',
        );
    }

    /**
     * Error codes that mean the money certainly did not move.
     *
     * Deliberately a SHORT allow-list rather than a deny-list: an unknown
     * code has to mean "a human looks", because the cost of guessing wrong
     * is paying a customer twice.
     */
    private static function provesNoDebit(string $errorCode): bool
    {
        return in_array($errorCode, [
            'invalid_account',
            'invalid_amount',
            'missing_field',
            'validation_failed',
        ], true);
    }

    /**
     * Integer laari → the decimal string the API expects.
     *
     * Formatted from the integer rather than divided into a float: laari are
     * exact and floats are not, and this is the one place our money crosses
     * into somebody else's number system.
     */
    private static function amount(int $laari): string
    {
        return sprintf('%d.%02d', intdiv($laari, 100), $laari % 100);
    }
}
