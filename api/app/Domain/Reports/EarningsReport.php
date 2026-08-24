<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Ledger\AccountCode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REPORT C — what the platform actually earned, traced through the LEDGER.
 *
 * Every figure here is a sum of `ledger_entries` rows, grouped by account
 * and by the journal description the §8 catalogue posted them under. That
 * is the whole design: the nightly Reconciler compares derived totals
 * against the same ledger, so a report built from `transactions` and
 * `settlements` could disagree with the reconciliation and both could be
 * defensible. Built from the ledger, it cannot — if this report is wrong,
 * the reconciliation is failing too, and somebody is already being told.
 *
 * The period is the journal's `posted_at`.
 *
 * WHAT THE MONEY IS, and what it is not:
 *
 *   - Fee revenue (4100) is ours. Prompt-payment discounts are a sales
 *     discount on it, and a forgiven settlement shortfall is money we chose
 *     not to collect; both reduce it, so they are shown NEGATIVE and the
 *     section adds up to net fee income.
 *   - GST (2300) is NOT income. It is collected on the platform's fee and
 *     owed to MIRA — it sits in the summary so the number is visible, never
 *     inside the earnings arithmetic.
 *   - Referral rewards and adjustment-funded cashback (5100) and bad debt
 *     (5900) are expenses, and come off the bottom line.
 *
 * ACCRUED vs COLLECTED. Fees are recognised when a sale accrues and turn
 * into cash when the merchant's settlement allocates — usually a different
 * month. The second half of the summary shows both: accrued from the
 * accrual journals, collected from the fee component of the lines allocated
 * in the period, split by how the batch was funded (bank transfer or
 * merchant wallet).
 */
final class EarningsReport extends BaseReport
{
    public const string SUMMARY = 'Summary';

    public const string BY_ACCOUNT = 'By account';

    public const string BY_MERCHANT = 'By merchant';

    public const string POSTINGS = 'Postings';

    /** @var array<string, array<string, array{debit: int, credit: int}>>|null */
    private ?array $totals = null;

    public function key(): string
    {
        return 'earnings';
    }

    public function primarySheetTitle(): string
    {
        return self::POSTINGS;
    }

    protected function countRows(): int
    {
        return (int) $this->entryScope()->count();
    }

    /**
     * @return list<Sheet>
     */
    protected function build(): array
    {
        return [
            $this->summarySheet(),
            $this->byAccountSheet(),
            $this->byMerchantSheet(),
            $this->postingsSheet(),
        ];
    }

    /** Journals posted inside the period, narrowed to one merchant if asked. */
    private function journalScope(): Builder
    {
        return DB::table('ledger_journals')
            ->where('ledger_journals.posted_at', '>=', $this->period->start)
            ->where('ledger_journals.posted_at', '<', $this->period->end)
            ->when($this->merchantId !== null, fn ($query) => $query->where(function ($outer): void {
                // A journal belongs to a merchant through whatever it
                // references. `payout_item` journals belong to a CUSTOMER —
                // the money leaving for a bank transfer — and have no
                // merchant at all, so a filtered report leaves them out
                // rather than guessing.
                $outer
                    ->orWhere(fn ($q) => $q->where('reference_type', 'transaction')->whereIn(
                        'reference_id',
                        DB::table('transactions')->where('merchant_id', $this->merchantId)->select('id'),
                    ))
                    ->orWhere(fn ($q) => $q->where('reference_type', 'settlement')->whereIn(
                        'reference_id',
                        DB::table('settlements')->where('merchant_id', $this->merchantId)->select('id'),
                    ))
                    ->orWhere(fn ($q) => $q->where('reference_type', 'adjustment')->whereIn(
                        'reference_id',
                        DB::table('adjustments')
                            ->join('transactions', 'transactions.id', '=', 'adjustments.transaction_id')
                            ->where('transactions.merchant_id', $this->merchantId)
                            ->select('adjustments.id'),
                    ))
                    ->orWhere(fn ($q) => $q->where('reference_type', 'wallet_transaction')->whereIn(
                        'reference_id',
                        DB::table('wallet_transactions')
                            ->join('merchant_wallets', 'merchant_wallets.id', '=', 'wallet_transactions.wallet_id')
                            ->where('merchant_wallets.merchant_id', $this->merchantId)
                            ->select('wallet_transactions.id'),
                    ));
            }));
    }

