<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Models\Order;
use App\Models\SettlementPayment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Has this bank credit already been spent?
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
 * So the question is asked of EVERY identifier a row carries, against BOTH
 * tables, reading both the keyed column and the recorded set.
 *
 * This is still a courtesy check, not the guarantee: two workers reading the
 * same history in the same instant would both see a credit unclaimed. The
 * guarantee remains the unique index on `matched_trx_id`, which is why that
 * column keeps a single stable value per credit.
 */
final readonly class BankCreditClaim
{
    /**
     * @param  int|null  $exceptOrder  an order allowed to already hold it (itself)
     * @param  int|null  $exceptPayment  a settlement payment allowed to already hold it
     */
    public function taken(BankRow $row, ?int $exceptOrder = null, ?int $exceptPayment = null): bool
    {
        $identifiers = $row->identifiers();

        if ($identifiers === []) {
            // A row we cannot name is a row we cannot prove unspent.
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
        $this->constrain($payments, $identifiers);

        if ($exceptPayment !== null) {
            $payments->whereKeyNot($exceptPayment);
        }

        return $payments->exists();
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $identifiers
     */
    private function constrain(Builder $query, array $identifiers): void
    {
        $query->where(function (Builder $inner) use ($identifiers): void {
            $inner->whereIn('matched_trx_id', $identifiers);

            foreach ($identifiers as $identifier) {
                $inner->orWhereJsonContains('matched_trx_refs', $identifier);
            }
        });
    }
}
