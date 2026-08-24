<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Cashback\Actor;
use App\Domain\MerchantAccess\Permission;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Models\Merchant;
use App\Models\MerchantWallet;
use App\Models\Settlement;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Auto-settlement from the wallet (owner, 2026-08-24 — the decision that
 * reversed PLAN §1 "wallet is not pre-funding"). Once an hour, right after
 * the validation sweep has moved the day's sales onto the settlement clock,
 * every willing merchant whose wallet holds balance has that balance spent
 * on their validated cashback: OLDEST FIRST, as far as it reaches, one
 * settlement per merchant per run.
 *
 * Doctrine (PLAN §7, restated at PLAN.md:297): this is NOT a second
 * settlement path. The run decides WHICH lines and then hands them to
 * SettlementBuilder::createAndSettleFromWallet — the same call the merchant's
 * own "settle from wallet" button makes — with Actor::system() as the
 * signature. Draft, freeze, prompt discount, wallet debit, ledger postings,
 * line allocation and the money-cache bump all happen in there, exactly
 * once, exactly as they do by hand.
 *
 * WHAT FITS, oldest first. The run walks the merchant's eligible
 * payable_unfunded lines in due_at order and takes the longest PREFIX whose
 * UNDISCOUNTED sum (cashback + fee + fee GST, the stored §4 integers) the
 * balance covers. A prefix, never a skip: a newer line is never settled
 * ahead of an older one the wallet could not afford — the merchant's oldest
 * debt is what the escalation ladder and day-16 suspension measure, so it
 * is what the money goes to. Whatever does not fit stays payable_unfunded
 * for the merchant to top up against or pay by bank, and the next hour
 * looks again.
 *
 * The undiscounted sum is the affordability test on purpose. submit() can
 * only make the batch CHEAPER — §7 credits net in, the PLAN §1 prompt
 * discount comes off — so a batch that fits undiscounted always settles,
 * and any relief simply stays in the wallet. Planning against a discounted
 * figure would mean guessing at submit's answer outside the lock it is
 * decided under, and the whole point of the discount design is that only
 * submit's answer moves money.
 *
 * Idempotent across concurrent runs by the same means the manual path is:
 * the plan reads without locks, and the builder then claims the chosen
 * rows FOR UPDATE behind the unique settlement_lines index and debits the
 * wallet under its row lock. A row another batch took first surfaces as
 * NotEligibleForSettlementException; a balance that moved surfaces as
 * InsufficientWalletBalanceException. Both roll the merchant's whole
 * attempt back and are logged — and neither stops the run: one shop's
 * problem is one shop's problem.
 */
final class WalletAutoSettler
{
    /**
     * The three statuses before approval, mirroring EnsureMerchantApproved's
     * default gate on the merchant's own wallet-settle route: a store that
     * may not press the button may not have it pressed for them. Suspended
     * and closed stores are deliberately IN — suspension is credit control
     * for unpaid cashback, and a wallet that can pay it down should; a
     * closed store's customers are still owed their confirmation.
     */
    private const array PRE_APPROVAL_STATUSES = ['draft', 'pending_review', 'rejected'];

    private const int CHUNK = 100;

