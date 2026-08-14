<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Cashback\TransactionState;
use App\Models\Transaction;

/**
 * The §6 mapping from internal transaction state to what the customer sees.
 * Deliberately simpler than the state machine: four internal states collapse
 * into "pending", and written_off surfaces as "unpaid" with §9.4 factual
 * wording left to the frontend.
 *
 * Reasons are KEYS, not prose — the frontend translates them (EN/DV) and
 * fills in the merchant name and window, e.g. merchant_settlement_window →
 * "Store X settles within 15 days".
 */
final class CustomerFacingStatus
{
    /** Internal states the customer sees as a single conditional "pending". */
    public const array PENDING_STATES = [
        'tracked',
        'awaiting_validation',
        'payable_unfunded',
        'on_hold',
    ];

    public static function status(TransactionState $state): string
    {
        return match ($state) {
            TransactionState::Tracked,
            TransactionState::AwaitingValidation,
            TransactionState::PayableUnfunded,
            TransactionState::OnHold => 'pending',
            TransactionState::Confirmed => 'confirmed',
            TransactionState::Paid => 'paid',
            TransactionState::Reversed => 'reversed',
            TransactionState::WrittenOff => 'unpaid',
        };
    }

    /**
     * The per-item reason line. Null for confirmed/paid — a settled reward
     * needs no qualifier. Reversed rows echo their stored reason_code
     * (customer_refund, below_minimum, …) so the app can say why.
     */
    public static function reasonKey(Transaction $transaction): ?string
    {
        return match ($transaction->state) {
            TransactionState::Tracked,
            TransactionState::AwaitingValidation => 'validation_window',
            TransactionState::PayableUnfunded => 'merchant_settlement_window',
            TransactionState::OnHold => 'under_review',
            TransactionState::WrittenOff => 'merchant_not_settled',
            TransactionState::Reversed => $transaction->reason_code ?? 'reversed',
            TransactionState::Confirmed,
            TransactionState::Paid => null,
        };
    }
}
