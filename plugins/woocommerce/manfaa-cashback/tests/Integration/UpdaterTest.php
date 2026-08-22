<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Tests\Integration;

use Manfaa\Cashback\Support\Updater;

final class UpdaterTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        Updater::forget();
    }

    private function manifest(string $version): array
    {
        return [
            'slug' => 'manfaa-cashback', 'version' => $version,
            'download_url' => "https://manfaa.app/app/woocommerce/manfaa-cashback-{$version}.zip",
            'requires' => '6.9', 'requires_php' => '8.1', 'released_at' => '2026-08-22T00:00:00Z',
        ];
    }

    public function test_a_newer_manifest_is_offered_as_an_update(): void
    {
        $this->answer(200, $this->manifest('9.9.9'));

        $transient = Updater::inject((object) ['response' => [], 'no_update' => []]);
        $row = $transient->response[Updater::basename()] ?? null;

        self::assertNotNull($row);
        self::assertSame('9.9.9', $row->new_version);
        self::assertSame('https://manfaa.app/app/woocommerce/manfaa-cashback-9.9.9.zip', $row->package);
        self::assertSame(Updater::MANIFEST_URL, $this->lastRequest()['url']);

        // Cached: the second ask does not fetch again.
        Updater::inject((object) ['response' => [], 'no_update' => []]);
        self::assertCount(1, $this->requests);
    }

    public function test_the_same_or_older_version_is_not_an_update(): void
    {
        $this->answer(200, $this->manifest(MANFAA_CASHBACK_VERSION));
        $transient = Updater::inject((object) ['response' => [Updater::basename() => (object) ['stale' => true]], 'no_update' => []]);
        self::assertArrayNotHasKey(Updater::basename(), $transient->response);

        Updater::forget();
        $this->answer(200, $this->manifest('0.0.1'));
        self::assertNull(Updater::update());
    }

    public function test_a_manifest_from_anywhere_but_manfaa_is_ignored(): void
    {
        $this->answer(200, ['version' => '9.9.9', 'download_url' => 'https://evil.example/x.zip']);
        self::assertNull(Updater::update());

        Updater::forget();
        $this->answer(500, []);
        self::assertNull(Updater::update());
        // A failure is remembered briefly, not retried on every page.
        self::assertNull(Updater::update());
        self::assertCount(2, $this->requests);
    }

    public function test_view_details_comes_from_the_manifest(): void
    {
        $this->answer(200, $this->manifest('9.9.9'));
        $info = Updater::details(false, 'plugin_information', (object) ['slug' => 'manfaa-cashback']);

        self::assertSame('9.9.9', $info->version);
        self::assertStringContainsString('manfaa-cashback-9.9.9.zip', $info->download_link);
        self::assertFalse(Updater::details(false, 'plugin_information', (object) ['slug' => 'other']));
    }
}
