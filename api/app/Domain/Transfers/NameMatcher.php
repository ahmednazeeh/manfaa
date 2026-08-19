<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use Illuminate\Support\Facades\DB;

/**
 * Does the name on a bank transfer belong to this customer?
 * (owner spec 2026-08-19)
 *
 * Bank narration is not a database field. The same person arrives as
 * "Ahmed Nazeeh", "AHMD NAZEEH" and "AHMD N ADAM", because a teller typed it
 * or a phone keyboard ate a vowel — so the comparison is TOKEN BY TOKEN, and
 * a token matches three ways:
 *
 *  1. exactly, once both are normalised;
 *  2. by trigram similarity at or above {@see THRESHOLD} — which is what
 *     lets AHMED meet AHMD;
 *  3. as an INITIAL — a single letter standing in for a whole name, which is
 *     what lets "Ahmed Nazeeh Adam" meet "AHMD N ADAM".
 *
 * The threshold was calibrated against real Maldivian name pairs rather than
 * guessed: the weakest true pair (AHMED/AHMD) scores 0.375 and the strongest
 * false pair (NAZEEH/NASEEM) scores 0.167, so 0.30 sits in the gap with room
 * on both sides.
 *
 * EVERY expected token must find a partner. A name that matches "most of"
 * somebody is a name that will eventually verify a stranger's payment.
 */
final readonly class NameMatcher
{
    /** Calibrated, not guessed — see the class docblock. */
    public const float THRESHOLD = 0.30;

    /**
     * @return int|null a 0–100 confidence, or null when it does not match
     */
    public function score(string $expected, string $actual): ?int
    {
        $wanted = self::tokens($expected);
        $given = self::tokens($actual);

        if ($wanted === [] || $given === []) {
            return null;
        }

        $total = 0.0;
        $remaining = $given;

        foreach ($wanted as $token) {
            $best = null;
            $bestIndex = null;

            foreach ($remaining as $index => $candidate) {
                $similarity = self::compare($token, $candidate);

                if ($similarity !== null && ($best === null || $similarity > $best)) {
                    $best = $similarity;
                    $bestIndex = $index;
                }
            }

            if ($best === null) {
                // One unmatched token is enough to refuse. Partial credit is
                // how a stranger's payment gets accepted.
                return null;
            }

            $total += $best;
            // Consumed: two expected tokens must not both match the same
            // word, or "Ahmed Ahmed" would match "Ahmed".
            unset($remaining[$bestIndex]);
        }

        return (int) round(($total / count($wanted)) * 100);
    }

    public function matches(string $expected, string $actual): bool
    {
        return $this->score($expected, $actual) !== null;
    }

    /**
     * How well two single words match, or null if they do not.
     */
    private static function compare(string $wanted, string $given): ?float
    {
        if ($wanted === $given) {
            return 1.0;
        }

        // An initial standing in for a name. Scored below an exact match —
        // "N" for "NAZEEH" is real evidence, but weaker than the word.
        if (self::isInitialFor($wanted, $given) || self::isInitialFor($given, $wanted)) {
            return 0.6;
        }

        $similarity = (float) DB::selectOne(
            'select similarity(?, ?) as score',
            [$wanted, $given],
        )->score;

        return $similarity >= self::THRESHOLD ? $similarity : null;
    }

    /** Is `$initial` a single letter opening `$word`? */
    private static function isInitialFor(string $initial, string $word): bool
    {
        return mb_strlen($initial) === 1
            && mb_strlen($word) > 1
            && str_starts_with($word, $initial);
    }

    /**
     * Upper-case letters and spaces only. Punctuation, titles and digits are
     * noise a bank adds, not part of anybody's name.
     *
     * @return list<string>
     */
    private static function tokens(string $name): array
    {
        $name = mb_strtoupper(trim($name));
        $name = (string) preg_replace('/[^A-Z\s]/u', ' ', $name);

        $tokens = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Honorifics carry no identity and would soak up a real token.
        return array_values(array_filter(
            $tokens,
            fn (string $token): bool => ! in_array($token, ['MR', 'MRS', 'MS', 'DR'], true),
        ));
    }
}
