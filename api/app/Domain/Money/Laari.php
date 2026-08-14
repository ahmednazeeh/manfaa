<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * An integer count of laari (1/100 MVR). Never a float, in construction,
 * arithmetic, or formatting. Negative values are permitted — adjustments
 * and reversals legitimately produce them.
 */
final readonly class Laari
{
    private function __construct(private int $value)
    {
        Assert::laariRange($value);
    }

    public static function of(int $value): self
    {
        return new self($value);
    }

    /**
     * Parses a decimal MVR string ("1234.56") via integer math only.
     * Accepts an optional leading minus and 0–2 decimal places; rejects
     * everything else. There is deliberately no float-accepting factory.
     */
    public static function fromMvrString(string $mvr): self
    {
        if (preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,2}))?$/', $mvr, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid MVR amount.', $mvr));
        }

        $whole = (int) $matches[2];
        $fraction = isset($matches[3]) ? (int) str_pad($matches[3], 2, '0') : 0;

        $laari = $whole * 100 + $fraction;

        return new self($matches[1] === '-' ? -$laari : $laari);
    }

    public function add(self $other): self
    {
        return new self($this->value + $other->value);
    }

    /**
     * May produce a negative result — allowed by design for adjustments.
     */
    public function subtract(self $other): self
    {
        return new self($this->value - $other->value);
    }

    public function isNegative(): bool
    {
        return $this->value < 0;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function value(): int
    {
        return $this->value;
    }

    /**
     * Formats as MVR with thousands separators, e.g. 430025 => "4,300.25".
     * Pure string/integer arithmetic — no float ever touches the value.
     */
    public function formatMvr(): string
    {
        $abs = $this->value < 0 ? -$this->value : $this->value;

        $whole = (string) intdiv($abs, 100);
        $cents = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        $grouped = '';
        $length = strlen($whole);
        $lead = $length % 3 === 0 ? 3 : $length % 3;
        $grouped = substr($whole, 0, $lead);

        for ($i = $lead; $i < $length; $i += 3) {
            $grouped .= ','.substr($whole, $i, 3);
        }

        return ($this->value < 0 ? '-' : '').$grouped.'.'.$cents;
    }
}
