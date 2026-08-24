<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * The §8 posting catalogue's journal DESCRIPTIONS, named once.
 *
 * `ledger_journals` records what an event was in its description string —
 * there is no kind column — so a ledger-derived report has to read those
 * strings to tell an accrual from a discount. Three reports and the
 * per-line collection maths all ask that question, and a string typed out
 * four times is a string that ends up spelled three ways.
 *
 * Every case here is asserted against a real Postings call in
 * tests/Feature/Reports/JournalKindTest.php: rename a description in the
 * catalogue and that test fails loudly, rather than the earnings report
 * quietly reporting zero discounts forever.
 */
enum JournalKind: string
{
    case Accrual = 'Cashback accrued';
    case AccrualReversed = 'Cashback accrual reversed';
    case BankSettlementReceived = 'Bank settlement received';
    case WalletTopUp = 'Merchant wallet top-up';
    case WalletSettle = 'Wallet settlement applied';
    case PayoutSent = 'Customer payout sent';
    case AdjustmentCreditApplied = 'Adjustment credit applied';
    case AdjustmentCreditUnapplied = 'Adjustment credit unapplied';
    case WriteOff = 'Unsettled reward written off';
    case ShortfallForgiven = 'Settlement shortfall forgiven';
    case PromptDiscount = 'Prompt-payment fee discount';
    case PlatformFundedReward = 'Platform-funded reward granted';
}
