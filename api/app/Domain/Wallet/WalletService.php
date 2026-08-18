<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Models\Customer;
use App\Models\CustomerPayout;
use App\Models\CustomerWallet;
use App\Models\CustomerWalletEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The customer wallet (owner decision 2026-08-19).
 *
 * Balance and ledger move together, always, under a row lock — the balance
 * column is a cache of the entries, and the moment the two can disagree the
 * number on a customer's screen stops being evidence of anything.
 */
final readonly class WalletService
{
    public function walletFor(Customer $customer): CustomerWallet
    {
        return CustomerWallet::query()->firstOrCreate(
            ['customer_id' => $customer->getKey()],
            ['balance_laari' => 0, 'currency' => 'MVR'],
        );
    }

    /**
     * Put money in.
     *
     * Idempotent per (reference_type, reference_id): crediting one refund
     * twice IS refunding twice, and a retried job must not be able to.
     */
    public function credit(
        Customer $customer,
        int $amountLaari,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): ?CustomerWalletEntry {
        if ($amountLaari <= 0) {
            return null;
        }

        $this->walletFor($customer);

        return DB::transaction(function () use ($customer, $amountLaari, $type, $referenceType, $referenceId, $description): ?CustomerWalletEntry {
            $wallet = CustomerWallet::query()
                ->where('customer_id', $customer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($referenceType !== null && $referenceId !== null) {
                $already = CustomerWalletEntry::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->where('type', $type)
                    ->exists();

                if ($already) {
                    return null;
                }
            }

            $balance = $wallet->balance_laari + $amountLaari;
            $wallet->forceFill(['balance_laari' => $balance])->save();

            return $wallet->entries()->create([
                'amount_laari' => $amountLaari,
                'balance_after_laari' => $balance,
                'type' => $type,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }

    /**
     * Ask for money out.
     *
     * The balance is debited HERE, not when the bank finally moves it: the
     * customer has committed that money, and leaving it spendable while a
     * transfer sits in a queue is how the same laari gets withdrawn twice. A
     * proven failure puts it back — see reverse().
     */
    public function requestWithdrawal(Customer $customer, int $amountLaari): CustomerPayout
    {
        if ($amountLaari <= 0) {
            throw WalletException::invalidAmount();
        }

        if (trim((string) $customer->payout_account) === '') {
            throw WalletException::noBankAccount();
        }

        $this->walletFor($customer);

        return DB::transaction(function () use ($customer, $amountLaari): CustomerPayout {
            $wallet = CustomerWallet::query()
                ->where('customer_id', $customer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($amountLaari > $wallet->balance_laari) {
                throw WalletException::insufficient($wallet->balance_laari);
            }

            $balance = $wallet->balance_laari - $amountLaari;
            $wallet->forceFill(['balance_laari' => $balance])->save();

            $payout = CustomerPayout::query()->create([
                'customer_id' => $customer->getKey(),
                'amount_laari' => $amountLaari,
                'currency' => 'MVR',
                // Snapshotted: a customer editing their bank details later
                // must not redirect a transfer already in the queue.
                'bank' => $customer->payout_bank,
                'account' => $customer->payout_account,
                'account_name' => $customer->payout_account_name,
                'internal_ref' => self::mintReference(),
                'state' => 'pending',
                'requested_at' => CarbonImmutable::now(),
            ]);

            $wallet->entries()->create([
                'amount_laari' => -$amountLaari,
                'balance_after_laari' => $balance,
                'type' => 'withdrawal',
                'reference_type' => CustomerPayout::class,
                'reference_id' => $payout->id,
                'description' => 'Withdrawal to bank',
            ]);

            return $payout;
        });
    }

    /**
     * A withdrawal that certainly did not happen. The money goes back, and
     * the ledger says why rather than quietly restating a balance.
     */
    public function reverse(CustomerPayout $payout, string $reason): void
    {
        DB::transaction(function () use ($payout, $reason): void {
            $wallet = CustomerWallet::query()
                ->where('customer_id', $payout->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $already = CustomerWalletEntry::query()
                ->where('wallet_id', $wallet->id)
                ->where('reference_type', CustomerPayout::class)
                ->where('reference_id', $payout->id)
                ->where('type', 'withdrawal_reversed')
                ->exists();

            if ($already) {
                return;
            }

            $balance = $wallet->balance_laari + $payout->amount_laari;
            $wallet->forceFill(['balance_laari' => $balance])->save();

            $wallet->entries()->create([
                'amount_laari' => $payout->amount_laari,
                'balance_after_laari' => $balance,
                'type' => 'withdrawal_reversed',
                'reference_type' => CustomerPayout::class,
                'reference_id' => $payout->id,
                'description' => $reason,
            ]);
        });
    }

    /**
     * The idempotency key the bank deduplicates on.
     *
     * Minted before the row exists and never reused, then stable forever —
     * that permanence is the whole of what makes a retry safe.
     */
    private static function mintReference(): string
    {
        return 'manfaa-w-'.CarbonImmutable::now()->format('Ymd').'-'.bin2hex(random_bytes(6));
    }
}
