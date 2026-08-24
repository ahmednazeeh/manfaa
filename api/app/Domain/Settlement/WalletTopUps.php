<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\MerchantAccess\Permission;
use App\Domain\Money\Laari;
use App\Domain\Money\MerchantMoneyCache;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Transfers\BankCreditClaim;
use App\Jobs\PollWalletTopUp;
use App\Jobs\ReadWalletTopUpReceipt;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\MerchantWallet;
use App\Models\SettlementPayment;
use App\Models\TransferSetting;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Merchant-initiated wallet top-ups (owner, 2026-08-24 — reverses PLAN §1
 * "wallet is not pre-funding").
 *
 * The SAME receipt-first act a settlement uses: the merchant picks the
 * platform account, transfers, uploads the slip, optionally types the bank
 * reference. That creates a CLAIM (wallet_top_ups, `pending`) — never money.
 * The claim becomes money on exactly one path, {@see credit()}: the
 * automatic verifier found the transfer in the bank's own history, or an
 * admin reconciled it by hand. Both call the same method, which calls
 * {@see WalletFunding::recordTopUp} — the one wallet-crediting primitive the
 * platform has, unchanged.
 *
 * Sits beside WalletFunding in the Settlement namespace on purpose: a
 * top-up exists to fund settlements, and the slip, the verifier and the
 * admin queue are all siblings of the settlement-payment ones.
 */
final class WalletTopUps
{
    /**
     * How many claims a merchant may have waiting at once. Every claim
     * costs a slip on disk, an OCR run and a bank-polling loop for the whole
     * verify window, and a top-up has no natural bound the way a settlement
     * receipt does (no payable lines needed) — so the bound is this.
     */
    public const int MAX_PENDING = 3;

    public function __construct(
        private readonly SlipStorage $slips,
        private readonly WalletFunding $wallet,
        private readonly NotificationService $notifications,
        private readonly PlatformConfig $config,
        private readonly BankCreditClaim $claims,
    ) {}

