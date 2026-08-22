<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Admin;

use Manfaa\Cashback\Money\Laari;
use Manfaa\Cashback\Orders\Meta;
use Manfaa\Cashback\Orders\Poster;
use Manfaa\Cashback\Orders\State;
use WC_Order;
use WP_Post;

/**
 * The "Manfaa" column on the orders list (HPOS and legacy), the metabox on
 * the order screen, and the two actions a person can take: Retry (after
 * needs-attention / disconnected) and Refresh status.
 */
final class OrderColumn
{
    private const COLUMN = 'manfaa_cashback';

    public static function hooks(): void
    {
        // HPOS list.
        add_filter('manage_woocommerce_page_wc-orders_columns', [self::class, 'columns']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [self::class, 'cell'], 10, 2);
        // Legacy list.
        add_filter('manage_edit-shop_order_columns', [self::class, 'columns']);
        add_action('manage_shop_order_posts_custom_column', [self::class, 'legacyCell'], 10, 2);

        add_action('add_meta_boxes', [self::class, 'metabox']);
        add_action('admin_post_manfaa_order_retry', [self::class, 'actionRetry']);
        add_action('admin_post_manfaa_order_refresh', [self::class, 'actionRefresh']);
        add_action('admin_notices', [self::class, 'notices']);
    }

    /** @param array<string, string> $columns */
    public static function columns(array $columns): array
    {
        $out = [];

        foreach ($columns as $key => $label) {
            $out[$key] = $label;

            if ($key === 'order_total') {
                $out[self::COLUMN] = __('Manfaa', 'manfaa-cashback');
            }
        }

        if (! isset($out[self::COLUMN])) {
            $out[self::COLUMN] = __('Manfaa', 'manfaa-cashback');
        }

        return $out;
    }

    public static function cell(string $column, mixed $order): void
    {
        if ($column !== self::COLUMN) {
            return;
        }

        $order = $order instanceof WC_Order ? $order : wc_get_order((int) $order);

        if ($order instanceof WC_Order) {
            echo esc_html(self::summary($order));
        }
    }

    public static function legacyCell(string $column, int $postId): void
    {
        self::cell($column, $postId);
    }

    public static function summary(WC_Order $order): string
    {
        $state = Meta::get($order, Meta::STATE);

        return State::label($state, Meta::get($order, Meta::TRANSACTION_STATE), (int) Meta::get($order, Meta::CASHBACK_LAARI));
    }

    public static function metabox(): void
    {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';

        add_meta_box('manfaa-cashback', __('Manfaa cashback', 'manfaa-cashback'), [self::class, 'renderMetabox'], $screen, 'side', 'default');
    }

    public static function renderMetabox(WC_Order|WP_Post $object): void
    {
        $order = $object instanceof WC_Order ? $object : wc_get_order($object->ID);

        if (! $order instanceof WC_Order) {
            return;
        }

        $state = Meta::get($order, Meta::STATE);
        $code = Meta::get($order, Meta::CODE);
        $id = (int) Meta::get($order, Meta::TRANSACTION_ID);
        $error = Meta::get($order, Meta::ERROR);
        $action = static fn (string $name) => wp_nonce_url(admin_url('admin-post.php?action='.$name.'&order='.$order->get_id()), $name.'_'.$order->get_id());

        echo '<p><strong>'.esc_html(self::summary($order)).'</strong></p>';
        echo '<p>'.esc_html__('Code:', 'manfaa-cashback').' '.($code !== '' ? '<code>'.esc_html($code).'</code>' : esc_html__('none', 'manfaa-cashback')).'</p>';

        if ($id > 0) {
            echo '<p>'.esc_html__('Manfaa transaction', 'manfaa-cashback').' #'.esc_html((string) $id).' · '.esc_html(State::transactionLabel(Meta::get($order, Meta::TRANSACTION_STATE))).'</p>';
        }

        if ($error !== '') {
            echo '<p class="description" style="color:#b32d2e">'.esc_html($error).'</p>';
        }

        if (in_array($state, [State::NEEDS_ATTENTION, State::DISCONNECTED], true) && Meta::get($order, Meta::REQUEST) !== '') {
            echo '<a class="button" href="'.esc_url($action('manfaa_order_retry')).'">'.esc_html__('Retry', 'manfaa-cashback').'</a> ';
        }

        if ($id > 0) {
            echo '<a class="button" href="'.esc_url($action('manfaa_order_refresh')).'">'.esc_html__('Refresh status', 'manfaa-cashback').'</a>';
        }
    }

    public static function actionRetry(): void
    {
        $order = self::guardedOrder('manfaa_order_retry');
        $ok = Poster::retry($order);
        self::back($order, $ok ? __('Queued again.', 'manfaa-cashback') : __('Nothing to retry on this order.', 'manfaa-cashback'));
    }

    public static function actionRefresh(): void
    {
        $order = self::guardedOrder('manfaa_order_refresh');
        Poster::refresh($order);
        self::back($order, __('Status refreshed from Manfaa.', 'manfaa-cashback'));
    }

    private static function guardedOrder(string $action): WC_Order
    {
        $id = (int) ($_GET['order'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('You do not have permission to do that.', 'manfaa-cashback'), 403);
        }

        check_admin_referer($action.'_'.$id);
        $order = wc_get_order($id);

        if (! $order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'manfaa-cashback'), 404);
        }

        return $order;
    }

    private static function back(WC_Order $order, string $message): never
    {
        set_transient('manfaa_cashback_order_notice_'.get_current_user_id(), $message, MINUTE_IN_SECONDS);
        wp_safe_redirect($order->get_edit_order_url());
        exit;
    }

    public static function notices(): void
    {
        $message = get_transient('manfaa_cashback_order_notice_'.get_current_user_id());

        if (is_string($message) && $message !== '') {
            delete_transient('manfaa_cashback_order_notice_'.get_current_user_id());
            echo '<div class="notice notice-info is-dismissible"><p>'.esc_html('Manfaa: '.$message).'</p></div>';
        }

        $disconnected = (string) get_option('manfaa_cashback_disconnected', '');

        if ($disconnected !== '' && current_user_can(Settings::CAP) && ! (isset($_GET['page']) && $_GET['page'] === Settings::PAGE)) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-error"><p>'.esc_html__('Manfaa Cashback: the connection to Manfaa no longer works, so orders are not being posted.', 'manfaa-cashback').' <a href="'.esc_url(Settings::url()).'">'.esc_html__('Reconnect', 'manfaa-cashback').'</a></p></div>';
        }
    }
}
