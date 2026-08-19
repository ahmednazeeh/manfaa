<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use Carbon\CarbonImmutable;

/**
 * One line of bank history, normalised out of two very different shapes.
 *
 * MIB and BML answer with their own field names — MIB passes MIB's through
 * verbatim — so the differences are absorbed here, once, rather than in
 * every caller that wants to know "did this money arrive".
 */
final readonly class BankRow
{
    public function __construct(
        /** The bank's own reference. MIB `trxNumber2`, BML `reference`/`id`. */
        public string $reference,
        /** The counterparty as the bank recorded it, already de-prefixed. */
        public string $name,
        /** Unsigned, in integer laari. */
        public int $amountLaari,
        /** True when money came IN to our account. */
        public bool $incoming,
        public ?CarbonImmutable $at,
        /** The untouched row, for an operator who needs to see the original. */
        public array $raw,
    ) {}
}
