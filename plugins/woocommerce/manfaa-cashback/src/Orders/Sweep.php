<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Orders;

use Manfaa\Cashback\Api\Client;

/**
 * Once a day, refresh the Manfaa state of orders still pending and younger
 * than 60 days, so the column says "confirmed" and "paid" without anyone
 * pressing Refresh. Paged and capped: a big store is walked over several
 * days rather than in one hot request.
 */
final class Sweep
{
    public const HOOK = 'manfaa_cashback_sweep';

    private const BATCH = 50;

    public static function run(): void
    {
        if (! Client::fromSettings()->connected()) {
            return;
        }

        $orders = Query::byMeta([
            ['key' => Meta::STATE, 'value' => State::LIVE, 'compare' => 'IN'],
            ['key' => Meta::TRANSACTION_STATE, 'value' => ['awaiting_validation', 'on_hold', 'confirmed', 'payable_unfunded', ''], 'compare' => 'IN'],
        ], [
            'limit' => self::BATCH,
            'date_created' => '>'.(time() - 60 * DAY_IN_SECONDS),
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        foreach ($orders as $order) {
            if ($order instanceof \WC_Order) {
                Poster::refresh($order);
            }
        }
    }
}
