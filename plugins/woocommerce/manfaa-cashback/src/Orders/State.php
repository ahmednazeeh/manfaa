<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Orders;

/**
 * The plugin's order state machine — the table in PLAN-woocommerce §4 that
 * every note, column and test hangs off.
 */
final class State
{
    public const SKIPPED_NO_CODE = 'skipped_no_code';
    public const SKIPPED_PRE_ACTIVATION = 'skipped_pre_activation';
    public const SKIPPED_CURRENCY = 'skipped_currency';
    public const SKIPPED_ZERO = 'skipped_zero';
    public const SKIPPED_INVALID_CUSTOMER = 'skipped_invalid_customer';
    public const QUEUED = 'queued';
    public const POSTED = 'posted';
    public const POSTED_ZERO = 'posted_zero';
    public const ADOPTED = 'adopted';
    public const NEEDS_ATTENTION = 'needs_attention';
    public const DISCONNECTED = 'disconnected';
    public const REVERSED = 'reversed';
    public const ADJUSTED = 'adjusted';
    public const REVERSE_REFUSED = 'reverse_refused';
    public const FINAL_REVERSED = 'final_reversed';

    /** States in which a sale exists on Manfaa and could still be reversed. */
    public const LIVE = [self::POSTED, self::POSTED_ZERO, self::ADOPTED];

    /** Anything past `queued` means the trigger has already been acted on. */
    public static function settled(string $state): bool
    {
        return $state !== '' && $state !== self::QUEUED;
    }

    /** The column label, per state, with the amount where one exists. */
    public static function label(string $state, string $transactionState = '', int $cashbackLaari = 0): string
    {
        $mvr = \Manfaa\Cashback\Money\Laari::toMvr($cashbackLaari);

        return match ($state) {
            self::QUEUED => __('Posting…', 'manfaa-cashback'),
            self::POSTED, self::ADOPTED => sprintf('MVR %s · %s', $mvr, self::transactionLabel($transactionState)),
            self::POSTED_ZERO => sprintf('MVR 0.00 · %s', $transactionState === 'recorded_ineligible' ? __('store ineligible', 'manfaa-cashback') : __('below minimum', 'manfaa-cashback')),
            self::NEEDS_ATTENTION => __('Needs attention', 'manfaa-cashback'),
            self::DISCONNECTED => __('Reconnect Manfaa', 'manfaa-cashback'),
            self::REVERSED => __('Reversed', 'manfaa-cashback'),
            self::ADJUSTED => __('Credit memo', 'manfaa-cashback'),
            self::REVERSE_REFUSED => __('Reverse refused', 'manfaa-cashback'),
            self::FINAL_REVERSED => __('Reversed (final)', 'manfaa-cashback'),
            self::SKIPPED_ZERO => __('Nothing eligible', 'manfaa-cashback'),
            self::SKIPPED_INVALID_CUSTOMER => __('Customer cannot earn', 'manfaa-cashback'),
            default => '—',
        };
    }

    /** The Manfaa transaction state, in the merchant's words. */
    public static function transactionLabel(string $state): string
    {
        return match ($state) {
            'awaiting_validation', 'on_hold' => __('pending', 'manfaa-cashback'),
            'confirmed', 'payable_unfunded' => __('confirmed', 'manfaa-cashback'),
            'paid' => __('paid', 'manfaa-cashback'),
            'reversed', 'written_off' => __('reversed', 'manfaa-cashback'),
            'tracked' => __('recorded', 'manfaa-cashback'),
            'recorded_ineligible' => __('store ineligible', 'manfaa-cashback'),
            'below_minimum' => __('below minimum', 'manfaa-cashback'),
            '' => __('pending', 'manfaa-cashback'),
            default => str_replace('_', ' ', $state),
        };
    }
}
