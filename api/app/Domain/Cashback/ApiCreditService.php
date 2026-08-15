<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Money\Laari;
use App\Models\Merchant;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * The §9.2 API ingestion path: same rate-at-occurred_at resolution, ceiling
 * money, below-minimum handling and backdated routing (PLAN §1) as the
 * manual path — all shared through CreditRecorder, which is deliberately the
 * ONE place those rules live.
 *
 * customer_ref resolution is dual (original-spec online model 3): a 6-digit
 * customer code, or a Maldivian mobile normalised to +960 E.164
 * (CustomerRef). Phone-keyed credits record origin 'api_phone'; code-keyed
 * stay 'pos'. An unknown phone answers exactly like an unknown code —
 * CustomerNotFoundException, same shape — so the write path leaks nothing
 * the rate-limited lookup endpoint does not already answer.
 *
 * The one POS-specific rule is §7 suspension semantics: suspension stops
 * cashback CREATION, not ingestion. A suspended merchant's till keeps
 * POSTing; the sale is recorded with zero cashback, zero fee, reason
 * merchant_suspended, and an immediate terminal reversal (the exact
 * below-minimum mechanics), so the cashier sees something truthful instead
 * of an error. Only a closed merchant is refused outright.
 */
final readonly class ApiCreditService
{
    public function __construct(private CreditRecorder $recorder) {}

    /**
     * @param  list<LineInput>|null  $lines  parsed line splits (LineSetParser) — null for a single-rate credit
     * @param  int|null  $overrideRateBp  per-sale rate override in basis points (wire: `cashback_rate_percent`)
     */
    public function credit(
        Merchant $merchant,
        Actor $actor,
        string $customerRef,
        string $invoiceNo,
        Laari $eligible,
        ?Laari $saleAmount,
        CarbonImmutable $occurredAt,
        ?int $branchId = null,
        ?string $idempotencyKey = null,
        ?array $lines = null,
        ?int $overrideRateBp = null,
    ): Transaction {
        // Only active and suspended merchants may reach the recorder (§7:
        // suspension stops cashback creation, not ingestion). Closed is
        // terminal, and the pre-approval statuses (draft / pending_review /
        // rejected) have never traded — a credential should not even exist
        // for them, but defence in depth refuses the write outright.
        if (! in_array($merchant->status, ['active', 'suspended'], true)) {
            throw MerchantNotActiveException::for($merchant);
        }

        // Controller validation already rejects malformed refs; a malformed
        // ref reaching here gets the same terminal answer as an unknown one.
        $ref = CustomerRef::parse($customerRef)
            ?? throw CustomerNotFoundException::forCode($customerRef);

        // Phone refs resolve here (CreditRecorder only knows codes, and its
        // code path stays untouched); the recorder re-reads the customer by
        // the code we hand it — same unique row, one source of truth.
        $customerCode = $ref->normalized;

        if ($ref->isPhone) {
            $customer = $ref->resolve() ?? throw CustomerNotFoundException::forCode($customerRef);
            $customerCode = $customer->customer_code;
        }

        return $this->recorder->record(
            merchant: $merchant,
            actor: $actor,
            origin: $ref->origin(),
            customerCode: $customerCode,
            invoiceNo: $invoiceNo,
            eligible: $eligible,
            saleAmount: $saleAmount,
            occurredAt: $occurredAt,
            branchId: $branchId,
            idempotencyKey: $idempotencyKey,
            ineligibleReason: $merchant->status === 'suspended' ? 'merchant_suspended' : null,
            lines: $lines,
            overrideRateBp: $overrideRateBp,
        );
    }
}
