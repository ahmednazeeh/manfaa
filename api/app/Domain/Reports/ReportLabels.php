<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Cashback\TransactionState;
use App\Domain\Payout\PayoutBatchState;
use App\Domain\Platform\FeePromotionKind;
use App\Domain\Settlement\SettlementState;
use Illuminate\Support\Str;

/**
 * Words, not codes (PLAN §13b task #22). Everything a report prints into a
 * cell is a phrase a person can read: `payable_unfunded` never reaches a
 * finance spreadsheet, and neither does `api_phone`.
 *
 * The state enums already carry their own label(); this adds the ones that
 * have none (transaction origin, payout item state, wallet payout state)
 * and gives every caller one door.
 */
final class ReportLabels
{
    public static function transactionState(TransactionState|string|null $state): string
    {
        if ($state === null) {
            return '';
        }

        $state = $state instanceof TransactionState ? $state : TransactionState::tryFrom($state);

        return $state?->label() ?? '';
    }

    public static function settlementState(SettlementState|string|null $state): string
    {
        if ($state === null) {
            return '';
        }

        $state = $state instanceof SettlementState ? $state : SettlementState::tryFrom($state);

        return $state?->label() ?? '';
    }

    public static function payoutBatchState(PayoutBatchState|string|null $state): string
    {
        if ($state === null) {
            return '';
        }

        $state = $state instanceof PayoutBatchState ? $state : PayoutBatchState::tryFrom($state);

        return $state?->label() ?? '';
    }

    /**
     * payout_items.state — pending | sent | paid | failed. The enum carries
     * no label(), and every value is already one plain word.
     */
    public static function payoutItemState(mixed $state): string
    {
        $value = $state instanceof \BackedEnum ? (string) $state->value : (string) $state;

        return match ($value) {
            'pending' => 'pending',
            'sent' => 'sent',
            'paid' => 'paid',
            'failed' => 'failed',
            '' => '',
            default => self::humanise($value),
        };
    }

    /**
     * customer_payouts.state — the withdrawal queue. `refunded` is the one
     * that must not read as `failed`: the money went back to the wallet and
     * the row is finished (migration 2026_08_19_070000).
     */
    public static function walletPayoutState(?string $state): string
    {
        return match ($state) {
            'pending' => 'pending',
            'processing' => 'processing',
            'pending_approval' => 'awaiting approval',
            'sent' => 'paid',
            'failed' => 'failed',
            'refunded' => 'refunded to wallet',
            'cancelled' => 'cancelled',
            null, '' => '',
            default => self::humanise($state),
        };
    }

    public static function origin(?string $origin): string
    {
        return match ($origin) {
            'pos' => 'POS',
            // The `manual` origin is the "Credit Customer" flow in the
            // merchant APP and panel — a real cashier crediting a real sale
            // through Manfaa's own software, not a hand-keyed correction.
            // "Manual" read as the latter, which is the one thing it never
            // is (owner, 2026-08-24).
            'manual' => 'Manfaa App',
            'online_link' => 'Online link',
            'api_phone' => 'API (phone)',
            'card_linked' => 'Card linked',
            'claim' => 'Claim',
            'marketplace' => 'Marketplace',
            null, '' => '',
            default => self::humanise($origin),
        };
    }

    public static function fundingMethod(?string $method): string
    {
        return match ($method) {
            'bank' => 'Bank',
            'wallet' => 'Wallet',
            null, '' => '',
            default => self::humanise($method),
        };
    }

    /**
     * WHICH platform fee promotion priced a sale (owner, 2026-08-25) —
     * `transactions.fee_promo_kind`, frozen on the row at creation.
     *
     * Blank on every sale that paid its ordinary §4 tier fee, which is every
     * sale before this feature existed. It has to be readable somewhere: both
     * kinds share ONE mutable settings row, so a superadmin who re-rates or
     * ends a campaign leaves the answer recoverable from nothing but the
     * stamp — and "why was this sale 0.00%?" is the question the stamp exists
     * to answer.
     */
    public static function feePromotionKind(?string $kind): string
    {
        return match ($kind) {
            'introductory' => FeePromotionKind::Introductory->label(),
            'platform_wide' => FeePromotionKind::PlatformWide->label(),
            null, '' => '',
            default => self::humanise($kind),
        };
    }

    /**
     * A bank account shown to whoever reads the sheet: last four digits
     * only. The transfer sheet carries the real number to the bank; a
     * report is read by people who do not need it.
     */
    public static function maskedAccount(?string $account): string
    {
        $account = trim((string) $account);

        if ($account === '') {
            return '';
        }

        return mb_strlen($account) <= 4
            ? str_repeat('*', mb_strlen($account))
            : '****'.mb_substr($account, -4);
    }

    /**
     * What a ledger journal points at. The stored values are the §8
     * catalogue's own reference types; a sheet says them in words.
     */
    public static function referenceType(?string $type): string
    {
        return match ($type) {
            'transaction' => 'Transaction',
            'settlement' => 'Settlement',
            'payout_item' => 'Payout item',
            'adjustment' => 'Adjustment',
            'wallet_transaction' => 'Wallet movement',
            null, '' => '',
            default => self::humanise($type),
        };
    }

    private static function humanise(string $value): string
    {
        return Str::of($value)->replace('_', ' ')->trim()->toString();
    }
}
