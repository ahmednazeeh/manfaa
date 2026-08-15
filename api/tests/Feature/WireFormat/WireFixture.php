<?php

declare(strict_types=1);

namespace Tests\Feature\WireFormat;

/**
 * Shared helpers for the wire-format suite (PLAN §1, decision 2026-08-15:
 * percent strings on the wire, basis points inside).
 *
 * Not a *Test.php file — PHPUnit never collects it; the tests reach it
 * through the Tests\ PSR-4 map.
 */
final class WireFixture
{
    /**
     * Every key anywhere in a decoded response body, flattened with its
     * path — the tool the "no basis points on the wire" assertions use.
     *
     * @param  mixed  $body
     * @return list<string>
     */
    public static function keyPaths($body, string $prefix = ''): array
    {
        if (! is_array($body)) {
            return [];
        }

        $paths = [];

        foreach ($body as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (! is_int($key)) {
                $paths[] = $path;
            }

            $paths = [...$paths, ...self::keyPaths($value, $path)];
        }

        return $paths;
    }

    /**
     * The key paths that carry a basis-point field name. MUST be empty for
     * every request and response body: `rate_bp` / `fee_bp` and their
     * relatives are the platform's internal representation only.
     *
     * @param  mixed  $body
     * @return list<string>
     */
    public static function basisPointKeys($body): array
    {
        return array_values(array_filter(
            self::keyPaths($body),
            fn (string $path): bool => preg_match('/(^|\.)[a-z_]*_bp$/', $path) === 1,
        ));
    }
}
