<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/**
 * The §8 posting catalogue — one named method per event row. Methods take the
 * integer laari amounts already stored on the source row; nothing here ever
 * recomputes from a rate. Each call posts exactly one balanced journal.
 */
final readonly class Postings
{
    public function __construct(private LedgerPoster $poster) {}

    /**
     * Cashback accrues (→ Pending): DR Merchant Receivable for the total the
     * merchant owes; CR the cashback liability, fee revenue, and fee GST
     * payable. Zero components are omitted — small eligibles legitimately
     * round a fee (or everything) to 0, and a zero-amount line would be
     * rejected by the poster.
     */
    public function accrue(
        int $cashbackLaari,
        int $feeLaari,
        int $feeGstLaari,
        string $referenceType = 'transaction',
        int $referenceId = 0,
    ): int {
        $lines = [
            $this->dr(AccountCode::MerchantReceivable, $cashbackLaari + $feeLaari + $feeGstLaari),
        ];

        if ($cashbackLaari !== 0) {
            $lines[] = $this->cr(AccountCode::CustomerCashbackLiability, $cashbackLaari);
        }

        if ($feeLaari !== 0) {
            $lines[] = $this->cr(AccountCode::PlatformFeeRevenue, $feeLaari);
        }

        if ($feeGstLaari !== 0) {
            $lines[] = $this->cr(AccountCode::FeeTaxPayable, $feeGstLaari);
        }

        return $this->poster->post($referenceType, $referenceId, 'Cashback accrued', $lines);
    }

    // Reward confirmation deliberately has no posting method: the liability and
    // revenue were already recognised at accrual, so confirmation is a state
    // change only — posting again would double-count both sides (§8).

    public function bankSettlementReceived(
        int $totalLaari,
        string $referenceType = 'settlement',
        int $referenceId = 0,
    ): int {
        return $this->poster->post($referenceType, $referenceId, 'Bank settlement received', [
            $this->dr(AccountCode::SettlementCash, $totalLaari),
            $this->cr(AccountCode::MerchantReceivable, $totalLaari),
        ]);
    }

    public function walletTopUp(
        int $amountLaari,
        string $referenceType = 'wallet_transaction',
        int $referenceId = 0,
    ): int {
        return $this->poster->post($referenceType, $referenceId, 'Merchant wallet top-up', [
            $this->dr(AccountCode::SettlementCash, $amountLaari),
            $this->cr(AccountCode::MerchantWalletBalance, $amountLaari),
        ]);
    }

    public function walletSettle(
        int $totalLaari,
        string $referenceType = 'settlement',
        int $referenceId = 0,
    ): int {
        return $this->poster->post($referenceType, $referenceId, 'Wallet settlement applied', [
            $this->dr(AccountCode::MerchantWalletBalance, $totalLaari),
            $this->cr(AccountCode::MerchantReceivable, $totalLaari),
        ]);
    }

    public function payoutSent(
        int $cashbackLaari,
        string $referenceType = 'payout_item',
        int $referenceId = 0,
    ): int {
        return $this->poster->post($referenceType, $referenceId, 'Customer payout sent', [
            $this->dr(AccountCode::CustomerCashbackLiability, $cashbackLaari),
            $this->cr(AccountCode::SettlementCash, $cashbackLaari),
        ]);
    }

    /**
     * Pre-confirmation reversal: the exact mirror of accrue() with debit and
     * credit sides swapped. Callers pass the integers stored on the transaction
     * row — never recompute from a rate that may have changed since accrual.
     */
    public function reverseAccrual(
        int $cashbackLaari,
        int $feeLaari,
        int $feeGstLaari,
        string $referenceType = 'transaction',
        int $referenceId = 0,
    ): int {
        $lines = [];

        if ($cashbackLaari !== 0) {
            $lines[] = $this->dr(AccountCode::CustomerCashbackLiability, $cashbackLaari);
        }

        if ($feeLaari !== 0) {
            $lines[] = $this->dr(AccountCode::PlatformFeeRevenue, $feeLaari);
        }

        if ($feeGstLaari !== 0) {
            $lines[] = $this->dr(AccountCode::FeeTaxPayable, $feeGstLaari);
        }

        $lines[] = $this->cr(AccountCode::MerchantReceivable, $cashbackLaari + $feeLaari + $feeGstLaari);

        return $this->poster->post($referenceType, $referenceId, 'Cashback accrual reversed', $lines);
    }

    /**
     * Write-off at 90 days past due: the platform's own margin (fee + GST)
     * becomes bad debt, the customer liability is released, and the whole
     * receivable is cleared. GST reversal treatment is an open accountant
     * question (§14) — until resolved the GST share sits in bad debt.
     */
    public function writeOff(
        int $cashbackLaari,
        int $feeLaari,
        int $feeGstLaari,
        string $referenceType = 'transaction',
        int $referenceId = 0,
    ): int {
        $lines = [];

        if ($feeLaari + $feeGstLaari !== 0) {
            $lines[] = $this->dr(AccountCode::BadDebtExpense, $feeLaari + $feeGstLaari);
        }

        if ($cashbackLaari !== 0) {
            $lines[] = $this->dr(AccountCode::CustomerCashbackLiability, $cashbackLaari);
        }

        $lines[] = $this->cr(AccountCode::MerchantReceivable, $cashbackLaari + $feeLaari + $feeGstLaari);

        return $this->poster->post($referenceType, $referenceId, 'Unsettled reward written off', $lines);
    }

    /**
     * Referral and other platform-funded rewards bypass the merchant
     * receivable entirely.
     */
    public function platformFundedReward(
        int $amountLaari,
        string $referenceType = 'transaction',
        int $referenceId = 0,
    ): int {
        return $this->poster->post($referenceType, $referenceId, 'Platform-funded reward granted', [
            $this->dr(AccountCode::PlatformFundedRewards, $amountLaari),
            $this->cr(AccountCode::CustomerCashbackLiability, $amountLaari),
        ]);
    }

    /**
     * @return array{account: AccountCode, debit_laari: int, credit_laari: int}
     */
    private function dr(AccountCode $account, int $laari): array
    {
        return ['account' => $account, 'debit_laari' => $laari, 'credit_laari' => 0];
    }

    /**
     * @return array{account: AccountCode, debit_laari: int, credit_laari: int}
     */
    private function cr(AccountCode $account, int $laari): array
    {
        return ['account' => $account, 'debit_laari' => 0, 'credit_laari' => $laari];
    }
}