    /** Every ledger line inside the period — the Postings sheet's rows. */
    private function entryScope(): Builder
    {
        return DB::table('ledger_entries')
            ->joinSub($this->journalScope()->select('ledger_journals.*'), 'journal', 'journal.id', '=', 'ledger_entries.journal_id');
    }

    /**
     * One grouped pass over the period: account code × journal description.
     * Everything the summary says is read out of this, so no two figures can
     * come from two different sets of rows.
     *
     * @return array<string, array<string, array{debit: int, credit: int}>>
     */
    private function totals(): array
    {
        if ($this->totals !== null) {
            return $this->totals;
        }

        $rows = $this->entryScope()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->groupBy('ledger_accounts.code', 'journal.description')
            ->select('ledger_accounts.code', 'journal.description')
            ->selectRaw('SUM(ledger_entries.debit_laari) AS debit_laari, SUM(ledger_entries.credit_laari) AS credit_laari')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row->code][(string) $row->description] = [
                'debit' => (int) $row->debit_laari,
                'credit' => (int) $row->credit_laari,
            ];
        }

        return $this->totals = $totals;
    }

    /**
     * Net movement on one account, optionally restricted to (or excluding)
     * one journal kind.
     */
    private function net(AccountCode $account, ?JournalKind $only = null, ?JournalKind $except = null, bool $creditNormal = true): int
    {
        $net = 0;

        foreach ($this->totals()[$account->value] ?? [] as $description => $amounts) {
            if ($only !== null && $description !== $only->value) {
                continue;
            }

            if ($except !== null && $description === $except->value) {
                continue;
            }

            $net += $creditNormal
                ? $amounts['credit'] - $amounts['debit']
                : $amounts['debit'] - $amounts['credit'];
        }

        return $net;
    }

    private function side(AccountCode $account, JournalKind $kind, string $side): int
    {
        return $this->totals()[$account->value][$kind->value][$side] ?? 0;
    }

    private function summarySheet(): Sheet
    {
        $sheet = new Sheet(self::SUMMARY, [
            ReportColumn::text('metric', 'Metric'),
            ReportColumn::money('amount_laari', 'Amount'),
        ]);

        // Fee revenue as the ledger holds it, LESS the discount journals —
        // they are counted on their own line so the discount is visible
        // rather than netted away silently.
        $feeRevenue = $this->net(AccountCode::PlatformFeeRevenue, except: JournalKind::PromptDiscount);
        $discounts = $this->side(AccountCode::PlatformFeeRevenue, JournalKind::PromptDiscount, 'debit');
        $forgiveness = $this->side(AccountCode::PlatformFundedRewards, JournalKind::ShortfallForgiven, 'debit');
        $netFeeIncome = $feeRevenue - $discounts - $forgiveness;

        $gst = $this->net(AccountCode::FeeTaxPayable);
        $rewards = $this->net(AccountCode::PlatformFundedRewards, except: JournalKind::ShortfallForgiven, creditNormal: false);
        $badDebt = $this->net(AccountCode::BadDebtExpense, creditNormal: false);

        $sheet->push(['Fee revenue (4100)', $feeRevenue]);
        $sheet->push(['Prompt-payment discounts (4100)', -$discounts]);
        $sheet->push(['Shortfall forgiveness (5100)', -$forgiveness]);
        $sheet->push(['Net fee income', $netFeeIncome]);
        $sheet->push(['GST collected (2300) — payable to MIRA, not income', $gst]);
        $sheet->push(['Referral and platform-funded rewards (5100)', -$rewards]);
        $sheet->push(['Bad debt (5900)', -$badDebt]);
        $sheet->push(['Net platform earnings', $netFeeIncome - $rewards - $badDebt]);

        [$bank, $wallet] = $this->collectedFees();
        $accrued = $this->side(AccountCode::PlatformFeeRevenue, JournalKind::Accrual, 'credit');

        $sheet->push(['Accrued vs collected', null]);
        $sheet->push(['Fees accrued on transactions in the period', $accrued]);
        $sheet->push(['Fees collected — bank settlements', $bank]);
        $sheet->push(['Fees collected — merchant wallet', $wallet]);
        $sheet->push(['Fees collected — total', $bank + $wallet]);

        return $sheet;
    }

    /**
     * The fee that actually turned into MONEY in the period, split by how
     * the batch was funded: the fee component of the settlement lines
     * allocated in the window, LESS the prompt-payment discount posted on
     * those same batches.
     *
     * The subtraction is the whole point of the block. A discounted batch's
     * merchant transfers `amount_due = gross − discount`, and PLAN §1
     * defines the discount as a reduction of the FEE — so the raw
     * `settlement_lines.fee_laari` counts laari nobody sent. Left in, a
     * month where relief was given reads accrued == collected, i.e. "every
     * laari of fee arrived", while 4100 in the same workbook shows it being
     * discounted away. The discount journals are posted at the allocation
     * instant, so they belong to the same window by construction.
     *
     * A forgiven §7 shortfall is deliberately NOT deducted here: it is
     * capped under MVR 1, it is absorbed against the batch as a whole
     * rather than against the fee, and the summary already carries it on
     * its own line as an expense.
     *
     * @return array{0: int, 1: int} [bank, wallet]
     */
    private function collectedFees(): array
    {
        $rows = DB::table('settlement_lines')
            ->join('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
            ->whereNotNull('settlement_lines.allocated_at')
            ->where('settlement_lines.allocated_at', '>=', $this->period->start)
            ->where('settlement_lines.allocated_at', '<', $this->period->end)
            ->when($this->merchantId !== null, fn ($query) => $query->where('settlements.merchant_id', $this->merchantId))
            ->groupBy('settlements.funding_method')
            ->select('settlements.funding_method')
            ->selectRaw('SUM(settlement_lines.fee_laari) AS fee_laari')
            ->get();

        $bank = 0;
        $wallet = 0;

        foreach ($rows as $row) {
            if ($row->funding_method === 'wallet') {
                $wallet += (int) $row->fee_laari;
            } else {
                $bank += (int) $row->fee_laari;
            }
        }

        foreach ($this->discountsByFunding() as $method => $laari) {
            if ($method === 'wallet') {
                $wallet -= $laari;
            } else {
                $bank -= $laari;
            }
        }

        return [$bank, $wallet];
    }

    /**
     * The 4100 leg of the prompt-discount journals posted in the period,
     * grouped by how the batch they belong to was funded.
     *
     * @return array<string, int> funding method => laari
     */
    private function discountsByFunding(): array
    {
        $rows = $this->entryScope()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->join('settlements', function ($join): void {
                $join->on('settlements.id', '=', 'journal.reference_id')
                    ->where('journal.reference_type', '=', 'settlement');
            })
            ->where('journal.description', JournalKind::PromptDiscount->value)
            ->where('ledger_accounts.code', AccountCode::PlatformFeeRevenue->value)
            ->groupBy('settlements.funding_method')
            ->select('settlements.funding_method')
            ->selectRaw('SUM(ledger_entries.debit_laari - ledger_entries.credit_laari) AS laari')
            ->get();

        $byMethod = [];

        foreach ($rows as $row) {
            $byMethod[(string) $row->funding_method] = (int) $row->laari;
        }

        return $byMethod;
    }

    private function byAccountSheet(): Sheet
    {
        $sheet = new Sheet(
            self::BY_ACCOUNT,
            [
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('name', 'Account'),
                ReportColumn::text('type', 'Type'),
                ReportColumn::money('debit_laari', 'Debit'),
                ReportColumn::money('credit_laari', 'Credit'),
                ReportColumn::money('net_laari', 'Net (Dr − Cr)'),
            ],
            totals: ['debit_laari', 'credit_laari', 'net_laari'],
        );

        $rows = $this->entryScope()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->groupBy('ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type')
            ->orderBy('ledger_accounts.code')
            ->select('ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type')
            ->selectRaw('SUM(ledger_entries.debit_laari) AS debit_laari, SUM(ledger_entries.credit_laari) AS credit_laari')
            ->get();

        foreach ($rows as $row) {
            $sheet->push([
                (string) $row->code,
                (string) $row->name,
                (string) $row->type,
                (int) $row->debit_laari,
                (int) $row->credit_laari,
                (int) $row->debit_laari - (int) $row->credit_laari,
            ]);
        }

        return $sheet;
    }

    /**
     * The same money, per merchant, joined through what each journal
     * references: accruals through their transaction, discounts through
     * their settlement, and collection through the lines that allocated.
     */
    private function byMerchantSheet(): Sheet
    {
        $sheet = new Sheet(
            self::BY_MERCHANT,
            [
                ReportColumn::text('merchant', 'Merchant'),
                ReportColumn::money('fees_accrued_laari', 'Fees accrued'),
                ReportColumn::money('discounts_laari', 'Discounts'),
                ReportColumn::money('gst_laari', 'GST'),
                ReportColumn::money('collected_laari', 'Fees collected'),
            ],
            totals: ['fees_accrued_laari', 'discounts_laari', 'gst_laari', 'collected_laari'],
        );

        $merchants = [];

        $bucket = function (int $id, ?string $name) use (&$merchants): void {
            $merchants[$id] ??= [
                'name' => $name ?? '',
                'fees' => 0,
                'discounts' => 0,
                'gst' => 0,
                'collected' => 0,
            ];
        };

        // Accruals and their GST: journal → transaction → merchant. A
        // journal reaches its merchant two ways and BOTH are counted, or
        // this sheet's total stops matching the Summary sheet beside it:
        //
        //   reference_type = 'transaction'  the accrual itself, and the
        //                                   §6 reversal that mirrors it.
        //   reference_type = 'adjustment'   the §7 credit memo, which is
        //                                   the ordinary outcome of
        //                                   refunding an already-confirmed
        //                                   sale. Postings::applyAdjustment-
        //                                   Credit debits 4100 and 2300
        //                                   under the ADJUSTMENT, so a
        //                                   query that joined transactions
        //                                   alone saw the Summary lose the
        //                                   fee and no merchant give it up.
        //
        // Resolved through `adjustments.transaction_id` — the same hop
        // journalScope() already makes for the merchant filter.
        $accruals = $this->entryScope()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->leftJoin('adjustments', function ($join): void {
                $join->on('adjustments.id', '=', 'journal.reference_id')
                    ->where('journal.reference_type', '=', 'adjustment');
            })
            ->join('transactions', function ($join): void {
                $join->whereRaw(
                    "transactions.id = case when journal.reference_type = 'transaction' "
                    .'then journal.reference_id else adjustments.transaction_id end'
                );
            })
            ->whereIn('journal.reference_type', ['transaction', 'adjustment'])
            ->whereIn('ledger_accounts.code', [AccountCode::PlatformFeeRevenue->value, AccountCode::FeeTaxPayable->value])
            ->leftJoin('merchants', 'merchants.id', '=', 'transactions.merchant_id')
            ->groupBy('transactions.merchant_id', 'merchants.name', 'ledger_accounts.code')
            ->select('transactions.merchant_id', 'merchants.name', 'ledger_accounts.code')
            ->selectRaw('SUM(ledger_entries.credit_laari - ledger_entries.debit_laari) AS net_laari')
            ->get();

        foreach ($accruals as $row) {
            $bucket((int) $row->merchant_id, $row->name);

            if ((string) $row->code === AccountCode::PlatformFeeRevenue->value) {
                $merchants[(int) $row->merchant_id]['fees'] += (int) $row->net_laari;
            } else {
                $merchants[(int) $row->merchant_id]['gst'] += (int) $row->net_laari;
            }
        }

        // Prompt-payment discounts: journal → settlement → merchant.
        $discounts = $this->entryScope()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->join('settlements', function ($join): void {
                $join->on('settlements.id', '=', 'journal.reference_id')
                    ->where('journal.reference_type', '=', 'settlement');
            })
            ->leftJoin('merchants', 'merchants.id', '=', 'settlements.merchant_id')
            ->where('journal.description', JournalKind::PromptDiscount->value)
            ->where('ledger_accounts.code', AccountCode::PlatformFeeRevenue->value)
            ->groupBy('settlements.merchant_id', 'merchants.name')
            ->select('settlements.merchant_id', 'merchants.name')
            ->selectRaw('SUM(ledger_entries.debit_laari) AS laari')
            ->get();

        foreach ($discounts as $row) {
            $bucket((int) $row->merchant_id, $row->name);
            $merchants[(int) $row->merchant_id]['discounts'] += (int) $row->laari;
        }

        // Collection: the fee component of the lines allocated in the period.
        $collected = DB::table('settlement_lines')
            ->join('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
            ->leftJoin('merchants', 'merchants.id', '=', 'settlements.merchant_id')
            ->whereNotNull('settlement_lines.allocated_at')
            ->where('settlement_lines.allocated_at', '>=', $this->period->start)
            ->where('settlement_lines.allocated_at', '<', $this->period->end)
            ->when($this->merchantId !== null, fn ($query) => $query->where('settlements.merchant_id', $this->merchantId))
            ->groupBy('settlements.merchant_id', 'merchants.name')
            ->select('settlements.merchant_id', 'merchants.name')
            ->selectRaw('SUM(settlement_lines.fee_laari) AS laari')
            ->get();

        foreach ($collected as $row) {
            $bucket((int) $row->merchant_id, $row->name);
            $merchants[(int) $row->merchant_id]['collected'] += (int) $row->laari;
        }

        // Net of the relief posted on those batches, exactly as the summary's
        // "Fees collected" does — so this column's totals row and that figure
        // are the same number arrived at the same way.
        foreach ($merchants as $id => $merchant) {
            $merchants[$id]['collected'] -= $merchant['discounts'];
        }

        uasort($merchants, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        foreach ($merchants as $merchant) {
            $sheet->push([
                $merchant['name'],
                $merchant['fees'],
                $merchant['discounts'],
                $merchant['gst'],
                $merchant['collected'],
            ]);
        }

        return $sheet;
    }

    private function postingsSheet(): Sheet
    {
        $sheet = new Sheet(
            self::POSTINGS,
            [
                ReportColumn::date('posted_at', 'Posted at'),
                // IDENTIFIERS, not quantities. As `int` the panel rendered
                // them through a grouped Intl.NumberFormat while the
                // workbook wrote them bare, so past 999 the screen said
                // "1,234" and the cell said "1234" for the same fact — and
                // a grouped id cannot be pasted into a lookup. Counts
                // elsewhere (line_count, item_count) stay genuine ints.
                ReportColumn::text('journal_id', 'Journal'),
                ReportColumn::text('reference_type', 'Reference type'),
                ReportColumn::text('reference_id', 'Reference id'),
                ReportColumn::text('account_code', 'Code'),
                ReportColumn::text('account_name', 'Account'),
                ReportColumn::money('debit_laari', 'Debit'),
                ReportColumn::money('credit_laari', 'Credit'),
                ReportColumn::text('memo', 'Memo'),
            ],
            totals: ['debit_laari', 'credit_laari'],
        );

        $rows = $this->entryScope()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->select([
                'journal.posted_at',
                'journal.id as journal_id',
                'journal.reference_type',
                'journal.reference_id',
                'journal.description',
                'ledger_accounts.code',
                'ledger_accounts.name',
                'ledger_entries.debit_laari',
                'ledger_entries.credit_laari',
            ])
            ->orderBy('journal.posted_at')
            ->orderBy('journal.id')
            ->orderBy('ledger_entries.id')
            ->lazy(2000);

        foreach ($rows as $row) {
            $sheet->push([
                $this->at($row->posted_at),
                (string) $row->journal_id,
                ReportLabels::referenceType($row->reference_type),
                (string) $row->reference_id,
                (string) $row->code,
                (string) $row->name,
                (int) $row->debit_laari,
                (int) $row->credit_laari,
                (string) $row->description,
            ]);
        }

        return $sheet;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $feeRevenue = $this->net(AccountCode::PlatformFeeRevenue, except: JournalKind::PromptDiscount);
        $discounts = $this->side(AccountCode::PlatformFeeRevenue, JournalKind::PromptDiscount, 'debit');
        $forgiveness = $this->side(AccountCode::PlatformFundedRewards, JournalKind::ShortfallForgiven, 'debit');
        $netFeeIncome = $feeRevenue - $discounts - $forgiveness;
        $rewards = $this->net(AccountCode::PlatformFundedRewards, except: JournalKind::ShortfallForgiven, creditNormal: false);
        $badDebt = $this->net(AccountCode::BadDebtExpense, creditNormal: false);

        [$bank, $wallet] = $this->collectedFees();

        return [
            'fee_revenue_laari' => $feeRevenue,
            'prompt_discounts_laari' => $discounts,
            'shortfall_forgiveness_laari' => $forgiveness,
            'net_fee_income_laari' => $netFeeIncome,
            'gst_collected_laari' => $this->net(AccountCode::FeeTaxPayable),
            'platform_funded_rewards_laari' => $rewards,
            'bad_debt_laari' => $badDebt,
            'net_platform_earnings_laari' => $netFeeIncome - $rewards - $badDebt,
            'accrued_vs_collected' => [
                'fees_accrued_laari' => $this->side(AccountCode::PlatformFeeRevenue, JournalKind::Accrual, 'credit'),
                'fees_collected_bank_laari' => $bank,
                'fees_collected_wallet_laari' => $wallet,
                'fees_collected_laari' => $bank + $wallet,
            ],
            'postings' => [
                'count' => $this->sheet(self::POSTINGS)->count(),
                'debit_laari' => $this->sheet(self::POSTINGS)->sum('debit_laari'),
                'credit_laari' => $this->sheet(self::POSTINGS)->sum('credit_laari'),
            ],
        ];
    }
}
