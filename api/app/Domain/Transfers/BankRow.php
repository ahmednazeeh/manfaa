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

    /**
     * The identifier this credit is KEYED on — dedup, storage, the unique
     * index (owner, 2026-08-20: "BLAZ reference number is always consistent,
     * so use it for dedup, storage etc").
     *
     * For BML that is `narrative2`, the reference its app prints on the
     * merchant's slip, in preference to the internal statement id in
     * `reference` (`FT26235BDLZB\B26`). MIB has only the one, so this
     * returns what it always did.
     *
     * Deterministic on purpose: the same credit must always reduce to the
     * same key, or the unique index guarantees nothing. MATCHING is not
     * narrowed by this — {@see identifiers()} is what the rules read, so a
     * merchant whose slip shows the FT reference instead still matches, and
     * still lands under the BLAZ key.
     */
    public function key(): string
    {
        $slip = $this->raw['narrative2'] ?? null;

        if (is_string($slip) && trim($slip) !== '') {
            return trim($slip);
        }

        return $this->reference;
    }

    /**
     * Every bank-issued identifier this row carries, not only the one
     * normalised into `reference`.
     *
     * The two banks disagree about which field is "the" reference, and the
     * one the MERCHANT sees is not always the one we keep. MIB's
     * `trxNumber2` is the merchant-facing one. BML's `reference`/`id` is an
     * internal statement id — `FT26235BDLZB\B26` — while the reference BML's
     * own app prints on the transfer slip arrives in `narrative2`
     * (`BLAZ861828284421`). Matching a receipt or a typed reference against
     * `reference` alone therefore cannot confirm a BML transfer even when the
     * evidence is perfect: settlement 8 failed on exactly that.
     *
     * `narrative1` is deliberately excluded — it holds a timestamp
     * (`21-08-2026 03-38-58`), and dates appear on every receipt, so treating
     * it as an identifier would match transfers at random. `narrative3` is the
     * payer's name, which is already {@see $name}.
     *
     * @return list<string>
     */
    public function identifiers(): array
    {
        $values = [$this->reference];

        foreach (['trxNumber', 'trxNumber2', 'descr2', 'narrative2'] as $key) {
            $value = $this->raw[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $values[] = trim($value);
            }
        }

        return array_values(array_unique(array_filter($values, static fn (string $v): bool => $v !== '')));
    }
}
