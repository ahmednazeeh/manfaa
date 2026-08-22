<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Storefront;

use Manfaa\Cashback\Api\RateCard;
use Manfaa\Cashback\Support\Options;

/**
 * The CLASSIC cart and checkout (shortcode pages): the same panel as the
 * block, rendered in PHP and driven by a small script that writes the code
 * through admin-ajax and asks WooCommerce to refresh its totals.
 */
final class Panel
{
    public static function hooks(): void
    {
        add_action('woocommerce_after_cart_table', [self::class, 'render']);
        add_action('woocommerce_checkout_before_order_review', [self::class, 'render']);
        add_action('woocommerce_cart_totals_after_order_total', [self::class, 'estimateRow']);
        add_action('woocommerce_review_order_after_order_total', [self::class, 'estimateRow']);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_manfaa_set_code', [self::class, 'ajaxSetCode']);
        add_action('wp_ajax_nopriv_manfaa_set_code', [self::class, 'ajaxSetCode']);
    }

    public static function assets(): void
    {
        if (! function_exists('is_cart') || ! (is_cart() || is_checkout())) {
            return;
        }

        wp_enqueue_style('manfaa-cashback-storefront', plugins_url('assets/storefront.css', MANFAA_CASHBACK_FILE), [], (string) filemtime(MANFAA_CASHBACK_DIR.'/assets/storefront.css'));
        wp_enqueue_script('manfaa-cashback-classic', plugins_url('assets/classic.js', MANFAA_CASHBACK_FILE), ['jquery'], (string) filemtime(MANFAA_CASHBACK_DIR.'/assets/classic.js'), true);
        wp_localize_script('manfaa-cashback-classic', 'manfaaCashback', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'ajaxNonce' => wp_create_nonce('manfaa_set_code'),
            'lookupUrl' => rest_url(Lookup::ROUTE.'/lookup'),
            'lookupNonce' => wp_create_nonce('manfaa_lookup'),
            'lookup' => Lookup::enabled(),
            'i18n' => [
                'checking' => __('Checking…', 'manfaa-cashback'),
                'cleared' => __('Manfaa code removed.', 'manfaa-cashback'),
            ],
        ]);
    }

    public static function render(): void
    {
        if (get_woocommerce_currency() !== 'MVR') {
            return;
        }

        $code = Session::code();
        $id = 'manfaa-code-'.wp_unique_id();
        ?>
        <div class="manfaa-panel" data-manfaa-panel dir="<?php echo Estimate::rtl() ? 'rtl' : 'ltr'; ?>">
            <label class="manfaa-panel__label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html(Estimate::label()); ?></label>
            <div class="manfaa-panel__row">
                <input
                    type="text"
                    id="<?php echo esc_attr($id); ?>"
                    class="manfaa-panel__input"
                    inputmode="numeric"
                    autocomplete="off"
                    maxlength="6"
                    pattern="[0-9]*"
                    placeholder="<?php esc_attr_e('6-digit code', 'manfaa-cashback'); ?>"
                    value="<?php echo esc_attr($code); ?>"
                    data-manfaa-input
                />
                <button type="button" class="button manfaa-panel__apply" data-manfaa-apply><?php esc_html_e('Apply', 'manfaa-cashback'); ?></button>
            </div>
            <p class="manfaa-panel__hint" data-manfaa-message aria-live="polite"><?php esc_html_e('Enter the code from your Manfaa app to earn cashback on this order.', 'manfaa-cashback'); ?></p>
        </div>
        <?php
    }

    public static function estimateRow(): void
    {
        $estimate = Estimate::forCart(function_exists('WC') ? WC()->cart : null);

        if (! $estimate['available']) {
            return;
        }

        $text = $estimate['shortfall_laari'] > 0
            ? sprintf(
                /* translators: %s: amount */
                __('Add MVR %s more to earn cashback', 'manfaa-cashback'),
                $estimate['shortfall_mvr'],
            )
            : 'MVR '.$estimate['estimate_mvr'];
        ?>
        <tr class="manfaa-estimate">
            <th><?php echo esc_html($estimate['wording']); ?></th>
            <td data-title="<?php echo esc_attr($estimate['wording']); ?>"><?php echo esc_html($text); ?></td>
        </tr>
        <?php
    }

    public static function ajaxSetCode(): void
    {
        check_ajax_referer('manfaa_set_code', 'nonce');

        $code = Session::set((string) ($_POST['code'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        wp_send_json_success(['code' => $code]);
    }
}
