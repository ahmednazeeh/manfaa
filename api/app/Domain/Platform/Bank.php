<?php

declare(strict_types=1);

namespace App\Domain\Platform;

/**
 * The banks money can move through on this platform.
 *
 * Every bank field used to be free text, which meant "BML", "Bank of
 * Maldives", "bank of maldives" and a typo were four different banks to the
 * database and one bank to a payments clerk. That is tolerable in a
 * merchant's own notes and not tolerable in a bulk transfer file, where the
 * bank column decides whether a customer gets paid.
 *
 * A closed set also lets the panels show a logo instead of a string, which
 * is the difference between a form that asks someone to type their bank's
 * name correctly and one that asks them to recognise it.
 *
 * Adding a bank is a deploy: a new case here, its logo in each app's
 * public/banks, and its entry in the panels' BANKS map. Deliberately not a
 * database table — there are two retail banks in the Maldives, the list
 * changes on the timescale of banking licences, and a table would buy an
 * admin screen nobody needs in exchange for a join on every payout row.
 */
enum Bank: string
{
    case Bml = 'bml';
    case Mib = 'mib';

    /** The bank's full legal-ish name, for forms and printed instructions. */
    public function label(): string
    {
        return match ($this) {
            self::Bml => 'Bank of Maldives',
            self::Mib => 'Maldives Islamic Bank',
        };
    }

    /**
     * What Maldivians actually call it — the form that fits in a table cell
     * and on a transfer reference.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Bml => 'BML',
            self::Mib => 'MIB',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $bank): string => $bank->value, self::cases());
    }

    /**
     * Reads a stored or submitted bank, tolerating the free-text era: rows
     * written before this enum existed hold things like "BML" or "Bank of
     * Maldives" rather than a slug. Returns null for anything unrecognised,
     * so a caller renders what it was given instead of asserting a bank
     * nobody chose.
     */
    public static function parse(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $needle = strtolower(trim($value));

        foreach (self::cases() as $bank) {
            if ($needle === $bank->value
                || $needle === strtolower($bank->shortLabel())
                || $needle === strtolower($bank->label())) {
                return $bank;
            }
        }

        return null;
    }
}
