<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\MerchantAccess\Permission;
use App\Domain\Money\Percent;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * "GST now applies to your platform fee" — sent ONCE, on the superadmin
 * action that enables GST, and never again (owner decision, 2026-08-24).
 *
 * NOT on a rate edit. The rate is on every settlement screen the merchant
 * looks at, which is where a correction belongs; a push per correction is a
 * nuisance about a number nobody at the shop typed. The thing a merchant
 * genuinely cannot discover for themselves is that the shape of their bill
 * changed — one number became two — and that happens exactly once.
 *
 * Reaches the staff who watch settlements (the people who see what the shop
 * owes), by push and by SMS to the store's own verified number, exactly as
 * every other merchant moment does. Chunked: this fans out to every
 * approved store on the platform in one request, and the notification
 * service defers each send to afterCommit anyway.
 */
final readonly class GstAnnouncement
{
    /** Stores per chunk. */
    private const int CHUNK = 200;

    public function __construct(private NotificationService $notifications) {}

    /**
     * @return int how many stores were told
     */
    public function announce(FeeTax $tax, CarbonImmutable $enabledAt): int
    {
        $variables = [
            // PLAN §1 wire format even in prose: a percent, never bp.
            'rate' => Percent::format($tax->rateBp).'%',
            'date' => $enabledAt
                ->setTimezone((string) config('app.business_timezone', 'Indian/Maldives'))
                ->toDateString(),
            'effect' => $tax->treatment->merchantEffect(),
        ];

        $told = 0;

        Merchant::query()
            // Approved stores — TRADING or SUSPENDED. A store still in
            // review (or closed) has no settlements, no fee, and nothing yet
            // to be taxed on: telling it about a tax on a bill it has never
            // received would be the first thing we ever said to it.
            //
            // Suspension is temporary and the platform applies it ITSELF for
            // an overdue settlement, so a suspended store is precisely one
            // that owes money whose shape is about to change — and this
            // announcement fires exactly once, on the transition, so a store
            // skipped here would never be told by any path at all.
            ->whereIn('status', ['active', 'suspended'])
            ->orderBy('id')
            ->select(['id', 'name', 'contact_phone', 'support_phone'])
            ->chunkById(self::CHUNK, function (Collection $merchants) use ($variables, &$told): void {
                /** @var Collection<int, Merchant> $merchants */
                foreach ($merchants as $merchant) {
                    $this->notifications->sendToMerchantStaff(
                        NotificationTemplateKey::GstNowApplies,
                        $merchant,
                        $variables,
                        Permission::SettlementsView,
                    );

                    $told++;
                }
            });

        return $told;
    }
}
