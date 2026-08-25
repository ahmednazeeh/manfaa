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
     * Does the receipt mention this NAME?
     *
     * Both sides are stripped to letters and digits before comparing, so
     * OCR's spacing and punctuation cannot decide the answer: the slip's
     * "From Interbridge Pvt Ltd" contains "INTERBRIDGE".
     *
     * Short needles are refused. A two-character bank name inside eight
     * thousand characters of receipt would match nothing in particular, and
     * a false match here settles a bill against somebody else's money.
     *
     * A loose containment is right for a NAME — a payer's name is a prefix
     * of the receipt's rendering of it as often as not — and wrong for an
     * IDENTIFIER, which is why identifiers go through {@see receiptQuotes}
     * instead (2026-08-25).
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
     * Does the receipt QUOTE this bank identifier — as a whole thing, not as
     * a fragment of a longer run of characters?
     *
     * The same 5-character floor as {@see receiptMentions}, and the same
     * forgiveness of OCR's spacing and punctuation ("BLAZ 8618 2828 4421" is
     * the reference), but the run must START and END at a boundary in the
     * receipt: the character before and after it may not be a letter or a
     * digit.
     *
     * WHY THE BOUNDARY (verifier round, 2026-08-25). Until the amount stopped
     * gating a match, a receipt naming somebody else's transfer still had to
     * quote the exact figure as well. Now the identifier is the whole proof —
     * and the merchant supplies the receipt. A plain containment test over
     * the alphanumerics lets ONE uploaded slip carrying a dense run of digits
     * ("...908633889086339090863391...") quote thousands of consecutive bank
     * references at once, which is an enumeration of the platform account
     * rather than evidence about one transfer. Anchored, the slip has to name
     * the reference the way a bank prints it.
     */
    public static function receiptQuotes(string $receipt, ?string $identifier): bool
    {
        if ($receipt === '' || $identifier === null) {
            return false;
        }

        return mb_strlen(self::alnum($identifier)) >= 5
            && self::quotes($receipt, $identifier);
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
     *
     * THE SIX-CHARACTER FLOOR IS UNCHANGED, and containment is now ANCHORED
     * (verifier round, 2026-08-25). The floor alone stopped being enough the
     * moment the amount stopped gating a match: a bare `str_contains` accepts
     * any 6-character SUBSTRING of a bank-issued identifier, and MIB's live
     * references are 8 sequential digits — so a merchant who has made one
     * transfer knows the neighbourhood of everyone else's and could take an
     * unclaimed credit of any size by typing six of its digits. Anchored, the
     * typed string has to be a WHOLE identifier the row carries (or the row's
     * identifier has to be a whole part of what they typed): the composite
     * "1-703337593-804802801-1" still yields "804802801", and "804802" no
     * longer yields anything.
     */
    public static function sameReference(string $typed, BankRow $row): bool
    {
        $normalise = static fn (?string $value): string => preg_replace(
            '/[^A-Z0-9]/',
            '',
            mb_strtoupper((string) $value),
        ) ?? '';

        $raw = $typed;
        $typed = $normalise($typed);

        if (mb_strlen($typed) < 6) {
            // Too short to be evidence. Somebody typed "123" or the batch
            // number, and a containment test on that would match anything.
            return false;
        }

        foreach ($row->identifiers() as $identifier) {
            $candidate = $normalise($identifier);

            if ($candidate === '') {
                continue;
            }

            if ($candidate === $typed) {
                return true;
            }

            // The same floor on the OTHER side of the comparison: an
            // identifier of two or three characters found inside a long typed
            // string is a coincidence, not a reference.
            if (mb_strlen($candidate) < 6) {
                continue;
            }

            // Either whole thing inside the other, at a boundary — never a
            // fragment straddling one.
            if (self::quotes((string) $identifier, $raw) || self::quotes($raw, (string) $identifier)) {
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

    /**
     * Does $haystack contain $needle's alphanumerics as a WHOLE run — every
     * character in order, punctuation and spacing between them forgiven, and
     * neither end butting against another letter or digit?
     *
     * The forgiveness is what makes "BLAZ 8618 2828 4421" on an OCR'd slip
     * the reference "BLAZ861828284421", and "FT26235BDLZB\B26" the identifier
     * "FT26235BDLZBB26". The anchoring is what stops "90863389" being found
     * inside "908633890863390" — a fragment of a longer number is a different
     * transfer, and treating it as this one is how a credit gets claimed by
     * somebody it does not belong to.
     */
    private static function quotes(string $haystack, string $needle): bool
    {
        $characters = str_split(self::alnum($needle));

        if ($characters === [] || $characters === ['']) {
            return false;
        }

        $pattern = '/(?<![A-Za-z0-9])'
            .implode('[^A-Za-z0-9]*', array_map(
                static fn (string $character): string => preg_quote($character, '/'),
                $characters,
            ))
            .'(?![A-Za-z0-9])/i';

        return preg_match($pattern, $haystack) === 1;
    }
}
