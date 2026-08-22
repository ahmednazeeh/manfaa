<?php

declare(strict_types=1);

namespace Manfaa\Cashback;

use Manfaa\Cashback\Admin\OrderColumn;
use Manfaa\Cashback\Admin\Settings;
use Manfaa\Cashback\Orders\Amender;
use Manfaa\Cashback\Orders\Poster;
use Manfaa\Cashback\Orders\Query;
use Manfaa\Cashback\Orders\Reverser;
use Manfaa\Cashback\Orders\Sweep;
use Manfaa\Cashback\Storefront\Badge;
use Manfaa\Cashback\Storefront\Lookup;
use Manfaa\Cashback\Storefront\Panel;
use Manfaa\Cashback\Storefront\Session;
use Manfaa\Cashback\Storefront\StoreApi;
use Manfaa\Cashback\Support\Options;
use Manfaa\Cashback\Support\Updater;
use Manfaa\Cashback\Webhooks\Receiver;
use WC_Order;

/**
 * Wiring only. Every hook the plugin listens to is registered here, so the
 * whole surface can be read in one screen.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public function boot(): void
    {
        load_plugin_textdomain('manfaa-cashback', false, dirname(plugin_basename(MANFAA_CASHBACK_FILE)).'/languages');

        // ── Storefront ────────────────────────────────────────────────
        if (did_action('woocommerce_blocks_loaded')) {
            StoreApi::registerExtensions();
        } else {
            add_action('woocommerce_blocks_loaded', [StoreApi::class, 'registerExtensions']);
        }
        add_action('woocommerce_blocks_cart_block_registration', static fn ($registry) => $registry->register(new StoreApi));
        add_action('woocommerce_blocks_checkout_block_registration', static fn ($registry) => $registry->register(new StoreApi));
        add_action('init', [$this, 'registerBlock']);
        Panel::hooks();
        Badge::hooks();
        add_action('rest_api_init', [Lookup::class, 'register']);
        add_action('rest_api_init', [Receiver::class, 'registerRoute']);

        // The code onto the order, once, on both checkouts.
        add_action('woocommerce_store_api_checkout_update_order_from_request', static function (WC_Order $order): void {
            Session::stamp($order);
        }, 10, 1);
        add_action('woocommerce_checkout_create_order', static function (WC_Order $order): void {
            Session::stamp($order);
        }, 10, 1);
        add_action('woocommerce_after_checkout_validation', static function (array $data, \WP_Error $errors): void {
            $raw = isset($_POST['manfaa_code']) ? (string) wp_unslash($_POST['manfaa_code']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

            if ($raw !== '' && Session::clean($raw) === '') {
                $errors->add('manfaa_code', __('The Manfaa code must be 6 digits, or left empty.', 'manfaa-cashback'));
            } elseif ($raw !== '') {
                Session::set($raw);
            }
        }, 10, 2);

        // ── Posting and reversal ──────────────────────────────────────
        add_action('woocommerce_order_status_'.Options::string('post_on_status'), static function (int $orderId, ?WC_Order $order = null): void {
            $order ??= wc_get_order($orderId);

            if ($order instanceof WC_Order) {
                Poster::trigger($order);
            }
        }, 10, 2);

        foreach (['cancelled', 'refunded'] as $status) {
            add_action('woocommerce_order_status_'.$status, static function (int $orderId, ?WC_Order $order = null) use ($status): void {
                $order ??= wc_get_order($orderId);

                if ($order instanceof WC_Order) {
                    Reverser::trigger($order, $status);
                }
            }, 10, 2);
        }

        add_action('woocommerce_trash_order', static function (int $orderId): void {
            $order = wc_get_order($orderId);

            if ($order instanceof WC_Order) {
                Reverser::trigger($order, 'trashed');
            }
        });
        add_action('wp_trash_post', static function (int $postId): void {
            if (get_post_type($postId) === 'shop_order') {
                $order = wc_get_order($postId);

                if ($order instanceof WC_Order) {
                    Reverser::trigger($order, 'trashed');
                }
            }
        });
        add_action('woocommerce_order_partially_refunded', static function (int $orderId, int $refundId = 0): void {
            $order = wc_get_order($orderId);

            if ($order instanceof WC_Order) {
                Reverser::partialRefund($order, $refundId);
            }
        }, 10, 2);

        add_action(Amender::HOOK, [Amender::class, 'run'], 10, 2);

        Query::hooks();
        add_action(Poster::HOOK, [Poster::class, 'run'], 10, 1);
        add_action(Reverser::HOOK, [Reverser::class, 'run'], 10, 2);
        add_action(Sweep::HOOK, [Sweep::class, 'run']);
        add_action('init', [$this, 'scheduleSweep']);

        // ── Admin ─────────────────────────────────────────────────────
        if (is_admin()) {
            Settings::hooks();
            OrderColumn::hooks();
        }

        // Updates from manfaa.app's manifest — admin and cron both ask.
        Updater::hooks();
    }

    /** Server-side block registration so the editor knows the inner block. */
    public function registerBlock(): void
    {
        if (function_exists('register_block_type_from_metadata')) {
            register_block_type_from_metadata(MANFAA_CASHBACK_DIR.'/assets/block');
        }
    }

    public function scheduleSweep(): void
    {
        if (! function_exists('as_has_scheduled_action') || ! function_exists('as_schedule_recurring_action')) {
            return;
        }

        if (! as_has_scheduled_action(Sweep::HOOK, [], Poster::GROUP)) {
            as_schedule_recurring_action(time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, Sweep::HOOK, [], Poster::GROUP);
        }
    }
}