    public function __construct(
        private readonly SettlementBuilder $builder,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * One pass over every candidate merchant.
     *
     * @return array{checked: int, settled: int, skipped: int}
     */
    public function run(): array
    {
        $checked = 0;
        $settled = 0;
        $skipped = 0;

        $this->candidates()->chunkById(self::CHUNK, function ($merchants) use (&$checked, &$settled, &$skipped): void {
            foreach ($merchants as $merchant) {
                $checked++;

                try {
                    $settled += $this->settle($merchant) === null ? 0 : 1;
                } catch (InsufficientWalletBalanceException|NotEligibleForSettlementException|InvalidSettlementStateException $exception) {
                    // A concurrent movement got there first — a merchant
                    // settling by hand in the same minute, a top-up that
                    // was reversed, a batch claimed elsewhere. The attempt
                    // rolled back whole; the next hour reads the world
                    // afresh.
                    $skipped++;

                    Log::info('Wallet auto-settlement skipped', [
                        'merchant_id' => $merchant->id,
                        'reason' => $exception->getMessage(),
                    ]);
                } catch (Throwable $exception) {
                    // Anything else is a bug or an outage, reported as such
                    // — but the run carries on to the next store.
                    $skipped++;

                    report($exception);

                    Log::error('Wallet auto-settlement failed', [
                        'merchant_id' => $merchant->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        });

        return ['checked' => $checked, 'settled' => $settled, 'skipped' => $skipped];
    }

    /**
     * Merchants worth a look: opted in, past approval, holding balance, and
     * owing something a settlement could pick up. The two EXISTS clauses are
     * a cheap pre-filter, not the decision — settle() re-reads both.
     *
     * @return Builder<Merchant>
     */
    public function candidates(): Builder
    {
        return Merchant::query()
            ->where('auto_settle_from_wallet', true)
            ->whereNotIn('status', self::PRE_APPROVAL_STATUSES)
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('merchant_wallets')
                    ->whereColumn('merchant_wallets.merchant_id', 'merchants.id')
                    ->where('merchant_wallets.balance_laari', '>', 0);
            })
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('transactions')
                    ->whereColumn('transactions.merchant_id', 'merchants.id')
                    ->where('transactions.state', 'payable_unfunded')
                    ->where('transactions.origin', '!=', 'marketplace');
            })
            ->orderBy('id');
    }

    /**
     * Settle what this merchant's balance covers, oldest first, in ONE
     * database transaction. Returns null when there was nothing to do —
     * the store opted out or is not past approval, no balance, or not even
     * the oldest line fits.
     *
     * @throws InsufficientWalletBalanceException the balance moved under us
     * @throws NotEligibleForSettlementException a chosen row was claimed elsewhere
     * @throws InvalidSettlementStateException the batch was not where the wallet path expects
     */
    public function settle(Merchant $merchant): ?Settlement
    {
        return DB::transaction(function () use ($merchant): ?Settlement {
            // The merchant's answer as it stands NOW, not as the pre-filter
            // read it: a store that flipped the switch off a second ago
            // must not have its balance spent.
            if (! $this->willing($merchant)) {
                return null;
            }

            $balance = $this->balance($merchant);

            if ($balance <= 0) {
                return null;
            }

            $transactionIds = $this->plan($merchant, $balance);

            if ($transactionIds === []) {
                return null;
            }

            // THE settlement path — nothing about how a batch is built,
            // discounted, debited, posted or allocated lives in this class.
            $settlement = $this->builder->createAndSettleFromWallet($merchant, Actor::system(), $transactionIds);

            // A prefix fully netted by pending §7 credits settles inside
            // submit() at zero due and never touches the wallet
            // (funding_method stays 'bank', nothing received). It is
            // settled — the lines confirmed — but "MVR 0.00 was settled
            // from your wallet" is not a message anyone should get.
            if ($settlement->funding_method === 'wallet') {
                $this->announce($merchant, $settlement, count($transactionIds));
            } else {
                Log::info('Wallet auto-settlement netted by credits, wallet untouched', [
                    'merchant_id' => $merchant->id,
                    'settlement_id' => $settlement->id,
                ]);
            }

            return $settlement;
        });
    }

    /**
     * The longest oldest-first prefix of the merchant's eligible lines whose
     * undiscounted due the balance covers. Reads without locks — the builder
     * takes them, in its own order, when it claims these ids.
     *
     * @return list<int>
     */
    public function plan(Merchant $merchant, int $balance): array
    {
        $ids = [];
        $cumulative = 0;

        $lines = $this->builder->eligibleTransactions($merchant)
            ->orderBy('due_at')
            ->orderBy('id')
            ->cursor();

        /** @var Transaction $line */
        foreach ($lines as $line) {
            $due = (int) $line->cashback_laari + (int) $line->fee_laari + (int) $line->fee_gst_laari;

            if ($cumulative + $due > $balance) {
                break;
            }

            $cumulative += $due;
            $ids[] = (int) $line->id;
        }

        return $ids;
    }

    /**
     * One message per run, never one per line (the template says so too):
     * what the run drew, how many sales it covered, what is left. Runs after
     * commit through sendToMerchantStaff, so a rolled-back attempt says
     * nothing.
     */
    private function announce(Merchant $merchant, Settlement $settlement, int $count): void
    {
        $this->notifications->sendToMerchantStaff(
            NotificationTemplateKey::WalletAutoSettled,
            $merchant,
            [
                'amount' => NotificationService::money((int) $settlement->amount_received_laari),
                'count' => (string) $count,
                'balance' => NotificationService::money($this->balance($merchant)),
            ],
            Permission::SettlementsView,
        );
    }

    /**
     * Opted in and past approval, read fresh from the row — the same two
     * tests candidates() pre-filters on, re-asked at the moment of spending.
     */
    private function willing(Merchant $merchant): bool
    {
        $row = Merchant::query()
            ->whereKey($merchant->getKey())
            ->first(['auto_settle_from_wallet', 'status']);

        return $row !== null
            && (bool) $row->auto_settle_from_wallet
            && ! in_array($row->status, self::PRE_APPROVAL_STATUSES, true);
    }

    private function balance(Merchant $merchant): int
    {
        return (int) MerchantWallet::query()
            ->where('merchant_id', $merchant->id)
            ->value('balance_laari');
    }
}
