<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Money\Laari;

/**
 * One prompt-payment discount evaluation (PLAN §1). Immutable, integer laari
 * only, and carrying the WHY as well as the how much: a refusal is an answer
 * the merchant is entitled to, not an absence.
 *
 * The split matters when fee GST is ever switched on:
 *
 *   fee_discount_laari  = ceiling(fee_total_laari * rate_bp / 10000)
 *   gst_relief_laari    = ceiling(fee_gst_total_laari * fee_discount / fee_total)
 *   discount_laari      = fee_discount + gst_relief, capped at what is due
 *
 * GST is zero everywhere today, so discount_laari IS the fee discount and the
 * documented formula reads exactly as PLAN §1 states it.
 */
final readonly class PromptDiscountResult
{
    private function __construct(
        public bool $eligible,
        public PromptDiscountReason $reason,
        public int $rateBp,
        public int $maxAgeDays,
        public int $feeTotalLaari,
        public int $feeDiscountLaari,
        public int $gstReliefLaari,
        public int $discountLaari,
    ) {}

    public static function granted(
        int $rateBp,
        int $maxAgeDays,
        int $feeTotalLaari,
        int $feeDiscountLaari,
        int $gstReliefLaari,
    ): self {
        return new self(
            eligible: true,
            reason: PromptDiscountReason::Eligible,
            rateBp: $rateBp,
            maxAgeDays: $maxAgeDays,
            feeTotalLaari: $feeTotalLaari,
            feeDiscountLaari: $feeDiscountLaari,
            gstReliefLaari: $gstReliefLaari,
            discountLaari: $feeDiscountLaari + $gstReliefLaari,
        );
    }

    public static function refused(PromptDiscountReason $reason, int $rateBp, int $maxAgeDays, int $feeTotalLaari): self
    {
        return new self(
            eligible: false,
            reason: $reason,
            rateBp: $rateBp,
            maxAgeDays: $maxAgeDays,
            feeTotalLaari: $feeTotalLaari,
            feeDiscountLaari: 0,
            gstReliefLaari: 0,
            discountLaari: 0,
        );
    }

    /**
     * The discount can never hand money back. A batch already netted to (or
     * near) nothing by §7 credit adjustments has less receivable left than
     * the fee discount is worth, and a batch must never leave submit owing
     * the merchant money — so the grant is capped at what is actually still
     * due. The fee leg is consumed first, the GST leg second, which is the
     * same order the ledger posts them in.
     */
    public function cappedTo(int $maxDiscountLaari): self
    {
        $capped = max(0, min($this->discountLaari, $maxDiscountLaari));

        if ($capped === $this->discountLaari) {
            return $this;
        }

        $feeLeg = min($this->feeDiscountLaari, $capped);

        return new self(
            eligible: $this->eligible,
            reason: $this->reason,
            rateBp: $this->rateBp,
            maxAgeDays: $this->maxAgeDays,
            feeTotalLaari: $this->feeTotalLaari,
            feeDiscountLaari: $feeLeg,
            gstReliefLaari: $capped - $feeLeg,
            discountLaari: $capped,
        );
    }

    /**
     * The rate to STAMP on the settlement: the rate a batch was actually
     * priced at, or null when it was priced at no discount at all. Reading
     * the platform setting back later would answer a different question —
     * what the rate is now, not what this batch got.
     */
    public function storedRateBp(): ?int
    {
        return $this->discountLaari > 0 ? $this->rateBp : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'reason_code' => $this->reason->value,
            'rate_bp' => $this->rateBp,
            'max_age_days' => $this->maxAgeDays,
            'discount_laari' => $this->discountLaari,
            'discount_mvr' => Laari::of($this->discountLaari)->formatMvr(),
            'fee_discount_laari' => $this->feeDiscountLaari,
            'gst_relief_laari' => $this->gstReliefLaari,
        ];
    }
}
