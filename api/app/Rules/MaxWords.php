<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A word ceiling, not a character one: the owner asked for "no more than
 * 180 words", and counting characters would refuse a legitimate short
 * description written in a language whose words are long — Dhivehi
 * included — while allowing 180 words of very short ones.
 */
final class MaxWords implements ValidationRule
{
    public function __construct(private readonly int $max) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // Any run of whitespace separates words, in every script we serve.
        $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) > $this->max) {
            $fail("The :attribute may not be longer than {$this->max} words.");
        }
    }
}
