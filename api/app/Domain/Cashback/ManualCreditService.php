<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Money\Laari;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * The Phase 0 manual credit path — a merchant user keys a sale in by hand.
 * The mechanics live in CreditRecorder (shared with the §9.2 POS path);
 * what stays here is the manual policy: non-active merchants are refused
 * outright — the lenient record-as-ineligible behaviour (§7) belongs to POS
 * ingestion only, where the till must never error.
 */
final readonly class ManualCreditService
{
    public function __construct(private CreditRecorder $recorder) {}

    /**
     * @param  list<LineInput>|null  $lines  parsed line splits (LineSetParser) — null for a single-rate credit
     * @param  int|null  $overrideRateBp  per-sale rate override in basis points (wire: `cashback_rate_percent`)
     */
    public function credit(
        Merchant $merchant,
        MerchantUser $actor,
        string $customerCode,
        string $invoiceNo,
        Laari $eligible,
        ?Laari $saleAmount,
        CarbonImmutable $occurredAt,
        ?array $lines = null,
        ?int $overrideRateBp = null,
    ): Transaction {
        if ($merchant->status !== 'active') {
            throw MerchantNotActiveException::for($merchant);
        }

        // A self-paused store stops giving cashback (owner decision
        // 2026-08-18) — that is exactly what its customers were told when it
        // paused, and a till that kept crediting would make that a lie.
        if ($merchant->unpublished_at !== null) {
            throw MerchantNotActiveException::paused($merchant);
        }

        return $this->recorder->record(
            merchant: $merchant,
            actor: Actor::merchantUser($actor->id),
            origin: 'manual',
            customerCode: $customerCode,
            invoiceNo: $invoiceNo,
            eligible: $eligible,
            saleAmount: $saleAmount,
            occurredAt: $occurredAt,
            lines: $lines,
            overrideRateBp: $overrideRateBp,
        );
    }
}
