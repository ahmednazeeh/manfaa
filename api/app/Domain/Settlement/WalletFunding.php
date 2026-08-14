<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Cashback\Actor;
use App\Domain\Ledger\Postings;
use App\Domain\Money\Laari;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\MerchantWallet;
use App\Models\Settlement;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The merchant wallet as an alternate funding source on the same settlement
 * path (§7): same states, same confirm flow, same ledger accounts — only the
 * funding leg differs (walletSettle instead of bankSettlementReceived).
 *
 * Wallet settlement is deliberately all-or-nothing: the balance must cover
 * the whole amount due. Partial wallet funding does not exist — a partial
 * would need its own oldest-first walk plus a rule for the stranded
 * remainder, and §7 already assigns partials to the bank-payment path where
 * an admin matches real money. A merchant short of balance tops up first or
 * pays by bank.
 */
final class WalletFunding
{
    public function __construct(
        private readonly Postings $postings,
        private readonly LineAllocator $allocator,
    ) {}

    /**
     * Records a bank transfer into the wallet: movement row plus the §8
     * wallet top-up journal (DR Settlement Cash / CR Merchant Wallet
     * Balance).
     */
    public function recordTopUp(Merchant $merchant, Laari $amount, string $bankRef): WalletTransaction
    {
        if ($amount->value() <= 0) {
            throw new InvalidArgumentException('A wallet top-up must be a positive amount.');
        }

        return DB::transaction(function () use ($merchant, $amount, $bankRef): WalletTransaction {
            $movement = $this->credit($merchant, $amount->value(), 'top_up', description: sprintf('Bank top-up (%s)', $bankRef));

            $this->postings->walletTopUp($amount->value(), referenceId: $movement->id);

            return $movement;
        });
    }

    /**
     * Settles a submitted batch entirely from the wallet: debits the balance,
     * posts walletSettle (DR Merchant Wallet Balance / CR Merchant
     * Receivable), confirms every line through the shared allocation flow,
     * and marks the settlement settled.
     */
    public function settleFromWallet(Settlement $settlement, MerchantUser $actor): Settlement
    {
        return DB::transaction(function () use ($settlement, $actor): Settlement {
            $settlement = Settlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();

            if ($settlement->state !== SettlementState::AwaitingPayment || $settlement->amount_received_laari !== 0) {
                throw InvalidSettlementStateException::forAction($settlement, 'wallet settlement', 'an awaiting_payment batch with nothing received');
            }

            $required = $settlement->amount_due_laari;

            $this->debit(
                $settlement->merchant,
                $required,
                'settlement',
                'settlement',
                $settlement->id,
                sprintf('Wallet settlement %s', $settlement->reference),
            );

            $this->postings->walletSettle($required, referenceId: $settlement->id);

            $now = CarbonImmutable::now('UTC');

            foreach (SettlementLines::inAllocationOrder($settlement) as $line) {
                $this->allocator->allocate($line, Actor::merchantUser($actor->id), $now);
            }

            $settlement->forceFill([
                'state' => SettlementState::Settled,
                'funding_method' => 'wallet',
                'amount_received_laari' => $required,
            ])->save();

            return $settlement;
        });
    }

    /**
     * The merchant's current balance, read under the wallet row lock so a
     * caller inside a DB transaction can plan debits against a figure no
     * concurrent movement can invalidate before it commits.
     */
    public function lockedBalance(Merchant $merchant): int
    {
        return $this->lockedWallet($merchant)->balance_laari;
    }

    /**
     * Balance-mutating primitive shared with the allocator (over- and
     * unallocated payment remainders become wallet credits). Writes the
     * movement row only — the caller owns the matching ledger posting.
     */
    public function credit(
        Merchant $merchant,
        int $amountLaari,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): WalletTransaction {
        return $this->move($merchant, $amountLaari, $type, $referenceType, $referenceId, $description);
    }

    /**
     * Negative movement; throws rather than ever allowing the balance below
     * zero. The caller owns the matching ledger posting.
     */
    public function debit(
        Merchant $merchant,
        int $amountLaari,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): WalletTransaction {
        return $this->move($merchant, -$amountLaari, $type, $referenceType, $referenceId, $description);
    }

    private function move(
        Merchant $merchant,
        int $signedLaari,
        string $type,
        ?string $referenceType,
        ?int $referenceId,
        ?string $description,
    ): WalletTransaction {
        if ($signedLaari === 0) {
            throw new InvalidArgumentException('A wallet movement cannot be zero.');
        }

        return DB::transaction(function () use ($merchant, $signedLaari, $type, $referenceType, $referenceId, $description): WalletTransaction {
            $wallet = $this->lockedWallet($merchant);
            $balance = $wallet->balance_laari + $signedLaari;

            if ($balance < 0) {
                throw InsufficientWalletBalanceException::for($merchant, -$signedLaari, $wallet->balance_laari);
            }

            $wallet->forceFill(['balance_laari' => $balance])->save();

            return $wallet->transactions()->create([
                'amount_laari' => $signedLaari,
                'balance_after_laari' => $balance,
                'currency' => 'MVR',
                'type' => $type,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }

    private function lockedWallet(Merchant $merchant): MerchantWallet
    {
        MerchantWallet::query()->firstOrCreate(
            ['merchant_id' => $merchant->id],
            ['balance_laari' => 0, 'currency' => 'MVR'],
        );

        /** @var MerchantWallet */
        return MerchantWallet::query()->where('merchant_id', $merchant->id)->lockForUpdate()->firstOrFail();
    }
}
