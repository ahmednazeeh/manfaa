<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Admin;

use Manfaa\Cashback\Api\ApiException;
use Manfaa\Cashback\Api\Client;
use Manfaa\Cashback\Api\Connect;
use Manfaa\Cashback\Api\ConnectException;
use Manfaa\Cashback\Api\RateCard;
use Manfaa\Cashback\Orders\Meta;
use Manfaa\Cashback\Orders\State;
use Manfaa\Cashback\Pricing\CategoryMap;
use Manfaa\Cashback\Support\Crypto;
use Manfaa\Cashback\Support\Options;
use Manfaa\Cashback\Webhooks\Receiver;

/**
 * The settings screen — a TOP-LEVEL admin menu "Manfaa Cashback" (owner
 * decision 2026-08-22), capability `manage_woocommerce` so a Shop Manager
 * can run it. One option row through the Settings API; the actions that
 * are not form saves (connect, disconnect, test, sync, token paste) are
 * admin-post handlers with their own nonces.
 */
final class Settings
{
    public const PAGE = 'manfaa-cashback';
    public const CAP = 'manage_woocommerce';

    public static function hooks(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
        add_filter('option_page_capability_'.Options::OPTION, static fn (): string => self::CAP);
        add_action('admin_post_manfaa_connect', [self::class, 'actionConnect']);
        add_action('admin_post_manfaa_disconnect', [self::class, 'actionDisconnect']);
        add_action('admin_post_manfaa_token', [self::class, 'actionToken']);
        add_action('admin_post_manfaa_sync', [self::class, 'actionSync']);
        add_action('admin_post_manfaa_test', [self::class, 'actionTest']);
        add_action('admin_post_manfaa_webhook', [self::class, 'actionWebhook']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_filter('plugin_action_links_'.plugin_basename(MANFAA_CASHBACK_FILE), static function (array $links): array {
            array_unshift($links, '<a href="'.esc_url(self::url()).'">'.esc_html__('Settings', 'manfaa-cashback').'</a>');

            return $links;
        });
    }

    public static function url(array $args = []): string
    {
        return add_query_arg($args + ['page' => self::PAGE], admin_url('admin.php'));
    }

    public static function menu(): void
    {
        add_menu_page(
            __('Manfaa Cashback', 'manfaa-cashback'),
            __('Manfaa Cashback', 'manfaa-cashback'),
            self::CAP,
            self::PAGE,
            [self::class, 'render'],
            'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#9ca2a7" d="M3 15V5h2.6l2.4 5.2L10.4 5H13v10h-2.2V8.9L8.5 13.7h-1L5.2 8.9V15H3zm11.2-10H17v10h-2.8V5z"/></svg>'),
            56,
        );
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_'.self::PAGE) {
            return;
        }

