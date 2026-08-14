<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/**
 * Chart of accounts (§8). Codes are stable identifiers persisted in
 * ledger_accounts.code — never renumber a case.
 */
enum AccountCode: string
{
    case SettlementCash = '1000';
    case MerchantReceivable = '1100';
    case CustomerCashbackLiability = '2100';
    case MerchantWalletBalance = '2200';
    case FeeTaxPayable = '2300';
    case PlatformFeeRevenue = '4100';
    case PlatformFundedRewards = '5100';
    case BadDebtExpense = '5900';

    public function type(): string
    {
        return match ($this) {
            self::SettlementCash, self::MerchantReceivable => 'asset',
            self::CustomerCashbackLiability, self::MerchantWalletBalance, self::FeeTaxPayable => 'liability',
            self::PlatformFeeRevenue => 'income',
            self::PlatformFundedRewards, self::BadDebtExpense => 'expense',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SettlementCash => 'Settlement Cash',
            self::MerchantReceivable => 'Merchant Receivable',
            self::CustomerCashbackLiability => 'Customer Cashback Liability',
            self::MerchantWalletBalance => 'Merchant Wallet Balance',
            self::FeeTaxPayable => 'Fee GST Payable',
            self::PlatformFeeRevenue => 'Platform Fee Revenue',
            self::PlatformFundedRewards => 'Platform-Funded Rewards Expense',
            self::BadDebtExpense => 'Bad Debt Expense',
        };
    }

    /**
     * Asset and expense accounts carry a debit-normal balance; liability and
     * income accounts a credit-normal one.
     */
    public function isDebitNormal(): bool
    {
        return in_array($this->type(), ['asset', 'expense'], true);
    }
}
