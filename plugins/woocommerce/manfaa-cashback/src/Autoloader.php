<?php

declare(strict_types=1);

namespace Manfaa\Cashback;

/**
 * PSR-4 for `Manfaa\Cashback\` over `src/`. The plugin has no runtime
 * Composer dependencies on purpose — WordPress already ships an HTTP
 * client, PHP ships sodium, and WooCommerce ships Action Scheduler — so the
 * zip a merchant installs is exactly these files.
 */
final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Manfaa\\Cashback\\';

            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }
}
