<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use Carbon\CarbonImmutable;

/**
 * One bank row that the evidence says COULD be this merchant's transfer,
 * with the rung it answered on — and the rule for deciding which of several
 * such rows actually is.
 *
 * WHY THIS EXISTS (verifier round, 2026-08-25). Both verifiers used to walk
 * the history row by row and take the first row that answered ANY rung. That
 * was safe only because the amount had to be equal too: equality was quietly
 * acting as the ROW SELECTOR, skipping every row that was not the merchant's
 * transfer, so by the time a row reached the ladder there was usually only
 * one candidate. With the amount no longer a gate (owner: the typed figure is
 * a claim, the bank credit is the fact) first-hit means the ORDER the bank
 * returns its statement in decides which credit funds the batch — a weak
 * payer-name hit on an earlier row beating a perfect reference match on a
 * later one, and the earlier row's amount becoming the money. The platform
 * account is shared by every merchant, so the earlier row is frequently not
 * the claimant's at all.
 *
 * So every row is scored and the BEST one is matched, in this order:
 *
 *  1. the strength of the rung it answered — a bank-issued identifier the
 *     merchant typed or their slip quotes outranks any name;
 *  2. a row for exactly the claimed amount, over one that is not;
 *  3. the smaller distance from the claimed amount;
 *  4. the higher name score, where that is what separated them;
 *  5. the row closest in time to the claim.
 *
 * Ties keep the bank's own order, so the walk stays deterministic.
 */
final readonly class BankRowMatch
{
    /** The merchant typed a bank-issued identifier and we found it. */
    public const int RULE_REFERENCE = 50;

    /** Their own slip quotes one — the same proof, off a document. */
    public const int RULE_RECEIPT_REFERENCE = 40;

    /** The bank's payer, named on the slip. */
    public const int RULE_RECEIPT_NAME = 30;

    /** The same payer, allowing for how the slip spells them. */
    public const int RULE_RECEIPT_NAME_FUZZY = 20;

    /** The names we hold on file. The weakest thing on any ladder. */
    public const int RULE_NAME = 10;

    public function __construct(
        public BankRow $row,
        public string $rule,
        public int $rank,
        public int $score,
    ) {}

    /**
     * Is this candidate a better answer than the one we are holding?
     *
     * @param  int  $claimLaari  what the merchant said they sent — not a gate any
     *                           more, but still the best tie-breaker there is
     * @param  CarbonImmutable|null  $claimedAt  when they said it
     */
    public function beats(?self $incumbent, int $claimLaari, ?CarbonImmutable $claimedAt): bool
    {
        if ($incumbent === null) {
            return true;
        }

        if ($this->rank !== $incumbent->rank) {
            return $this->rank > $incumbent->rank;
        }

        $mine = abs($this->row->amountLaari - $claimLaari);
        $theirs = abs($incumbent->row->amountLaari - $claimLaari);

        if ($mine !== $theirs) {
            return $mine < $theirs;
        }

        if ($this->score !== $incumbent->score) {
            return $this->score > $incumbent->score;
        }

        $mineWhen = self::distance($this->row->at, $claimedAt);
        $theirsWhen = self::distance($incumbent->row->at, $claimedAt);

        return $mineWhen < $theirsWhen;
    }

    /** Seconds between a statement row and the claim, or "unknowable". */
    private static function distance(?CarbonImmutable $at, ?CarbonImmutable $claimedAt): int
    {
        if ($at === null || $claimedAt === null) {
            return PHP_INT_MAX;
        }

        return abs($at->getTimestamp() - $claimedAt->getTimestamp());
    }
}