        wp_enqueue_style('manfaa-cashback-admin', plugins_url('assets/admin.css', MANFAA_CASHBACK_FILE), [], (string) filemtime(MANFAA_CASHBACK_DIR.'/assets/admin.css'));
        wp_enqueue_script('manfaa-cashback-admin', plugins_url('assets/admin.js', MANFAA_CASHBACK_FILE), ['jquery'], (string) filemtime(MANFAA_CASHBACK_DIR.'/assets/admin.js'), true);
    }

    public static function register(): void
    {
        register_setting(Options::OPTION, Options::OPTION, ['sanitize_callback' => [self::class, 'sanitize']]);

        // The connect callback lands here with ?manfaa-callback=1.
        if (isset($_GET['page'], $_GET['manfaa-callback']) && $_GET['page'] === self::PAGE && current_user_can(self::CAP)) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            self::finishConnect();
        }
    }

    /** @param mixed $input */
    public static function sanitize($input): array
    {
        $current = Options::all();
        $input = is_array($input) ? $input : [];

        $statuses = array_keys(self::postableStatuses());
        $status = (string) ($input['post_on_status'] ?? $current['post_on_status']);

        $map = [];

        foreach ((array) ($input['category_map'] ?? []) as $slug => $terms) {
            $slug = sanitize_key((string) $slug);
            $ids = array_values(array_filter(array_map('intval', (array) $terms)));

            if ($slug !== '' && $ids !== []) {
                $map[$slug] = $ids;
            }
        }

        $clean = [
            'pricing_mode' => in_array($input['pricing_mode'] ?? '', [Options::PRICING_GENERAL, Options::PRICING_PER_CATEGORY], true) ? $input['pricing_mode'] : Options::PRICING_GENERAL,
            'awarding_policy' => in_array($input['awarding_policy'] ?? '', [Options::POLICY_ITEMS_EX_TAX, Options::POLICY_ITEMS_INC_TAX], true) ? $input['awarding_policy'] : Options::POLICY_ITEMS_EX_TAX,
            'category_map' => $map,
            'panel_label' => sanitize_text_field((string) ($input['panel_label'] ?? '')),
            'confirm_code_live' => ! empty($input['confirm_code_live']),
            'post_invalid_customer' => ! empty($input['post_invalid_customer']),
            'phone_fallback' => ! empty($input['phone_fallback']),
            'show_estimate' => ! empty($input['show_estimate']),
            'estimate_wording' => sanitize_text_field((string) ($input['estimate_wording'] ?? '')),
            'show_product_badge' => ! empty($input['show_product_badge']),
            'post_on_status' => in_array($status, $statuses, true) ? $status : 'completed',
            'reverse_on_cancel' => ! empty($input['reverse_on_cancel']),
            'partial_refund_policy' => in_array($input['partial_refund_policy'] ?? '', [Options::PARTIAL_NOTHING, Options::PARTIAL_REVERSE_ALL, Options::PARTIAL_AMEND], true) ? $input['partial_refund_policy'] : Options::PARTIAL_NOTHING,
            'only_after_activation' => ! empty($input['only_after_activation']),
            'invoice_prefix' => strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($input['invoice_prefix'] ?? '')) ?? ''),
        ];

        // Connection fields are not on the main form; keep whatever is stored.
        foreach (['api_base_url', 'panel_base_url', 'client_id'] as $keep) {
            $clean[$keep] = $current[$keep];
        }

        Options::flush();

        return $clean;
    }

    /** Registered WooCommerce statuses a merchant may post on. `on-hold` is not offered: payment unconfirmed. */
    public static function postableStatuses(): array
    {
        $all = wc_get_order_statuses();
        $offered = [];

        foreach ($all as $key => $label) {
            $slug = str_replace('wc-', '', $key);

            if (in_array($slug, ['pending', 'on-hold', 'cancelled', 'refunded', 'failed', 'checkout-draft'], true)) {
                continue;
            }

            $offered[$slug] = match ($slug) {
                'processing' => __('Processing (paid — WooCommerce\'s "order confirmed" moment)', 'manfaa-cashback'),
                'completed' => __('Completed (fulfilled) — recommended', 'manfaa-cashback'),
                default => $label,
            };
        }

        return $offered;
    }

    /* ------------------------------------------------------------------ *
     * Actions
     * ------------------------------------------------------------------ */

    public static function actionConnect(): void
    {
        self::guard('manfaa_connect');
        wp_safe_redirect(Connect::beginUrl(get_current_user_id()));
        exit;
    }

    private static function finishConnect(): void
    {
        $code = (string) ($_GET['code'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $state = (string) ($_GET['state'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $error = (string) ($_GET['error'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($error !== '') {
            self::redirectWith('denied', __('The connection was not approved on Manfaa.', 'manfaa-cashback'));
        }

        try {
            $profile = Connect::complete($code, $state, get_current_user_id());
        } catch (ConnectException $e) {
            self::redirectWith('error', $e->getMessage());
        } catch (ApiException $e) {
            self::redirectWith('error', $e->getMessage());
        }

        self::redirectWith('connected', sprintf(
            /* translators: %s: store name */
            __('Connected to Manfaa as %s.', 'manfaa-cashback'),
            (string) ($profile['merchant_name'] ?? ''),
        ));
    }

    public static function actionDisconnect(): void
    {
        self::guard('manfaa_disconnect');
        Connect::disconnect();
        delete_option('manfaa_cashback_disconnected');
        self::redirectWith('disconnected', __('Disconnected. Orders already posted are unaffected; new orders will not be posted until you reconnect.', 'manfaa-cashback'));
    }

    public static function actionToken(): void
    {
        self::guard('manfaa_token');
        $token = trim((string) ($_POST['token'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ($token === '') {
            self::redirectWith('error', __('Paste the token first.', 'manfaa-cashback'));
        }

        try {
            $profile = Connect::adoptToken($token);
        } catch (ApiException $e) {
            self::redirectWith('error', sprintf(
                /* translators: %s: reason */
                __('Manfaa did not accept that token: %s', 'manfaa-cashback'),
                $e->getMessage(),
            ));
        }

        delete_option('manfaa_cashback_disconnected');
        self::redirectWith('connected', sprintf(
            /* translators: %s: store name */
            __('Connected to Manfaa as %s.', 'manfaa-cashback'),
            (string) ($profile['merchant_name'] ?? ''),
        ));
    }

    public static function actionSync(): void
    {
        self::guard('manfaa_sync');

        try {
            $card = RateCard::sync(Client::fromSettings());
        } catch (ApiException $e) {
            self::redirectWith('error', sprintf(
                /* translators: %s: reason */
                __('Sync failed: %s', 'manfaa-cashback'),
                $e->getMessage(),
            ));
        }

        self::redirectWith('synced', sprintf(
            /* translators: 1: rate, 2: count */
            __('Synced: standing rate %1$s%%, %2$d categories.', 'manfaa-cashback'),
            self::percent($card->rateBp),
            count($card->categories),
        ));
    }

    public static function actionTest(): void
    {
        self::guard('manfaa_test');

        try {
            $profile = Connect::refreshProfile(Client::fromSettings());
        } catch (ApiException $e) {
            if ($e->status === 401) {
                update_option('manfaa_cashback_disconnected', $e->getMessage(), false);
            }

            self::redirectWith('error', sprintf(
                /* translators: %s: reason */
                __('Test failed: %s', 'manfaa-cashback'),
                $e->getMessage(),
            ));
        }

        delete_option('manfaa_cashback_disconnected');
        self::redirectWith('tested', sprintf(
            /* translators: 1: store name, 2: abilities */
            __('Connection works. Store: %1$s. Permissions: %2$s.', 'manfaa-cashback'),
            (string) ($profile['merchant_name'] ?? ''),
            implode(', ', (array) ($profile['abilities'] ?? [])),
        ));
    }

    public static function actionWebhook(): void
    {
        self::guard('manfaa_webhook');

        try {
            Receiver::register(Client::fromSettings());
        } catch (ApiException $e) {
            self::redirectWith('error', sprintf(
                /* translators: %s: reason */
                __('Webhook registration failed: %s', 'manfaa-cashback'),
                $e->getMessage(),
            ));
        }

        self::redirectWith('webhook', __('Webhook registered. Manfaa will tell this site when your rate changes or a sale is reversed.', 'manfaa-cashback'));
    }

    private static function guard(string $action): void
    {
        if (! current_user_can(self::CAP)) {
            wp_die(esc_html__('You do not have permission to do that.', 'manfaa-cashback'), 403);
        }

        check_admin_referer($action);
    }

    private static function redirectWith(string $kind, string $message): never
    {
        set_transient('manfaa_cashback_notice_'.get_current_user_id(), ['kind' => $kind, 'message' => $message], MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url());
        exit;
    }

    /* ------------------------------------------------------------------ *
     * Screen
     * ------------------------------------------------------------------ */

    public static function render(): void
    {
        if (! current_user_can(self::CAP)) {
            return;
        }

        $client = Client::fromSettings();
        $connected = $client->connected();
        $profile = Connect::profile();
        $card = RateCard::cached();
        $map = CategoryMap::fromSettings($card);
        $o = Options::all();
        $notice = get_transient('manfaa_cashback_notice_'.get_current_user_id());
        delete_transient('manfaa_cashback_notice_'.get_current_user_id());
        $disconnected = (string) get_option('manfaa_cashback_disconnected', '');
        $currencyOk = get_woocommerce_currency() === 'MVR';
        $abilities = (array) ($profile['abilities'] ?? []);
        $keyMismatch = Client::tokenKeyMismatch();
        $attention = self::countState(State::NEEDS_ATTENTION) + self::countState(State::DISCONNECTED);
        $isHttps = str_starts_with(home_url(), 'https://');

        include MANFAA_CASHBACK_DIR.'/src/Admin/views/settings.php';
    }

    public static function percent(int $bp): string
    {
        return intdiv($bp, 100).'.'.str_pad((string) ($bp % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function countState(string $state): int
    {
        return count(\Manfaa\Cashback\Orders\Query::byMeta([['key' => Meta::STATE, 'value' => $state]], ['limit' => 200, 'return' => 'ids']));
    }
}
