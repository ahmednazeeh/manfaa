<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tell the store's own customers that it has paused — or resumed — cashback.
 *
 * "Its own customers" is deliberately narrow: someone who has actually
 * EARNED cashback there. Not everyone who once had a sale recorded (a
 * below-minimum or reversed sale earned nothing and taught the shopper
 * nothing about the shop), and certainly not the whole customer base. The
 * test is a transaction at this merchant that put cashback on the books and
 * was not reversed.
 *
 * Queued and chunked because it fans out: one merchant tap becomes one
 * message per past customer, and that must never happen inside the request
 * that flipped the switch.
 *
 * The DAILY CAP is not enforced here. It is decided in
 * StorePublicationService, before this job is dispatched, and written to the
 * merchant row in the same transaction as the toggle — so a retry of this
 * job cannot re-open the gate, and neither can a cache flush.
 */
class NotifyStorePublicationChange implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /** Big enough to be few queries, small enough to hold in memory. */
    private const int CHUNK = 500;

    public function __construct(
        private readonly int $merchantId,
        private readonly bool $paused,
    ) {}

    public function handle(NotificationService $notifications): void
    {
        $merchant = Merchant::find($this->merchantId);

        if ($merchant === null) {
            return;
        }

        $key = $this->paused
            ? NotificationTemplateKey::StorePaused
            : NotificationTemplateKey::StoreResumed;

        // The store's name as the customer knows it — the same name the app
        // shows on the card they earned it from.
        $variables = ['store' => (string) $merchant->name];

        Customer::query()
            ->whereIn('id', Transaction::query()
                ->select('customer_id')
                ->where('merchant_id', $this->merchantId)
                ->where('cashback_laari', '>', 0)
                ->whereNotIn('state', ['reversed', 'written_off'])
                ->distinct())
            ->chunkById(self::CHUNK, function ($customers) use ($notifications, $key, $variables): void {
                foreach ($customers as $customer) {
                    $notifications->send($key, $customer, $variables);
                }
            });
    }
}
