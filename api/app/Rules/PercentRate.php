<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Money\Percent;
use App\Domain\Money\Rate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The wire grammar for a rate (PLAN §1 "API wire format"): a 2-decimal
 * percent, sent as a string ("2", "2.5", "2.50") or as a JSON number
 * (2, 2.5). Basis points never appear in a request.
 *
 * A malformed, negative, over-precise or out-of-range value is a FIELD
 * error inside the ordinary `validation_failed` envelope — vendors get the
 * offending field name and a message naming the legal range, which is more
 * useful than a bespoke top-level machine code for a typo.
 *
 * Conversion itself is Percent's job (pure integer math); this rule only
 * asks Percent whether the value is legal.
 */
final readonly class PercentRate implements ValidationRule
{
    private function __construct(private int $minBp, private int $maxBp) {}

    /**
     * A customer cashback rate: §4's 0.50%–20.00%.
     */
    public static function cashback(): self
    {
        return new self(Rate::MIN_CASHBACK_BP, Rate::MAX_CASHBACK_BP);
    }

    /**
     * A platform fee rate: 0.01%–20.00% (fees sit below the cashback floor).
     */
    public static function fee(): self
    {
        return new self(Rate::MIN_FEE_BP, Rate::MAX_CASHBACK_BP);
    }

    /**
     * A rate with bounds of its own — a platform setting whose own range
     * governs (PlatformConfig::KEYS), including a 0 floor where zero
     * switches a feature off.
     */
    public static function between(int $minBp, int $maxBp): self
    {
        return new self($minBp, $maxBp);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Percent::isValid($value, $this->minBp, $this->maxBp)) {
            $fail(sprintf(
                'The :attribute must be a percent between %s and %s with at most 2 decimal places, for example "%s".',
                Percent::format($this->minBp),
                Percent::format($this->maxBp),
                Percent::format($this->minBp),
            ));
        }
    }
}
