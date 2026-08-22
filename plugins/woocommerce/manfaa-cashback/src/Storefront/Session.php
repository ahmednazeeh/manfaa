<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Storefront;

use Manfaa\Cashback\Orders\Meta;
use WC_Order;

/**
 * The Manfaa code the buyer typed, kept in the WooCommerce session so the
 * cart, the checkout and the order creation all read one value. A signed-in
 * customer's code is also remembered on their account, so they type it once.
 */
final class Session
{
    private const KEY = 'manfaa_code';
    private const USER_META = '_manfaa_code';

    public static function code(): string
    {
        $session = function_exists('WC') ? WC()->session : null;
        $code = $session ? (string) $session->get(self::KEY, '') : '';

        if ($code === '' && is_user_logged_in()) {
            $code = (string) get_user_meta(get_current_user_id(), self::USER_META, true);
        }

        return self::clean($code);
    }

    /** Returns the stored (cleaned) code; an empty string clears it. */
    public static function set(string $code): string
    {
        $code = self::clean($code);
        $session = function_exists('WC') ? WC()->session : null;

        if ($session) {
            if ($code === '') {
                $session->set(self::KEY, null);
            } else {
                $session->set(self::KEY, $code);
            }
        }

        if (is_user_logged_in()) {
            if ($code === '') {
                delete_user_meta(get_current_user_id(), self::USER_META);
            } else {
                update_user_meta(get_current_user_id(), self::USER_META, $code);
            }
        }

        return $code;
    }

    /** Written once, at order creation, from the session — both checkouts. */
    public static function stamp(WC_Order $order): void
    {
        $code = self::code();

        if ($code !== '') {
            Meta::set($order, Meta::CODE, $code);
        }
    }

    /** Digits only, and only a complete 6-digit code survives. */
    public static function clean(string $code): string
    {
        $digits = preg_replace('/\D+/', '', $code) ?? '';

        return preg_match('/^\d{6}$/', $digits) ? $digits : '';
    }
}
