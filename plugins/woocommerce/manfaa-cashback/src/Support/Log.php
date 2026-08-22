<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Support;

/** WooCommerce's logger, source `manfaa-cashback`. Never the token, never the buyer's name. */
final class Log
{
    private const SOURCE = 'manfaa-cashback';

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }

        wc_get_logger()->log($level, $message, ['source' => self::SOURCE] + $context);
    }
}
