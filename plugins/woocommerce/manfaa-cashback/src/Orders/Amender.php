<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Orders;

use Manfaa\Cashback\Api\ApiException;
use Manfaa\Cashback\Api\Client;
use Manfaa\Cashback\Money\Laari;
use Manfaa\Cashback\Pricing\CategoryMap;
use Manfaa\Cashback\Pricing\LineBuilder;
use Manfaa\Cashback\Support\Log;
use WC_Order;

/**
 * A partial refund, as an AMEND of the sale (owner, 2026-08-22): the sale
 * is reduced to what the buyer kept — `PATCH /v1/transactions/{id}` with
 * the new eligible amount, sale amount and lines, net of every refund so
 * far — and Manfaa re-prices the cashback at the terms frozen on the sale.
 *
 * Only possible while the sale is still pending on Manfaa. Once it has
 * confirmed (the refund window closed) the amend is refused
 * `not_amendable_state`; the order then gets a note saying so, and the
 * cashback stands — a confirmed sale's cashback is the merchant's to
 * reverse from the panel if they choose, not something a plugin should
 * quietly take back in full over a small return.
 *
 * Same mechanics as posting: the body is frozen on the hook and re-sent
 * byte-identical under a key that names the refund, so a retry can never
 * meet `idempotency_key_reuse_mismatch`. Several refunds queue several
 * amends; each sends the cumulative remainder, so order does not matter.
 */
final class Amender
{
    public const HOOK = 'manfaa_cashback_amend';

    public static function trigger(WC_Order $order, int $refundId): void
    {
        $fresh = wc_get_order($order->get_id());
        $order = $fresh instanceof WC_Order ? $fresh : $order;

        if (! in_array(Meta::get($order, Meta::STATE), State::LIVE, true) || (int) Meta::get($order, Meta::TRANSACTION_ID) <= 0) {
            return;
        }

        if (Meta::get($order, Meta::REVERSE_STATE) !== '') {
            return; // a reversal is already in flight or done
        }

        $priced = (new LineBuilder(CategoryMap::fromSettings()))->build($order, netOfRefunds: true);

        if ($priced['eligible_laari'] <= 0) {
            // Everything refunded through partial refunds: that is a full
            // reversal in all but name.
            Reverser::trigger($order, 'refunded');

            return;
        }

        $body = [
            'eligible_amount' => $priced['eligible_laari'],
            'sale_amount' => max($priced['sale_laari'], $priced['eligible_laari']),
        ];

        if ($priced['lines'] !== null) {
            $body['lines'] = $priced['lines'];
        }

        $order->update_meta_data(Meta::AMEND_REQUEST.'_'.$refundId, wp_json_encode($body, JSON_UNESCAPED_SLASHES));
        $order->save();

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, [$order->get_id(), $refundId], Poster::GROUP);
        }
    }

    public static function run(int $orderId, int $refundId, int $attempt = 1): void
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            return;
        }

        $raw = Meta::get($order, Meta::AMEND_REQUEST.'_'.$refundId);
        $transactionId = (int) Meta::get($order, Meta::TRANSACTION_ID);

        if ($raw === '' || $transactionId <= 0 || ! in_array(Meta::get($order, Meta::STATE), State::LIVE, true)) {
            return;
        }

        try {
            $answer = Client::fromSettings()->patchRaw(
                'v1/transactions/'.$transactionId,
                $raw,
                Poster::idempotencyKey($orderId, 'amend:'.$refundId),
            );
        } catch (ApiException $e) {
            self::failed($order, $e, $refundId, $attempt);

            return;
        }

        $transaction = (array) ($answer['transaction'] ?? []);
        $cashback = (int) ($transaction['cashback_laari'] ?? 0);

        Meta::setMany($order, [
            Meta::CASHBACK_LAARI => $cashback,
            Meta::TRANSACTION_STATE => (string) ($transaction['state'] ?? ''),
            Meta::AMEND_REQUEST.'_'.$refundId => null,
        ]);
        $order->add_order_note(sprintf(
            /* translators: 1: eligible amount, 2: cashback */
            __('Manfaa: sale reduced to MVR %1$s after the refund — cashback is now MVR %2$s.', 'manfaa-cashback'),
            Laari::toMvr((int) ($transaction['eligible_laari'] ?? 0)),
            Laari::toMvr($cashback),
        ));
    }

    private static function failed(WC_Order $order, ApiException $e, int $refundId, int $attempt): void
    {
        if ($e->status === 409 && in_array($e->errorCode, ['not_amendable_state', 'backdated_irreversible'], true)) {
            Meta::setMany($order, [Meta::AMEND_REQUEST.'_'.$refundId => null]);
            $order->add_order_note(__('Manfaa: the sale had already confirmed when the partial refund was made, so its cashback is unchanged. Reverse it from the Manfaa merchant panel if you need to.', 'manfaa-cashback'));

            return;
        }

        if ($e->retryable() && $attempt <= count(Poster::BACKOFF) && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + max(Poster::BACKOFF[$attempt - 1], $e->retryAfter ?? 0), self::HOOK, [$order->get_id(), $refundId, $attempt + 1], Poster::GROUP);

            return;
        }

        Meta::setMany($order, [Meta::AMEND_REQUEST.'_'.$refundId => null, Meta::ERROR => $e->errorCode.': '.$e->getMessage()]);
        $order->add_order_note(sprintf(
            /* translators: %s: code */
            __('Manfaa: could not reduce the sale after the refund (%s). The cashback is unchanged.', 'manfaa-cashback'),
            $e->errorCode,
        ));
        Log::error('Amend failed', ['order' => $order->get_id(), 'code' => $e->errorCode]);
    }
}
