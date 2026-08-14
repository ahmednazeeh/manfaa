<?php

declare(strict_types=1);

namespace App\Domain\Adjustment;

use App\Models\Adjustment;
use App\Models\Transaction;

/**
 * What a §9.2 reversal request actually did — reversed the transaction in
 * place, or created (or found) a credit adjustment because the line is
 * locked in a non-draft settlement or the reward is already confirmed.
 */
final readonly class ReversalOutcome
{
    public const string REVERSED = 'reversed';

    public const string ADJUSTMENT_CREATED = 'adjustment_created';

    public const string CAUSE_LOCKED_IN_SETTLEMENT = 'locked_in_settlement';

    public const string CAUSE_ALREADY_CONFIRMED = 'already_confirmed';

    private function __construct(
        public string $outcome,
        public ?string $cause,
        public Transaction $transaction,
        public ?Adjustment $adjustment,
    ) {}

    public static function reversed(Transaction $transaction): self
    {
        return new self(self::REVERSED, null, $transaction, null);
    }

    public static function adjustmentCreated(Transaction $transaction, Adjustment $adjustment, string $cause): self
    {
        return new self(self::ADJUSTMENT_CREATED, $cause, $transaction, $adjustment);
    }
}
