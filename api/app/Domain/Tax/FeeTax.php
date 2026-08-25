<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * The GST terms one sale was priced under, and the one place the arithmetic
 * lives (owner, 2026-08-24 — "build the switch, ready to flip").
 *
 * ## The invariant every other file depends on
 *
 * After a split, whatever the treatment:
 *
 *     fee_laari      = Manfaa's NET fee revenue      → account 4100
 *     fee_gst_laari  = the tax owed to MIRA          → account 2300
 *     the merchant owes  cashback + fee + GST
 *
 * That is why nothing downstream had to learn the word "treatment". The §8
 * accrual already debits the receivable for the sum of the three and credits
 * 4100 and 2300 separately; the §7 settlement totals already add all three
 * into `amount_due_laari`; the nightly Reconciler already derives revenue
 * from `Σ fee_laari` and the receivable from `Σ (cashback + fee + gst)`.
 * Splitting the tax out HERE, at pricing time, makes every one of those
 * correct under both treatments without a line of change.
 *
 * The two treatments differ only in what they do to the fee the pricer
 * produced:
 *
 *   ON TOP     net = fee                       gst = ceil(fee * bp / 10000)
 *              — the merchant owes more; our revenue is unchanged.
 *
 *   INCLUSIVE  gst = ceil(fee * bp / (10000+bp))    net = fee - gst
 *              — the merchant owes the same; our revenue drops by the tax.
 *              Equivalently net = floor(fee * 10000 / (10000+bp)), which is
 *              the "fee - round(fee * 10000 / 10800)" the owner wrote at 8%.
 *
 * ## Rounding
 *
 * CEILING, in integer laari, matching how `fee_laari` itself is rounded from
 * `fee_bp` (CashbackCalculator: `intdiv(x * bp + 9999, 10000)`). Rounding the
 * TAX up in both directions is deliberate: a tax authority is never
 * short-changed by a rounding rule, and under `inclusive` it is the platform's
 * own revenue that absorbs the fraction, which is the right party to absorb
 * it.
 *
 * Rounding happens PER LINE on a lined credit and the header is the SUM of
 * the stored line integers (§4: round at the line, then sum), so the header
 * figure equals the sum of the lines exactly — never a second, differently
 * rounded computation over the aggregate.
 *
 * ## Zero is the identity
 *
 * `rateBp = 0` — which is the platform today, and the default stamped on
 * every row that already exists — returns `[$fee, 0]` under BOTH treatments.
 * Re-pricing any historical row from its own stamp therefore reproduces it
 * byte for byte, which is what makes the frozen-at-creation guarantee
 * checkable rather than merely asserted.
 */
final readonly class FeeTax
{
    private function __construct(
        public int $rateBp,
        public FeeTreatment $treatment,
    ) {}

    /** No tax at all — the platform as it stands today. */
    public static function none(): self
    {
        return new self(0, FeeTreatment::OnTop);
    }

    /**
     * The terms as stamped on a row (`fee_gst_bp` + `fee_treatment`), or as
     * read from the live setting. A negative rate is impossible through the
     * table's CHECK constraint; it is floored here anyway, because a tax of
     * "minus eight percent" must never become money.
     */
    public static function of(int $rateBp, FeeTreatment|string|null $treatment): self
    {
        $treatment = match (true) {
            $treatment instanceof FeeTreatment => $treatment,
            is_string($treatment) => FeeTreatment::tryFrom($treatment) ?? FeeTreatment::OnTop,
            default => FeeTreatment::OnTop,
        };

        return new self(max(0, $rateBp), $treatment);
    }

    /** Does this actually charge anything? */
    public function applies(): bool
    {
        return $this->rateBp > 0;
    }

    /**
     * Split a priced fee into what Manfaa keeps and what it owes MIRA.
     *
     * @return array{0: int, 1: int} [net fee revenue, fee GST]
     */
    public function split(int $feeLaari): array
    {
        if ($this->rateBp <= 0 || $feeLaari <= 0) {
            return [$feeLaari, 0];
        }

        return match ($this->treatment) {
            // Ceiling over 10000 — the same expression CashbackCalculator
            // rounds fee_laari with.
            FeeTreatment::OnTop => [
                $feeLaari,
                intdiv($feeLaari * $this->rateBp + 9999, 10000),
            ],
            // Ceiling over (10000 + bp): the tax is the share of a
            // GST-inclusive amount, so the divisor is the inclusive base.
            FeeTreatment::Inclusive => (function () use ($feeLaari): array {
                $divisor = 10000 + $this->rateBp;
                $gst = intdiv($feeLaari * $this->rateBp + $divisor - 1, $divisor);

                return [$feeLaari - $gst, $gst];
            })(),
        };
    }

    /** The tax on a priced fee — the second half of split(). */
    public function gstOn(int $feeLaari): int
    {
        return $this->split($feeLaari)[1];
    }

    /** The revenue left after the tax — the first half of split(). */
    public function netOf(int $feeLaari): int
    {
        return $this->split($feeLaari)[0];
    }

    /**
     * The two columns this stamps onto a transaction (and onto each of its
     * lines). Written at creation and never rewritten.
     *
     * @return array{fee_gst_bp: int, fee_treatment: string}
     */
    public function stamp(): array
    {
        return [
            'fee_gst_bp' => $this->rateBp,
            'fee_treatment' => $this->treatment->value,
        ];
    }
}
