<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Models\Order;
use App\Models\SettlementPayment;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Has this bank credit already been spent?
 *
 * THE PRIMARY GUARD, as of 2026-08-25. Until then the verifiers also
 * required the bank credit to EQUAL the merchant's typed amount, and that
 * equality was quietly carrying anti-fraud weight — a stranger's credit had
 * to be for exactly the right figure as well as answer to the right
 * reference. The owner removed it (a merchant typed MVR 20.00, sent MVR
 * 10.00, and a real transfer sat in a queue over the typo), so what now
 * stands between a merchant and somebody else's transfer is the evidence
 * ladder plus THIS: the rule that one credit funds exactly one thing.
 *
 * One credit may only ever settle one thing. That is easy to state and easy
 * to get wrong in two ways, both of which were live before this class:
 *
 *  1. **Across tables.** A credit spent on a settlement could still verify a
 *     customer order, because the order side only ever looked at `orders`.
 *     The unique indexes cannot catch this — they are per-table, and Postgres
 *     has no cross-table uniqueness.
 *  2. **Across a row's own names.** BML files a transfer as `FT26235BDLZB\B26`
 *     and prints `BLAZ861828284421` on the merchant's slip. Checking only the
 *     identifier we happened to keep leaves the other one unguarded.
 *
 * So the question is asked of EVERY identifier a row carries, against ALL
 * THREE tables that spend credits — orders, settlement payments and wallet
 * top-ups (2026-08-24) — reading both the keyed column and the recorded set.
 *
 * A third way surfaced in the wallet round: **credits reconciled by hand**.
 * An admin matching a settlement payment, or booking a transfer straight
 * into a wallet, never saw a bank row, so no matched_trx_* was written and
 * the credit stayed "unspent" for the verifiers — one bank transfer could
 * settle a batch by hand and then fund a top-up automatically. So the
 * merchant-typed / admin-typed reference is read too: `bank_ref` on a
 * MATCHED settlement payment or top-up, and on every wallet movement,
 * compared the way {@see TransferEvidence::sameReference} compares — letters
 * and digits only, case-folded.
 *
 * This is still a courtesy check, not the guarantee: two workers reading the
 * same history in the same instant would both see a credit unclaimed. Within
 * one table the unique index on `matched_trx_id` is the guarantee; across
 * tables the verifiers serialise on {@see lock()} and ask again under it.
 */
final readonly class BankCreditClaim
{
    /**
     * @param  int|null  $exceptOrder  an order allowed to already hold it (itself)
     * @param  int|null  $exceptPayment  a settlement payment allowed to already hold it
     * @param  int|null  $exceptTopUp  a wallet top-up allowed to already hold it
     */
    public function taken(BankRow $row, ?int $exceptOrder = null, ?int $exceptPayment = null, ?int $exceptTopUp = null): bool
    {
        $identifiers = $row->identifiers();

        if ($identifiers === []) {
            // A row we cannot name is a row we cannot prove unspent.
            return true;
        }

        return $this->spent($identifiers, $exceptOrder, $exceptPayment, $exceptTopUp);
    }

    /**
     * The same question for a reference that never came from a bank row —
     * the one an admin types when matching a top-up by hand.
     *
     * @param  list<string>  $identifiers
     */
    public function spent(array $identifiers, ?int $exceptOrder = null, ?int $exceptPayment = null, ?int $exceptTopUp = null): bool
    {
        $identifiers = array_values(array_unique(array_filter(array_map('trim', $identifiers), static fn (string $v): bool => $v !== '')));

        if ($identifiers === []) {
            return true;
        }

        $orders = Order::query();
        $this->constrain($orders, $identifiers);

        if ($exceptOrder !== null) {
            $orders->whereKeyNot($exceptOrder);
        }

        if ($orders->exists()) {
            return true;
        }

        $payments = SettlementPayment::query();
        $this->constrain($payments, $identifiers, byReferenceWhen: 'matched');

        if ($exceptPayment !== null) {
            $payments->whereKeyNot($exceptPayment);
        }

        if ($payments->exists()) {
            return true;
        }

        $topUps = WalletTopUp::query();
        $this->constrain($topUps, $identifiers, byReferenceWhen: 'matched');

        if ($exceptTopUp !== null) {
            $topUps->whereKeyNot($exceptTopUp);
        }

        if ($topUps->exists()) {
            return true;
        }

        // Money already IN a wallet under this reference — the admin
        // top-up route, or a top-up claim credited earlier — regardless of
        // whose wallet: a credit booked to merchant A must not fund B.
        return WalletTransaction::query()
            ->whereNotNull('bank_ref')
            ->whereIn(self::normalised('bank_ref'), self::normalise($identifiers))
            ->exists();
    }

    /**
     * Serialise every verifier that wants to spend this credit. Postgres
     * advisory locks are the one cross-table lock we have: taken inside the
     * caller's transaction and released with it, keyed on the credit's
     * identifiers, so two workers matching the same transfer against two
     * different tables cannot both read "unspent" and both commit — the
     * second waits, then asks {@see taken()} again and finds it gone.
     */
    public function lock(BankRow $row): void
    {
        $this->lockReferences($row->identifiers());
    }

    /**
     * The same serialisation for a path holding REFERENCES rather than a
     * bank row — the admin matching a top-up off a statement by hand
     * (2026-08-25). That path checks {@see spent()} like the verifiers do,
     * but held no lock while it did, so it could read "unspent" in the same
     * instant a verifier did and both commit.
     *
     * Two details make this actually meet the verifiers rather than merely
     * look like it:
     *
     *  - the keys are NORMALISED the way {@see spent()} compares — letters
     *    and digits, upper-cased — so an admin's "ref same" and a bank row's
     *    "REF-SAME" hash to the same lock instead of passing each other;
     *  - EVERY identifier is locked, not just the one we keyed on, because a
     *    BML credit answers to two names and the two paths may each be
     *    holding a different one. Sorted first: taking several locks in a
     *    deterministic order is what stops two transactions holding half of
     *    each other's set.
     *
     * @param  list<string>  $references
     */
    public function lockReferences(array $references): void
    {
        $keys = self::normalise($references);
        sort($keys);

        foreach ($keys as $key) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
        }
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $identifiers
     * @param  string|null  $byReferenceWhen  also treat the row's own bank_ref as spent, in this state
     */
    private function constrain(Builder $query, array $identifiers, ?string $byReferenceWhen = null): void
    {
        $query->where(function (Builder $inner) use ($identifiers, $byReferenceWhen): void {
            $inner->whereIn('matched_trx_id', $identifiers);

            foreach ($identifiers as $identifier) {
                $inner->orWhereJsonContains('matched_trx_refs', $identifier);
            }

            if ($byReferenceWhen !== null) {
                $inner->orWhere(function (Builder $byRef) use ($identifiers, $byReferenceWhen): void {
                    $byRef->where('state', $byReferenceWhen)
                        ->whereNotNull('bank_ref')
                        ->whereIn(self::normalised('bank_ref'), self::normalise($identifiers));
                });
            }
        });
    }

    /** The column, letters and digits only, upper-cased — in SQL. */
    private static function normalised(string $column): Expression
    {
        return DB::raw(sprintf("regexp_replace(upper(%s), '[^A-Z0-9]', '', 'g')", $column));
    }

    /**
     * @param  list<string>  $identifiers
     * @return list<string>
     */
    private static function normalise(array $identifiers): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value)),
            $identifiers,
        ), static fn (string $v): bool => $v !== '')));
    }
}
