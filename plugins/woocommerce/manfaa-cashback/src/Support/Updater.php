<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Support;

use stdClass;

/**
 * Updates from Manfaa's own manifest, not wordpress.org.
 *
 * Twice a day the plugin reads
 * `https://manfaa.app/app/woocommerce/manifest.json`; when the version
 * there is newer than this one, WordPress shows the usual "update
 * available" row and installs the zip the manifest names through the
 * standard upgrader. Nothing else is special: same one-click, same
 * rollback on failure, same "View details" panel.
 *
 * Version discipline is the APKs': code changed → version changed
 * (scripts/build-woocommerce-plugin.sh refuses a zip whose header, constant
 * and readme disagree), so a merchant is never offered an "update" that is
 * the same code.
 */
final class Updater
{
    public const MANIFEST_URL = 'https://manfaa.app/app/woocommerce/manifest.json';

    private const TRANSIENT = 'manfaa_cashback_manifest';
    private const TTL = 12 * HOUR_IN_SECONDS;

    public static function hooks(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject']);
        add_filter('site_transient_update_plugins', [self::class, 'inject']);
        add_filter('plugins_api', [self::class, 'details'], 10, 3);
        add_action('upgrader_process_complete', [self::class, 'forget'], 10, 2);
        add_filter('plugin_row_meta', [self::class, 'rowMeta'], 10, 2);
    }

    public static function basename(): string
    {
        return plugin_basename(MANFAA_CASHBACK_FILE);
    }

    /** @return array<string, mixed>|null */
    public static function manifest(bool $fresh = false): ?array
    {
        if (! $fresh) {
            $cached = get_site_transient(self::TRANSIENT);

            if (is_array($cached)) {
                return $cached['manifest'] ?? null;
            }
        }

        $response = wp_remote_get(self::MANIFEST_URL, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
        $manifest = null;

        if (! is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

            if (is_array($decoded) && isset($decoded['version'], $decoded['download_url'])
                && preg_match('/^\d+\.\d+\.\d+$/', (string) $decoded['version'])
                && str_starts_with((string) $decoded['download_url'], 'https://manfaa.app/')) {
                $manifest = $decoded;
            }
        }

        // A failed fetch is remembered too, for a shorter while, so a
        // broken CDN does not turn every admin page load into a request.
        set_site_transient(self::TRANSIENT, ['manifest' => $manifest, 'checked' => time()], $manifest === null ? HOUR_IN_SECONDS : self::TTL);

        return $manifest;
    }

    public static function forget(): void
    {
        delete_site_transient(self::TRANSIENT);
    }

    /** The row WordPress's updater understands, or null when up to date. */
    public static function update(): ?stdClass
    {
        $manifest = self::manifest();

        if ($manifest === null || version_compare((string) $manifest['version'], MANFAA_CASHBACK_VERSION, '<=')) {
            return null;
        }

        $row = new stdClass;
        $row->id = 'manfaa.app/woocommerce/manfaa-cashback';
        $row->slug = 'manfaa-cashback';
        $row->plugin = self::basename();
        $row->new_version = (string) $manifest['version'];
        $row->url = 'https://manfaa.app/app/';
        $row->package = (string) $manifest['download_url'];
        $row->requires = (string) ($manifest['requires'] ?? '6.9');
        $row->requires_php = (string) ($manifest['requires_php'] ?? '8.1');
        $row->tested = (string) ($manifest['tested'] ?? '');
        $row->icons = [];
        $row->banners = [];

        return $row;
    }

    /** @param mixed $transient */
    public static function inject($transient)
    {
        if (! is_object($transient)) {
            return $transient;
        }

        $update = self::update();
        $key = self::basename();

        if ($update !== null) {
            $transient->response ??= [];
            $transient->response[$key] = $update;
            unset($transient->no_update[$key]);
        } else {
            unset($transient->response[$key]);
        }

        return $transient;
    }

    /** "View details" for this plugin: the readme's facts from the manifest. */
    public static function details(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'manfaa-cashback') {
            return $result;
        }

        $manifest = self::manifest() ?? [];

        $info = new stdClass;
        $info->name = 'Manfaa Cashback';
        $info->slug = 'manfaa-cashback';
        $info->version = (string) ($manifest['version'] ?? MANFAA_CASHBACK_VERSION);
        $info->author = '<a href="https://manfaa.app">Manfaa</a>';
        $info->homepage = 'https://manfaa.app/app/';
        $info->download_link = (string) ($manifest['download_url'] ?? '');
        $info->requires = (string) ($manifest['requires'] ?? '6.9');
        $info->requires_php = (string) ($manifest['requires_php'] ?? '8.1');
        $info->last_updated = (string) ($manifest['released_at'] ?? '');
        $info->sections = [
            'description' => __('Pays Manfaa cashback on WooCommerce orders. Buyers enter their Manfaa code on the cart; cashback is posted when the order reaches the status you choose and reversed on cancellation or refund.', 'manfaa-cashback'),
            'changelog' => sprintf('<p>%s <a href="https://manfaa.app/docs/integration-guide.html#the-woocommerce-plugin">manfaa.app/docs</a></p>', esc_html__('Release notes:', 'manfaa-cashback')),
        ];

        return $info;
    }

    /** @param array<int, string> $links */
    public static function rowMeta(array $links, string $file): array
    {
        if ($file === self::basename()) {
            $links[] = '<a href="'.esc_url(wp_nonce_url(admin_url('update-core.php?force-check=1'), 'upgrade-core')).'">'.esc_html__('Check for updates', 'manfaa-cashback').'</a>';
        }

        return $links;
    }
}
