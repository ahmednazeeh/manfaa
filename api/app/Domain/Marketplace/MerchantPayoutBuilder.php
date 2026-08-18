<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantPayoutBatch;
use App\Models\MerchantPayoutItem;
use App\Models\Suborder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Building a run of what we owe shops (PLAN-marketplace.md §5.5).
 *
 * WHEN a suborder becomes payable is the one judgement here: on delivery
 * plus the store's own validation window. That is the same clock the
 * customer's cashback confirms on, so a return handled inside the window
 * settles before anybody is paid for it — and it is a window the merchant
 * already understands, rather than a second one invented for the
 * marketplace.
 *
 * A suborder with no `payout_item_id` is unpaid; one with a link is claimed.
 * That single column is what stops an order being paid for twice.
 */
final readonly class MerchantPayoutBuilder
{
    public function build(AdminUser $creator, ?CarbonImmutable $cutoff = null): MerchantPayoutBatch
    {
        $cutoff ??= CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($creator, $cutoff): MerchantPayoutBatch {
            $batch = MerchantPayoutBatch::query()->create([
                'reference' => $this->nextReference(),
                'cutoff_at' => $cutoff,
                'state' => 'draft',
                'created_by' => $creator->getKey(),
            ]);

            $rows = $this->payableSuborders($cutoff)->get()->groupBy('merchant_id');

            $total = 0;
            $count = 0;
            $excludedLaari = 0;
            $excludedCount = 0;

            foreach ($rows as $merchantId => $suborders) {
                $merchant = Merchant::query()->find($merchantId);
                $amount = (int) $suborders->sum('payable_to_merchant_laari');

                if ($merchant === null || $amount <= 0) {
                    continue;
                }

                // A transfer needs somewhere to go. Money waiting on bank
                // details is surfaced on the batch rather than silently
                // missing from it — the orders stay unlinked and carry into
                // the next run.
                if (trim((string) $merchant->bank_account) === '') {
                    $excludedLaari += $amount;
                    $excludedCount++;

                    continue;
                }

                $item = MerchantPayoutItem::query()->create([
                    'batch_id' => $batch->id,
                    'merchant_id' => $merchant->id,
                    'amount_laari' => $amount,
                    'currency' => 'MVR',
                    // Snapshotted, like the customer sheet: a later edit must
                    // not redirect a transfer the bank already has.
                    'merchant_name' => (string) $merchant->name,
                    'bank' => $merchant->bank_name,
                    'account' => (string) $merchant->bank_account,
                    'account_name' => $merchant->bank_account_name,
                    'internal_ref' => self::mintReference(),
                    'state' => 'pending',
                ]);

                // Claim them. The whereNull guard makes a concurrent build
                // lose loudly instead of paying the same order twice.
                $claimed = Suborder::query()
                    ->whereIn('id', $suborders->pluck('id'))
                    ->whereNull('payout_item_id')
                    ->update(['payout_item_id' => $item->id]);

                if ($claimed !== $suborders->count()) {
                    throw new \RuntimeException(
                        'A suborder was claimed by another batch while this one was building.',
                    );
                }

                $total += $amount;
                $count++;
            }

            $batch->forceFill([
                'total_laari' => $total,
                'merchant_count' => $count,
                'excluded_laari' => $excludedLaari,
                'excluded_count' => $excludedCount,
            ])->save();

            return $batch->refresh();
        });
    }

    /**
     * Delivered, unpaid, and past the store's validation window.
     *
     * @return Builder<Suborder>
     */
    public function payableSuborders(CarbonImmutable $cutoff)
    {
        return Suborder::query()
            ->join('merchants', 'merchants.id', '=', 'suborders.merchant_id')
            ->where('suborders.state', 'delivered')
            ->whereNull('suborders.payout_item_id')
            ->where('suborders.payable_to_merchant_laari', '>', 0)
            ->whereRaw(
                'suborders.delivered_at + make_interval(days => merchants.validation_window_days) <= ?',
                [$cutoff],
            )
            ->select('suborders.*');
    }

    /** Refuse a batch nobody has approved, and release what it claimed. */
    public function cancel(MerchantPayoutBatch $batch): MerchantPayoutBatch
    {
        DB::transaction(function () use ($batch): void {
            $locked = MerchantPayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->state, ['draft', 'approved'], true)) {
                throw new \RuntimeException('Only a draft or approved batch can be cancelled.');
            }

            // Unclaim, so the orders fall into the next run rather than
            // vanishing with the batch.
            Suborder::query()
                ->whereIn('payout_item_id', $locked->items()->pluck('id'))
                ->update(['payout_item_id' => null]);

            $locked->items()->update(['state' => 'cancelled']);
            $locked->forceFill(['state' => 'cancelled'])->save();
        });

        return $batch->refresh();
    }

    /** MS-<year>-<seq>, serialised like every other reference here. */
    private function nextReference(): string
    {
        $year = CarbonImmutable::now((string) config('app.business_timezone', 'Indian/Maldives'))->year;

        DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('merchant-payouts-'.$year)]);

        $prefix = sprintf('MS-%d-', $year);
        $latest = MerchantPayoutBatch::query()->where('reference', 'like', $prefix.'%')->max('reference');
        $next = $latest === null ? 1 : (int) substr((string) $latest, strlen($prefix)) + 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /** The bank's idempotency key: minted once, then stable forever. */
    private static function mintReference(): string
    {
        return 'manfaa-m-'.CarbonImmutable::now()->format('Ymd').'-'.bin2hex(random_bytes(6));
    }
}
