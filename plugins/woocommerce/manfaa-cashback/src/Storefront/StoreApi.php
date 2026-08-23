<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Storefront;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\StoreApi as WooStoreApi;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;

/**
 * The Cart and Checkout BLOCKS side (the default on a current install).
 *
 *  - Endpoint data on the cart schema, namespace `manfaa`: the stored code,
 *    the estimate, and the settings the panel needs. Re-read after every
 *    cart change, so the estimate follows quantities and coupons.
 *  - An update callback, namespace `manfaa`: the panel's input calls
 *    `extensionCartUpdate({ namespace: 'manfaa', data: { code } })` and the
 *    session is written here.
 *  - An IntegrationInterface that loads the inner-block script on the cart
 *    and checkout pages and in the editor.
 */
final class StoreApi implements IntegrationInterface
{
    public const HANDLE = 'manfaa-cashback-blocks';

    public static function registerExtensions(): void
    {
        if (! class_exists(WooStoreApi::class)) {
            return;
        }

        /** @var ExtendSchema $extend */
        $extend = WooStoreApi::container()->get(ExtendSchema::class);

        $extend->register_endpoint_data([
            'endpoint' => CartSchema::IDENTIFIER,
            'namespace' => 'manfaa',
            'data_callback' => static fn (): array => self::cartData(),
            'schema_callback' => static fn (): array => [
                'code' => ['type' => 'string', 'readonly' => true],
                'label' => ['type' => 'string', 'readonly' => true],
                'lookup' => ['type' => 'boolean', 'readonly' => true],
                'estimate' => ['type' => 'object', 'readonly' => true],
            ],
            'schema_type' => ARRAY_A,
        ]);

        $extend->register_update_callback([
            'namespace' => 'manfaa',
            'callback' => static function (array $data): void {
                Session::set((string) ($data['code'] ?? ''));
            },
        ]);
    }

    /** @return array<string, mixed> */
    public static function cartData(): array
    {
        return [
            'code' => Session::code(),
            'label' => Estimate::label(),
            'lookup' => Lookup::enabled(),
            'estimate' => Estimate::forCart(function_exists('WC') ? WC()->cart : null),
        ];
    }

    public function get_name(): string
    {
        return 'manfaa';
    }

    public function initialize(): void
    {
        $file = MANFAA_CASHBACK_DIR.'/assets/blocks.js';

        wp_register_script(
            self::HANDLE,
            plugins_url('assets/blocks.js', MANFAA_CASHBACK_FILE),
            ['wc-blocks-checkout', 'wc-blocks-components', 'wp-element', 'wp-data', 'wp-i18n', 'wp-html-entities', 'wp-api-fetch'],
            (string) filemtime($file),
            true,
        );

        wp_register_style(
            'manfaa-cashback-storefront',
            plugins_url('assets/storefront.css', MANFAA_CASHBACK_FILE),
            [],
            (string) filemtime(MANFAA_CASHBACK_DIR.'/assets/storefront.css'),
        );
        wp_enqueue_style('manfaa-cashback-storefront');

        wp_set_script_translations(self::HANDLE, 'manfaa-cashback', MANFAA_CASHBACK_DIR.'/languages');
    }

    public function get_script_handles(): array
    {
        return [self::HANDLE];
    }

    public function get_editor_script_handles(): array
    {
        return [self::HANDLE];
    }

    public function get_script_data(): array
    {
        return [
            'lookupUrl' => rest_url(Lookup::ROUTE.'/lookup'),
            'lookupNonce' => wp_create_nonce('manfaa_lookup'),
            'isRtl' => Estimate::rtl(),
        ];
    }
}