    /**
     * The merchant's claim: inspect the slip BEFORE anything is written,
     * create the pending row, store the slip under the row's id, open the
     * bank-watching window — one transaction, and the file is deleted
     * explicitly if any of it rolls back.
     *
     * @throws WalletTopUpBelowMinimumException under the platform minimum
     * @throws TooManyPendingTopUpsException MAX_PENDING claims are already waiting
     * @throws InvalidSlipException the upload is not a JPEG/PNG/WebP/PDF, or is too large
     * @throws DuplicateBankRefException this merchant already claimed or recorded that transfer
     */
    public function claim(
        Merchant $merchant,
        MerchantUser $uploader,
        Laari $amount,
        int $platformBankAccountId,
        ?string $bankRef,
        UploadedFile $slip,
    ): WalletTopUp {
        $minimum = $this->config->walletTopUpMinLaari();

        if ($amount->value() < $minimum) {
            throw WalletTopUpBelowMinimumException::of($amount, $minimum);
        }

        $bankRef = trim((string) $bankRef);
        $bankRef = $bankRef === '' ? null : $bankRef;

        $pending = WalletTopUp::query()
            ->where('merchant_id', $merchant->id)
            ->where('state', 'pending')
            ->count();

        if ($pending >= self::MAX_PENDING) {
            throw TooManyPendingTopUpsException::forMerchant($merchant, $pending);
        }

        // A reference an admin already booked straight into the wallet
        // (the admin top-up route) would only fail at match time; refuse it
        // now, while the merchant is still looking at the form.
        if ($bankRef !== null && $this->alreadyCredited($merchant, $bankRef)) {
            throw DuplicateBankRefException::forWalletTopUp($merchant, $bankRef);
        }

        // One transfer funds one thing: a reference this merchant already
        // put on a settlement receipt (pending or matched) cannot ALSO top
        // up the wallet. The per-table indexes cannot see across; this can.
        if ($bankRef !== null && $this->heldBySettlementPayment($merchant, $bankRef)) {
            throw DuplicateBankRefException::forWalletTopUpHeldBySettlement($merchant, $bankRef);
        }

        $inspection = $this->slips->inspect($slip);
        $storedPath = null;

        try {
            return DB::transaction(function () use ($merchant, $uploader, $amount, $platformBankAccountId, $bankRef, $slip, $inspection, &$storedPath): WalletTopUp {
                $topUp = WalletTopUp::query()->create([
                    'merchant_id' => $merchant->id,
                    'amount_laari' => $amount->value(),
                    'currency' => 'MVR',
                    'platform_bank_account_id' => $platformBankAccountId,
                    'bank_ref' => $bankRef,
                    'state' => 'pending',
                ]);

                $storedPath = $this->slips->storeForTopUp($merchant, $topUp, $slip, $inspection);

                $topUp->forceFill([
                    'slip_path' => $storedPath,
                    'slip_mime' => $inspection['mime'],
                    'slip_size_bytes' => $inspection['size'],
                    'uploaded_by' => $uploader->id,
                ])->save();

                $this->watchTheBank($topUp);

                return $topUp->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            $this->slips->delete($storedPath);

            throw DuplicateBankRefException::forWalletTopUpClaim($merchant, $bankRef);
        } catch (Throwable $exception) {
            $this->slips->delete($storedPath);

            throw $exception;
        }
    }

    /**
     * Open the bank-watching window on a freshly claimed top-up — the same
     * clock, stored on the row, as a settlement payment's
     * ({@see SettlementAllocator::watchTheBank}). Dispatched after commit,
     * because a job that reads the row before its transaction lands finds
     * nothing.
     */
    private function watchTheBank(WalletTopUp $topUp): void
    {
        $settings = TransferSetting::current();
        $now = CarbonImmutable::now();

        $topUp->forceFill([
            'poll_started_at' => $now,
            'poll_until' => $now->addMinutes((int) $settings->verify_window_minutes),
            'poll_attempts' => 0,
        ])->save();

        if (! $settings->auto_verify_enabled) {
            return;
        }

        DB::afterCommit(function () use ($topUp): void {
            // The slip first: the poll asks whether the bank's payer and
            // reference appear on it, and it retries for the whole window.
            ReadWalletTopUpReceipt::dispatch((int) $topUp->getKey());
            PollWalletTopUp::dispatch((int) $topUp->getKey());
        });
    }

    /**
     * THE crediting path — the only one. Called by the automatic verifier
     * (actor null, matched_* already written by the caller under the same
     * lock) and by the admin's manual match (actor set). Credits the wallet
     * through WalletFunding::recordTopUp, links the movement to the claim,
     * marks it matched, bumps the money cache and tells the store.
     *
     * The caller holds the row lock and has verified the claim is pending;
     * this runs inside the caller's transaction.
     *
     * @throws DuplicateBankRefException the wallet already holds a movement under this reference
     */
    public function credit(WalletTopUp $locked, string $bankRef, ?AdminUser $actor): WalletTransaction
    {
        if ($locked->state !== 'pending') {
            throw InvalidWalletTopUpStateException::notPending($locked, 'crediting');
        }

        $merchant = $locked->merchant;

        // Wallet balance moved — orphan this merchant's cached money reads
        // once this transaction commits.
        MerchantMoneyCache::bump((int) $merchant->getKey());

        $movement = $this->wallet->recordTopUp(
            $merchant,
            Laari::of((int) $locked->amount_laari),
            $bankRef,
            sprintf('Wallet top-up #%d (%s)', $locked->id, $bankRef),
        );

        $locked->forceFill([
            'state' => 'matched',
            'matched_by' => $actor?->id,
            'matched_at' => CarbonImmutable::now('UTC'),
            'wallet_transaction_id' => $movement->id,
            'poll_until' => null,
        ])->save();

        $this->notifications->sendToMerchantStaff(
            NotificationTemplateKey::WalletTopUpReceived,
            $merchant,
            [
                'amount' => NotificationService::money((int) $movement->amount_laari),
                'balance' => NotificationService::money((int) $movement->balance_after_laari),
            ],
            Permission::WalletView,
        );

        return $movement;
    }

    /**
     * The admin's manual match (the queue's fallback): the transfer was
     * found on the statement by a person. The reference the wallet movement
     * is keyed on is the merchant's own if they typed one, else the one the
     * admin read off the statement — one of the two is required, because
     * without a reference the movement has no idempotency key.
     *
     * `matched_trx_id` records what was found in the bank, so the credit is
     * spent everywhere BankCreditClaim looks — and BankCreditClaim is asked
     * FIRST, so a reference an order, a settlement payment or another
     * wallet already spent (auto-matched or by hand) is refused here rather
     * than credited a second time.
     *
     * @throws InvalidWalletTopUpStateException the claim is no longer pending
     * @throws DuplicateBankRefException the reference already credited a wallet, or another claim holds it
     */
    public function match(WalletTopUp $topUp, AdminUser $actor, ?string $bankRef): WalletTopUp
    {
        $bankRef = trim((string) $bankRef);
        $bankRef = $bankRef === '' ? null : $bankRef;

        try {
            return DB::transaction(function () use ($topUp, $actor, $bankRef): WalletTopUp {
                $locked = $this->locked($topUp);

                if ($locked->state !== 'pending') {
                    throw InvalidWalletTopUpStateException::notPending($locked, 'matching');
                }

                $reference = $locked->bank_ref ?? $bankRef;

                if ($reference === null) {
                    throw new InvalidArgumentException('A bank reference is required to match a top-up the merchant gave none for.');
                }

                $references = array_values(array_unique(array_filter([$reference, $bankRef])));

                if ($this->claims->spent($references, exceptTopUp: (int) $locked->getKey())) {
                    throw DuplicateBankRefException::forWalletTopUpSpent($locked->merchant, $reference);
                }

                $locked->forceFill([
                    'auto_matched' => false,
                    'matched_trx_id' => $bankRef ?? $reference,
                    'matched_trx_refs' => $references,
                ])->save();

                $this->credit($locked, $reference, $actor);

                return $locked->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // matched_trx_id is unique: another claim already spent this
            // credit. The index is the guarantee, this is its message.
            throw DuplicateBankRefException::forWalletTopUpClaim($topUp->merchant, $bankRef);
        }
    }

    /**
     * The reject half of the review: the transfer could not be verified.
     * No money moved and none will; the reason travels to the store, which
     * has to act on it (re-check the transfer, submit again).
     *
     * @throws InvalidWalletTopUpStateException the claim is no longer pending
     */
    public function reject(WalletTopUp $topUp, AdminUser $actor, string $reason): WalletTopUp
    {
        return DB::transaction(function () use ($topUp, $actor, $reason): WalletTopUp {
            $locked = $this->locked($topUp);

            if ($locked->state !== 'pending') {
                throw InvalidWalletTopUpStateException::notPending($locked, 'rejecting');
            }

            $locked->forceFill([
                'state' => 'rejected',
                'rejected_by' => $actor->id,
                'rejected_at' => CarbonImmutable::now('UTC'),
                'rejected_reason' => $reason,
                'poll_until' => null,
            ])->save();

            $this->notifications->sendToMerchantStaff(
                NotificationTemplateKey::WalletTopUpRejected,
                $locked->merchant,
                [
                    'amount' => NotificationService::money((int) $locked->amount_laari),
                    'reason' => $reason,
                ],
                Permission::WalletView,
            );

            return $locked;
        });
    }

    private function alreadyCredited(Merchant $merchant, string $bankRef): bool
    {
        $walletId = MerchantWallet::query()->where('merchant_id', $merchant->id)->value('id');

        if ($walletId === null) {
            return false;
        }

        return WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('bank_ref', $bankRef)
            ->exists();
    }

    /** A non-rejected settlement receipt of this merchant's already quotes the reference. */
    private function heldBySettlementPayment(Merchant $merchant, string $bankRef): bool
    {
        return SettlementPayment::query()
            ->where('merchant_id', $merchant->id)
            ->where('bank_ref', $bankRef)
            ->where('state', '!=', 'rejected')
            ->exists();
    }

    private function locked(WalletTopUp $topUp): WalletTopUp
    {
        /** @var WalletTopUp */
        return WalletTopUp::query()->whereKey($topUp->getKey())->lockForUpdate()->firstOrFail();
    }
}
