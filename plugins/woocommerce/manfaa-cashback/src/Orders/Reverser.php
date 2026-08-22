<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Orders;

use Manfaa\Cashback\Api\ApiException;
use Manfaa\Cashback\Api\Client;
use Manfaa\Cashback\Support\Log;
use Manfaa\Cashback\Support\Options;
use WC_Order;

/**
 * Reversing the sale when the order is cancelled, fully refunded or
 * trashed — the contractual obligation every integration carries.
 *
 * Same two halves as posting: the hook freezes a reason and enqueues; the
 * job sends. ONE reverse per order, ever: a full refund fires both
 * `woocommerce_order_fully_refunded` and `woocommerce_order_status_refunded`,
 * and a cancelled order can later be trashed — the reverse-state meta,
 * written synchronously in the hook, makes every later trigger a no-op.
 *
 * Outcomes: `reversed` (in place — the sale was still pending) or
 * `adjustment_created` (a credit memo against the store's next settlement,
 * because the sale was already confirmed or already on a submitted
 * settlement). Both are recorded in the merchant's words.
 */
final class Reverser
{
    public const HOOK = 'manfaa_cashback_reverse';

    public const REASONS = ['cancelled' => 'till_void', 'refunded' => 'customer_refund', 'trashed' => 'other'];

    public static function trigger(WC_Order $order, string $because): void
    {
        if (! Options::bool('reverse_on_cancel')) {
            return;
        }

        // Read the guard meta FRESH rather than from the object handed in:
        // a full refund reaches here twice on two different order objects
        // (WooCommerce's own refund flow, then the status change), and the
        // second one may predate the first one's save.
        $fresh = wc_get_order($order->get_id());
        $fresh = $fresh instanceof WC_Order ? $fresh : $order;

        if (Meta::get($fresh, Meta::REVERSE_STATE) !== '') {
            return; // already reversing, reversed, or refused
        }

        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::HOOK, [$order->get_id()], Poster::GROUP)) {
            return;
        }

        $order = $fresh;
        $state = Meta::get($order, Meta::STATE);

        if ($state === State::QUEUED) {
            // Cancelled before the post ran: the job will find the order
            // not queued any more and do nothing, and no sale ever exists.
            Meta::setMany($order, [Meta::STATE => State::SKIPPED_NO_CODE, Meta::REVERSE_STATE => 'unposted']);
            $order->add_order_note(__('Manfaa: cancelled before the sale was posted — nothing to reverse.', 'manfaa-cashback'));

            return;
        }

        if (! in_array($state, State::LIVE, true) || (int) Meta::get($order, Meta::TRANSACTION_ID) <= 0) {
            return;
        }

        $body = ['reason' => self::REASONS[$because] ?? 'other'];

        Meta::setMany($order, [
            Meta::REVERSE_STATE => State::QUEUED,
            Meta::REVERSE_REQUEST => wp_json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, [$order->get_id()], Poster::GROUP);
        }
    }

    public static function run(int $orderId, int $attempt = 1): void
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order || Meta::get($order, Meta::REVERSE_STATE) !== State::QUEUED) {
            return;
        }

        $transactionId = (int) Meta::get($order, Meta::TRANSACTION_ID);
        $client = Client::fromSettings();

        try {
            $answer = $client->postRaw(
                'v1/transactions/'.$transactionId.'/reverse',
                Meta::get($order, Meta::REVERSE_REQUEST),
                Poster::idempotencyKey($orderId, 'reverse'),
            );
        } catch (ApiException $e) {
            self::failed($order, $e, $attempt);

            return;
        }

        $outcome = (string) ($answer['outcome'] ?? '');
        $cause = (string) ($answer['cause'] ?? '');
        $transaction = (array) ($answer['transaction'] ?? []);

        if ($outcome === 'adjustment_created') {
            Meta::setMany($order, [
                Meta::STATE => State::ADJUSTED,
                Meta::REVERSE_STATE => 'done',
                Meta::TRANSACTION_STATE => (string) ($transaction['state'] ?? ''),
            ]);
            $order->add_order_note(match ($cause) {
                'already_confirmed' => __('Manfaa: the cashback was already confirmed, so a credit memo was created — it is netted against the store\'s next settlement.', 'manfaa-cashback'),
                'locked_in_settlement' => __('Manfaa: this sale is already on a submitted settlement, so a credit memo was created — credited back on the next one.', 'manfaa-cashback'),
                default => __('Manfaa: a credit memo was created for this sale.', 'manfaa-cashback'),
            });

            return;
        }

        Meta::setMany($order, [
            Meta::STATE => State::REVERSED,
            Meta::REVERSE_STATE => 'done',
            Meta::TRANSACTION_STATE => 'reversed',
        ]);
        $order->add_order_note(__('Manfaa: cashback reversed. The buyer sees it as reversed in their app.', 'manfaa-cashback'));
    }

    private static function failed(WC_Order $order, ApiException $e, int $attempt): void
    {
        if ($e->status === 409 && in_array($e->errorCode, ['backdated_irreversible', 'invalid_state'], true)) {
            Meta::setMany($order, [Meta::STATE => State::REVERSE_REFUSED, Meta::REVERSE_STATE => 'refused', Meta::ERROR => $e->errorCode.': '.$e->getMessage()]);
            $order->add_order_note(sprintf(
                /* translators: 1: code, 2: message */
                __('Manfaa refused the reversal: %1$s — %2$s. Contact Manfaa if this is wrong.', 'manfaa-cashback'),
                $e->errorCode,
                $e->getMessage(),
            ));

            return;
        }

        if ($e->retryable() && $attempt <= count(Poster::BACKOFF) && function_exists('as_schedule_single_action')) {
            $delay = max(Poster::BACKOFF[$attempt - 1], $e->retryAfter ?? 0);
            as_schedule_single_action(time() + $delay, self::HOOK, [$order->get_id(), $attempt + 1], Poster::GROUP);

            return;
        }

        Meta::setMany($order, [Meta::REVERSE_STATE => 'failed', Meta::ERROR => $e->errorCode.': '.$e->getMessage()]);
        $order->add_order_note(sprintf(
            /* translators: %s: code */
            __('Manfaa: the reversal could not be sent (%s). Reverse it from the Manfaa merchant panel.', 'manfaa-cashback'),
            $e->errorCode,
        ));
        Log::error('Reversal failed', ['order' => $order->get_id(), 'code' => $e->errorCode]);
    }

    /** Partial refund: the merchant's policy decides. */
    public static function partialRefund(WC_Order $order, int $refundId = 0): void
    {
        switch (Options::string('partial_refund_policy')) {
            case Options::PARTIAL_REVERSE_ALL:
                self::trigger($order, 'refunded');
                break;

            case Options::PARTIAL_AMEND:
                Amender::trigger($order, $refundId);
                break;

            default:
                // Do nothing: the buyer keeps the full cashback.
                break;
        }
    }
}
