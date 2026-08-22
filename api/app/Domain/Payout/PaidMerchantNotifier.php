<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\MerchantAccess\Permission;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Models\Merchant;
use App\Models\PayoutBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tells each merchant when a payout run has reached the customers who
 * earned cashback at their store.
 *
 * The merchant settled that money to the platform weeks earlier and then
 * heard nothing: until now the one party who never learned the customer had
 * actually been paid was the shop that funded it.
 *
 * ONE notification per merchant per batch, carrying the run's totals for
 * that store. A batch pays many customers at once; a line per customer
 * would be forty notifications about a single event.
 */
final readonly class PaidMerchantNotifier
{
    public function __construct(private NotificationService $notifications) {}

    public function notify(PayoutBatch $batch): void
    {
        foreach ($this->paidPerMerchant($batch) as $row) {
            $merchant = Merchant::query()->find($row->merchant_id);

            if ($merchant === null) {
                continue;
            }

            $this->notifications->sendToMerchantStaff(
                NotificationTemplateKey::CustomersPaid,
                $merchant,
                [
                    'customers' => (string) $row->customers,
                    'amount' => NotificationService::money((int) $row->cashback_laari),
                ],
                // News about the shop's money, so the people who may see the
                // wallet are the people who hear about it.
                Permission::WalletView,
            );
        }
    }

    /**
     * Cashback actually PAID in this batch, grouped by the store it was
     * earned at.
     *
     * Only `paid` items count. A batch that half failed still notifies the
     * merchants whose customers were reached, and says nothing to the rest —
     * telling a shop their customers were paid when the transfer bounced
     * would be worse than silence.
     *
     * @return Collection<int, object>
     */
    private function paidPerMerchant(PayoutBatch $batch)
    {
        return DB::table('transactions')
            ->join('payout_items', 'payout_items.id', '=', 'transactions.payout_item_id')
            ->where('payout_items.batch_id', $batch->getKey())
            ->where('payout_items.state', PayoutItemState::Paid->value)
            ->groupBy('transactions.merchant_id')
            ->selectRaw('transactions.merchant_id, count(distinct payout_items.customer_id) as customers, sum(transactions.cashback_laari) as cashback_laari')
            ->get();
    }
}
