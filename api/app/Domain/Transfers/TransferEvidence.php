<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

/**
 * The evidence rules a merchant-side transfer is matched on — shared by
 * {@see SettlementPaymentVerifier} and {@see WalletTopUpVerifier}, which
 * walk the same ladder over the same bank rows and differ only in what a
 * match funds.
 *
 * One home rather than two copies, because every rule here was calibrated
 * against a real mis-match (settlements 5 and 8) and a second copy would
 * drift the moment one of them is tuned again.
 */
final readonly class TransferEvidence
{
    public function __construct(private NameMatcher $names) {}

    /**
     * Does the receipt mention this value?
     *
     * Both sides are stripped to letters and digits before comparing, so
     * OCR's spacing and punctuation cannot decide the answer: the slip's
     * "Transaction# 90863389" contains the history's "90863389", and its
     * "From Interbridge Pvt Ltd" contains "INTERBRIDGE".
     *
     * Short needles are refused. A two-character bank name inside eight
     * thousand characters of receipt would match nothing in particular, and
     * a false match here settles a bill against somebody else's money.
     */
    public static function receiptMentions(string $receipt, ?string $needle): bool
    {
        if ($receipt === '' || $needle === null) {
            return false;
        }

        $haystack = self::alnum($receipt);
        $needle = self::alnum($needle);

        return mb_strlen($needle) >= 5 && str_contains($haystack, $needle);
    }

    /**
     * Does the receipt name this payer, allowing for spelling?
     *
     * Deliberately stricter than "are these words somewhere on the page".
     * Only a CONTIGUOUS run of the receipt's words is compared, the run is
     * exactly as long as the bank's name, and {@see NameMatcher} requires
     * EVERY token to find a partner — so a long slip cannot assemble a payer
     * out of words scattered across it, which is the way this kind of match
     * settles a bill against somebody else's money.
     *
     * A single-word payer is refused outright. "AHMED" fuzzily matching one
     * word of a receipt is not evidence; the exact-containment rule above
     * already accepts a distinctive single name like "INTERBRIDGE".
     */
    public function receiptNames(string $receipt, ?string $payer, int $minimum): ?int
    {
        if ($receipt === '' || $payer === null) {
            return null;
        }

        $wanted = self::words($payer);
        $words = self::words($receipt);

        if (count($wanted) < 2 || count($words) < count($wanted)) {
            return null;
        }

        $best = null;
        $size = count($wanted);
        $last = count($words) - $size;

        for ($i = 0; $i <= $last; $i++) {
            $score = $this->names->score($payer, implode(' ', array_slice($words, $i, $size)));

            if ($score !== null && $score >= $minimum && ($best === null || $score > $best)) {
                $best = $score;
            }
        }

        return $best;
    }

    /**
     * The best score any of the names we hold on file earns against the
     * bank's payer — the last rung of the ladder.
     *
     * @param  list<string>  $expected
     */
    public function bestNameScore(array $expected, string $payer): int
    {
        $score = 0;

        foreach ($expected as $candidate) {
            $score = max($score, (int) $this->names->score($candidate, $payer));
        }

        return $score;
    }

    /**
     * The merchant's typed reference against the row's.
     *
     * Compared loosely on purpose — a person retyping a reference from a
     * banking app drops spaces and hyphens, and MIB carries both a composite
     * `trxNumber` and the short `trxNumber2`. A containment test in either
     * direction catches the honest transcription without accepting a
     * different transfer: these strings are long and bank-issued.
     */
    public static function sameReference(string $typed, BankRow $row): bool
    {
        $normalise = static fn (?string $value): string => preg_replace(
            '/[^A-Z0-9]/',
            '',
            mb_strtoupper((string) $value),
        ) ?? '';

        $typed = $normalise($typed);

        if (mb_strlen($typed) < 6) {
            // Too short to be evidence. Somebody typed "123" or the batch
            // number, and a containment test on that would match anything.
            return false;
        }

        foreach ($row->identifiers() as $candidate) {
            $candidate = $normalise($candidate);

            if ($candidate === '') {
                continue;
            }

            if ($candidate === $typed
                || str_contains($candidate, $typed)
                || str_contains($typed, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The names a merchant's transfer could plausibly arrive under: what
     * their bank calls them first, the trading name as a fallback.
     *
     * @return list<string>
     */
    public static function merchantNames(?string $bankAccountName, ?string $tradingName): array
    {
        $names = [];

        foreach ([$bankAccountName, $tradingName] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '' && ! in_array($candidate, $names, true)) {
                $names[] = $candidate;
            }
        }

        return $names;
    }

    /**
     * The receipt as words, punctuation and digits dropped.
     *
     * OCR renders "AHMD.NAZEEH" without a space, so splitting on anything
     * that is not a letter is what recovers the two names.
     *
     * @return list<string>
     */
    private static function words(string $value): array
    {
        $value = mb_strtoupper($value);
        $value = (string) preg_replace('/[^A-Z]+/u', ' ', $value);

        return preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function alnum(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}
