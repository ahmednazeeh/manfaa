<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Orders;

use WC_Order;

/**
 * Every key the plugin writes on an order, through the CRUD API only —
 * HPOS-safe on both storage backends.
 */
final class Meta
{
    public const CODE = '_manfaa_code';
    public const STATE = '_manfaa_state';
    public const REVERSE_STATE = '_manfaa_reverse_state';
    public const TRANSACTION_ID = '_manfaa_transaction_id';
    public const TRANSACTION_STATE = '_manfaa_transaction_state';
    public const CASHBACK_LAARI = '_manfaa_cashback_laari';
    public const REQUEST = '_manfaa_request';
    public const REVERSE_REQUEST = '_manfaa_reverse_request';
    /** Suffixed with the refund id: one frozen body per partial refund. */
    public const AMEND_REQUEST = '_manfaa_amend_request';
    public const INVOICE_NO = '_manfaa_invoice_no';
    public const ERROR = '_manfaa_error';
    public const ATTEMPTS = '_manfaa_attempts';
    public const LAST_CHECKED = '_manfaa_last_checked';

    public static function get(WC_Order $order, string $key): string
    {
        $value = $order->get_meta($key, true);

        return is_scalar($value) ? (string) $value : '';
    }

    public static function set(WC_Order $order, string $key, string|int|null $value): void
    {
        if ($value === null || $value === '') {
            $order->delete_meta_data($key);
        } else {
            $order->update_meta_data($key, (string) $value);
        }
    }

    /** @param array<string, string|int|null> $values */
    public static function setMany(WC_Order $order, array $values, bool $save = true): void
    {
        foreach ($values as $key => $value) {
            self::set($order, $key, $value);
        }

        if ($save) {
            $order->save();
        }
    }
}
