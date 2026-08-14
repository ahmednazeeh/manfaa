<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Money\Laari;
use App\Models\Merchant;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * The §9.2 POS ingestion path: origin 'pos', same rate-at-occurred_at
 * resolution, ceiling money, below-minimum and stale routing as the manual
 * path — all shared through CreditRecorder.
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
    ): Transaction {
        if ($merchant->status === 'closed') {
            throw MerchantNotActiveException::for($merchant);
        }

        return $this->recorder->record(
            merchant: $merchant,
            actor: $actor,
            origin: 'pos',
            customerCode: $customerRef,
            invoiceNo: $invoiceNo,
            eligible: $eligible,
            saleAmount: $saleAmount,
            occurredAt: $occurredAt,
            branchId: $branchId,
            idempotencyKey: $idempotencyKey,
            ineligibleReason: $merchant->status === 'suspended' ? 'merchant_suspended' : null,
        );
    }
}
