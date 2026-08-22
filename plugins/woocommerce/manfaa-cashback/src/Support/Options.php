<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Support;

/**
 * One option row, `manfaa_cashback`, read through typed accessors with the
 * defaults in one place. Every screen and every job reads the same keys.
 */
final class Options
{
    public const OPTION = 'manfaa_cashback';

    /** Cashback awarding policy (owner decision 2026-08-22). */
    public const POLICY_ITEMS_EX_TAX = 'items_ex_tax';
    public const POLICY_ITEMS_INC_TAX = 'items_inc_tax';

    public const PRICING_GENERAL = 'general';
    public const PRICING_PER_CATEGORY = 'per_category';

    public const PARTIAL_NOTHING = 'nothing';
    public const PARTIAL_REVERSE_ALL = 'reverse_all';
    /** Reduce the sale to what the buyer kept — an amend while the sale is still pending. */
    public const PARTIAL_AMEND = 'amend';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'api_base_url' => 'https://api.manfaa.app/api',
            'panel_base_url' => 'https://merchant.manfaa.app',
            'client_id' => 'mfa_gewk290rpqxqol48uais1cqs',

            'pricing_mode' => self::PRICING_GENERAL,
            'awarding_policy' => self::POLICY_ITEMS_EX_TAX,
            // Manfaa category slug => list of WooCommerce product_cat term ids.
            'category_map' => [],

            'panel_label' => '',
            'confirm_code_live' => true,
            'post_invalid_customer' => false,
            'phone_fallback' => false,

            'show_estimate' => true,
            'estimate_wording' => '',
            'show_product_badge' => false,

            'post_on_status' => 'completed',
            'reverse_on_cancel' => true,
            'partial_refund_policy' => self::PARTIAL_NOTHING,
            'only_after_activation' => true,
            'invoice_prefix' => '',
        ];
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = get_option(self::OPTION, []);
            self::$cache = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        }

        return self::$cache;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function string(string $key): string
    {
        $value = self::get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    /** @param array<string, mixed> $values */
    public static function update(array $values): void
    {
        $merged = array_merge(self::all(), $values);
        update_option(self::OPTION, $merged, false);
        self::$cache = $merged;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function apiBase(): string
    {
        return rtrim(self::string('api_base_url'), '/');
    }

    /**
     * The invoice prefix: the merchant's own, or a 6-character token derived
     * from the site URL so two stores of one merchant never collide on small
     * order ids. Always ends in `-`.
     */
    public static function invoicePrefix(): string
    {
        $prefix = trim(self::string('invoice_prefix'));

        if ($prefix === '') {
            $prefix = strtoupper(substr(hash('sha256', (string) home_url()), 0, 6));
        }

        return rtrim($prefix, '-').'-';
    }

    /** A stable per-site token for idempotency keys; never changes once set. */
    public static function siteHash(): string
    {
        $hash = get_option('manfaa_cashback_site_hash');

        if (! is_string($hash) || $hash === '') {
            $hash = substr(hash('sha256', home_url().'|'.wp_generate_password(32, false)), 0, 16);
            add_option('manfaa_cashback_site_hash', $hash, '', false);
        }

        return $hash;
    }
}
