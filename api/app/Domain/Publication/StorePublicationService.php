<?php

declare(strict_types=1);

namespace App\Domain\Publication;

use App\Domain\Discovery\DiscoveryService;
use App\Jobs\NotifyStorePublicationChange;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Self-service publication: a store takes itself off the app and puts itself
 * back, with no admin in the loop (owner decision 2026-08-18).
 *
 * Why no approval, when a name change needs one (MR9): an unpublish makes a
 * store LESS visible, and there is no claim in it to check. The risk MR9
 * guards against is a store editing what it promises the public; a store
 * saying "not today" promises nothing. Requiring a queue for it would also
 * mean a shop that shut for a funeral stayed listed until an admin woke up.
 *
 * The customer side is the part that needs care. Someone who has earned
 * cashback at a store has a reason to know it has paused — they might walk
 * there for it. Someone who never has does not. And the merchant controls
 * the switch, so the platform, not the merchant, decides how often that
 * switch may reach a customer's phone: ONE of each kind per store per day.
 */
final readonly class StorePublicationService
{
    /**
     * The owner's cap. Rolling rather than calendar so a toggle at 23:59
     * cannot send again at 00:01 — a day means a day, not a date change.
     */
    private const int NOTICE_WINDOW_HOURS = 24;

    /**
     * Take the store off the app. Idempotent: unpublishing an already
     * unpublished store changes nothing and notifies nobody, so a
     * double-tapped button cannot spend the day's notification.
     */
    public function unpublish(Merchant $merchant): bool
    {
        return $this->apply($merchant, unpublished: true);
    }

    /** Put it back. Same idempotence, same reason. */
    public function republish(Merchant $merchant): bool
    {
        return $this->apply($merchant, unpublished: false);
    }

    /**
     * @return bool whether customers were notified — the caller tells the
     *              merchant, so the merchant is never left wondering whether
     *              a silent toggle reached anyone.
     */
    private function apply(Merchant $merchant, bool $unpublished): bool
    {
        $alreadyThere = ($merchant->unpublished_at !== null) === $unpublished;

        if ($alreadyThere) {
            return false;
        }

        $now = CarbonImmutable::now();
        $stamp = $unpublished ? 'unpublish_notified_at' : 'republish_notified_at';
        $lastNotified = $merchant->{$stamp};

        // Asked BEFORE the write and honoured after it: the cap governs how
        // often customers are disturbed, never whether the store may toggle.
        // A merchant who flips twice in an hour still gets both flips; their
        // customers get one message.
        $mayNotify = $lastNotified === null
            || $lastNotified->lt($now->subHours(self::NOTICE_WINDOW_HOURS));

        DB::transaction(function () use ($merchant, $unpublished, $now, $stamp, $mayNotify): void {
            $merchant->unpublished_at = $unpublished ? $now : null;

            if ($mayNotify) {
                $merchant->{$stamp} = $now;
            }

            $merchant->save();
        });

        // The shelves and the store page are cached; a store that vanished
        // from the app a minute after it said so is the whole point.
        DiscoveryService::forgetMerchant($merchant);

        if ($mayNotify) {
            NotifyStorePublicationChange::dispatch((int) $merchant->getKey(), $unpublished);
        }

        return $mayNotify;
    }
}
