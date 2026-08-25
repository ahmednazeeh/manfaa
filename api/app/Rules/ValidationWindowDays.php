<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Platform\PlatformConfig;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The ONE rule for "how many days a merchant may hold a sale before its
 * cashback validates", wherever a merchant chooses it: the preferences
 * PATCH, the web signup register, and the till app's register.
 *
 * The ceiling is ADMIN policy (PlatformConfig::defaultValidationWindowDays,
 * default 3), never a constant repeated per door — §11's stale-review
 * window is not raisable by the party it polices, and a signup door that
 * copied "3" would keep letting stores in at 3 the day an admin lowered the
 * platform to 1. Reading it here means a value accepted at signup is a
 * value the preferences screen will still accept tomorrow morning.
 *
 * The floor is 0 — immediate validation, which CreditRecorder handles
 * explicitly — and belongs to the merchant-facing range rather than to
 * PlatformConfig::KEYS, whose 0–30 bounds govern what an ADMIN may set the
 * ceiling itself to.
 *
 * A refusal is a FIELD error naming the whole allowed range, following
 * PercentRate: a merchant who typed 10 needs to be told what they may type
 * instead, and both clients read the same range from
 * `validation_window` on their signup-options endpoint before ever
 * submitting, so the form can show its own limit (in its own language)
 * rather than guessing.
 */
final readonly class ValidationWindowDays implements ValidationRule
{
    /** Immediate validation. A merchant may always tighten to zero. */
    public const int MIN_DAYS = 0;

    public function __construct(private ?PlatformConfig $config = null) {}

    /** The ceiling a merchant may choose, today, per platform settings. */
    public static function maxDays(): int
    {
        return app(PlatformConfig::class)->defaultValidationWindowDays();
    }

    /** What a store that says nothing at signup is created with. */
    public static function defaultDays(): int
    {
        return app(PlatformConfig::class)->newMerchantValidationWindowDays();
    }

    /**
     * The bounds as both signup doors publish them, so a form can render
     * its own limit before the merchant submits anything.
     *
     * @return array{min_days: int, max_days: int, default_days: int}
     */
    public static function bounds(): array
    {
        return [
            'min_days' => self::MIN_DAYS,
            'max_days' => self::maxDays(),
            'default_days' => self::defaultDays(),
        ];
    }

    /** The refusal, naming the range a merchant may choose from. */
    public static function message(int $maxDays): string
    {
        return sprintf(
            'The validation window must be a whole number of days between %d and %d.',
            self::MIN_DAYS,
            $maxDays,
        );
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $max = ($this->config ?? app(PlatformConfig::class))->defaultValidationWindowDays();

        if (! self::isWholeNumber($value)) {
            $fail(self::message($max));

            return;
        }

        $days = (int) $value;

        if ($days < self::MIN_DAYS || $days > $max) {
            $fail(self::message($max));
        }
    }

    /**
     * Days are whole. Accepts the numeric STRING a form posts ("3") exactly
     * as Laravel's own `integer` rule does, and refuses 2.5, "2.5", true
     * and everything else — a half-day window has no meaning to the
     * validation sweep, which counts in whole days.
     */
    private static function isWholeNumber(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1;
    }
}
