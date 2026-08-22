<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Orders;

use Manfaa\Cashback\Api\ApiException;
use Manfaa\Cashback\Api\Client;
use Manfaa\Cashback\Api\Connect;
use Manfaa\Cashback\Api\RateCard;
use Manfaa\Cashback\Pricing\CategoryMap;
use Manfaa\Cashback\Pricing\LineBuilder;
use Manfaa\Cashback\Support\Log;
use Manfaa\Cashback\Support\Options;
use WC_Order;

/**
 * Posting a sale to Manfaa when the order reaches the trigger status.
 *
 * Two halves, deliberately split. {@see trigger()} runs ON the status
 * hook, synchronously and cheaply: it decides whether this order posts at
 * all, FREEZES the exact request body into order meta, marks the order
 * `queued`, and enqueues one Action Scheduler job. {@see run()} runs off
 * the request thread and re-sends that frozen body byte-identical under a
 * deterministic Idempotency-Key on every attempt — so a retry can never
 * meet `idempotency_key_reuse_mismatch`, and a job re-run a week later is
 * still "now" (no `occurred_at` is ever sent).
 */
final class Poster
{
    public const HOOK = 'manfaa_cashback_post';
    public const GROUP = 'manfaa';

    /** Retry ladder in seconds; after the last, the order needs attention. */
    public const BACKOFF = [60, 300, 1800, 7200, 43200];

    /** The status hook fired. Decide, freeze, enqueue. */
    public static function trigger(WC_Order $order): void
    {
        // Fresh read, same reason as Reverser::trigger — the object on the
        // status hook may be older than the last save of this order.
        $fresh = wc_get_order($order->get_id());
        $order = $fresh instanceof WC_Order ? $fresh : $order;
        $current = Meta::get($order, Meta::STATE);

        if (State::settled($current) || $current === State::QUEUED) {
            // Re-completion after a reversal is final; anything else past
            // queued has already been acted on.
            if ($current === State::REVERSED) {
                Meta::setMany($order, [Meta::STATE => State::FINAL_REVERSED]);
                $order->add_order_note(__('Manfaa: cashback was already reversed on this order — it cannot be earned again.', 'manfaa-cashback'));
            }

            return;
        }

        if (! Client::fromSettings()->connected()) {
            return;
        }

        if ($order->get_currency() !== 'MVR') {
            Meta::setMany($order, [Meta::STATE => State::SKIPPED_CURRENCY]);

            return;
        }

        if (Options::bool('only_after_activation')) {
            $activated = (int) get_option('manfaa_cashback_activated_at', 0);
            $created = $order->get_date_created();

            if ($activated > 0 && $created !== null && $created->getTimestamp() < $activated) {
                Meta::setMany($order, [Meta::STATE => State::SKIPPED_PRE_ACTIVATION]);

                return;
            }
        }

        $ref = self::customerRef($order);

        if ($ref === null) {
            Meta::setMany($order, [Meta::STATE => State::SKIPPED_NO_CODE]);
            $order->add_order_note(__('Manfaa: no Manfaa code on this order, so no cashback was posted.', 'manfaa-cashback'));

            return;
        }

        $priced = (new LineBuilder(CategoryMap::fromSettings()))->build($order);

        if ($priced['eligible_laari'] <= 0) {
            Meta::setMany($order, [Meta::STATE => State::SKIPPED_ZERO]);
            $order->add_order_note(__('Manfaa: nothing on this order is eligible for cashback (eligible amount is zero).', 'manfaa-cashback'));

            return;
        }

        $invoice = Options::invoicePrefix().$order->get_order_number();

        $body = [
            'invoice_no' => $invoice,
            'customer_ref' => $ref,
            'origin' => 'online_link',
            'eligible_amount' => $priced['eligible_laari'],
            'sale_amount' => max($priced['sale_laari'], $priced['eligible_laari']),
        ];

        if ($priced['lines'] !== null) {
            $body['lines'] = $priced['lines'];
        }

        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::HOOK, [$order->get_id()], self::GROUP)) {
            return;
        }

        Meta::setMany($order, [
            Meta::STATE => State::QUEUED,
            Meta::INVOICE_NO => $invoice,
            Meta::REQUEST => wp_json_encode($body, JSON_UNESCAPED_SLASHES),
            Meta::ATTEMPTS => 0,
            Meta::ERROR => null,
        ]);

        self::enqueue($order->get_id(), 0);
    }

    /** The Action Scheduler job. */
    public static function run(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order || Meta::get($order, Meta::STATE) !== State::QUEUED) {
            return;
        }

        $client = Client::fromSettings();

        if (! $client->connected()) {
            self::disconnected($order, __('No Manfaa connection.', 'manfaa-cashback'));

            return;
        }

        $raw = Meta::get($order, Meta::REQUEST);
        $key = self::idempotencyKey($orderId, 'create');
        $attempt = (int) Meta::get($order, Meta::ATTEMPTS) + 1;
        Meta::setMany($order, [Meta::ATTEMPTS => $attempt], false);

        try {
            $answer = $client->postRaw('v1/transactions', $raw, $key);
        } catch (ApiException $e) {
            self::failed($order, $e, $attempt);

            return;
        }

        self::recorded($order, $answer);
    }

    /** @param array<string, mixed> $answer */
    private static function recorded(WC_Order $order, array $answer): void
    {
        $transaction = (array) ($answer['transaction'] ?? []);
        $id = (int) ($transaction['id'] ?? 0);
        $cashback = (int) ($transaction['cashback_laari'] ?? 0);
        $status = (string) ($answer['status'] ?? 'created');
        $reason = (string) ($answer['reason'] ?? '');

        if ($status === 'created') {
            Meta::setMany($order, [
                Meta::STATE => State::POSTED,
                Meta::TRANSACTION_ID => $id,
                Meta::TRANSACTION_STATE => (string) ($transaction['state'] ?? 'awaiting_validation'),
                Meta::CASHBACK_LAARI => $cashback,
                Meta::ERROR => null,
            ]);
            $order->add_order_note(sprintf(
                /* translators: 1: amount, 2: Manfaa transaction id */
                __('Manfaa: cashback MVR %1$s recorded (#%2$d). It shows as pending in the buyer\'s app until the sale is confirmed.', 'manfaa-cashback'),
                \Manfaa\Cashback\Money\Laari::toMvr($cashback),
                $id,
            ));

            return;
        }

        // below_minimum / recorded_ineligible: recorded, priced at zero.
        Meta::setMany($order, [
            Meta::STATE => State::POSTED_ZERO,
            Meta::TRANSACTION_ID => $id,
            Meta::TRANSACTION_STATE => $status === 'recorded_ineligible' ? 'recorded_ineligible' : 'below_minimum',
            Meta::CASHBACK_LAARI => 0,
            Meta::ERROR => null,
        ]);
        $order->add_order_note(sprintf(
            /* translators: %s: Manfaa reason code */
            __('Manfaa: sale recorded with zero cashback (%s).', 'manfaa-cashback'),
            $reason !== '' ? $reason : $status,
        ));
    }

    private static function failed(WC_Order $order, ApiException $e, int $attempt): void
    {
        // Adoption: the sale already exists — we posted it before a crash,
        // or the merchant's till did. Read it back and record what is there.
        if ($e->status === 409 && $e->errorCode === 'duplicate_invoice') {
            $existing = (int) $e->meta('transaction_id');

            if ($existing > 0 && self::adopt($order, $existing)) {
                return;
            }
        }

        if ($e->disconnects()) {
            self::disconnected($order, $e->getMessage());

            return;
        }

        if ($e->retryable()) {
            if ($attempt <= count(self::BACKOFF)) {
                $delay = max(self::BACKOFF[$attempt - 1], $e->retryAfter ?? 0);
                Meta::setMany($order, [Meta::ERROR => $e->errorCode.': '.$e->getMessage()]);
                self::enqueue($order->get_id(), $delay);

                return;
            }

            Meta::setMany($order, [Meta::STATE => State::NEEDS_ATTENTION, Meta::ERROR => $e->errorCode.': '.$e->getMessage()]);
            $order->add_order_note(sprintf(
                /* translators: %s: the last error */
                __('Manfaa: gave up posting this sale after repeated failures (%s). Use Retry on the order once Manfaa is reachable.', 'manfaa-cashback'),
                $e->errorCode,
            ));
            Log::error('Posting exhausted', ['order' => $order->get_id(), 'code' => $e->errorCode]);

            return;
        }

        // Terminal 4xx: customer_not_found, validation_failed, unknown or
        // inactive category, reuse mismatch — a person has to look.
        Meta::setMany($order, [Meta::STATE => State::NEEDS_ATTENTION, Meta::ERROR => $e->errorCode.': '.$e->getMessage()]);
        $order->add_order_note(sprintf(
            /* translators: 1: code, 2: message */
            __('Manfaa refused this sale: %1$s — %2$s', 'manfaa-cashback'),
            $e->errorCode,
            $e->getMessage(),
        ));
    }

    private static function adopt(WC_Order $order, int $transactionId): bool
    {
        try {
            $answer = Client::fromSettings()->get('v1/transactions/'.$transactionId);
        } catch (ApiException) {
            return false;
        }

        $transaction = (array) ($answer['transaction'] ?? []);
        $state = (string) ($transaction['state'] ?? '');

        Meta::setMany($order, [
            Meta::STATE => State::ADOPTED,
            Meta::TRANSACTION_ID => $transactionId,
            Meta::TRANSACTION_STATE => $state,
            Meta::CASHBACK_LAARI => (int) ($transaction['cashback_laari'] ?? 0),
            Meta::ERROR => null,
        ]);
        $order->add_order_note(sprintf(
            /* translators: 1: id, 2: state */
            __('Manfaa: this sale already existed as #%1$d (%2$s) — adopted rather than posted twice.', 'manfaa-cashback'),
            $transactionId,
            State::transactionLabel($state),
        ));

        return true;
    }

    private static function disconnected(WC_Order $order, string $why): void
    {
        Meta::setMany($order, [Meta::STATE => State::DISCONNECTED, Meta::ERROR => $why]);
        $order->add_order_note(__('Manfaa: the connection no longer works — reconnect from Manfaa Cashback settings, then press Retry on this order.', 'manfaa-cashback'));
        update_option('manfaa_cashback_disconnected', $why, false);
        Log::error('Disconnected while posting', ['order' => $order->get_id(), 'why' => $why]);
    }

    /** Retry from the order screen: back to queued with the frozen body, attempts reset. */
    public static function retry(WC_Order $order): bool
    {
        $state = Meta::get($order, Meta::STATE);

        if (! in_array($state, [State::NEEDS_ATTENTION, State::DISCONNECTED], true) || Meta::get($order, Meta::REQUEST) === '') {
            return false;
        }

        Meta::setMany($order, [Meta::STATE => State::QUEUED, Meta::ATTEMPTS => 0, Meta::ERROR => null]);
        self::enqueue($order->get_id(), 0);

        return true;
    }

    /** Poll the sale's state — "Refresh status" and the daily sweep. */
    public static function refresh(WC_Order $order): void
    {
        $id = (int) Meta::get($order, Meta::TRANSACTION_ID);

        if ($id <= 0) {
            return;
        }

        try {
            $answer = Client::fromSettings()->get('v1/transactions/'.$id);
        } catch (ApiException) {
            return;
        }

        $transaction = (array) ($answer['transaction'] ?? []);
        $state = (string) ($transaction['state'] ?? '');
        $updates = [Meta::TRANSACTION_STATE => $state, Meta::LAST_CHECKED => time()];

        if ($state === 'reversed' && in_array(Meta::get($order, Meta::STATE), State::LIVE, true)) {
            $updates[Meta::STATE] = State::REVERSED;
            $order->add_order_note(__('Manfaa: this sale was reversed on Manfaa.', 'manfaa-cashback'));
        }

        Meta::setMany($order, $updates);
    }

    /** 6-digit code on the order, or the normalised billing phone when that fallback is on. */
    public static function customerRef(WC_Order $order): ?string
    {
        $code = preg_replace('/\D+/', '', Meta::get($order, Meta::CODE)) ?? '';

        if (preg_match('/^\d{6}$/', $code)) {
            return $code;
        }

        if (! Options::bool('phone_fallback')) {
            return null;
        }

        return self::normalisePhone((string) $order->get_billing_phone());
    }

    /** `+960XXXXXXX` for a Maldivian mobile (7 digits starting 7 or 9), else null. */
    public static function normalisePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (preg_match('/^(?:960)?([79]\d{6})$/', $digits, $m)) {
            return '+960'.$m[1];
        }

        return null;
    }

    public static function idempotencyKey(int $orderId, string $action): string
    {
        return 'woo:'.Options::siteHash().':order:'.$orderId.':'.$action;
    }

    private static function enqueue(int $orderId, int $delay): void
    {
        if (! function_exists('as_schedule_single_action')) {
            return;
        }

        if ($delay > 0) {
            as_schedule_single_action(time() + $delay, self::HOOK, [$orderId], self::GROUP);
        } else {
            as_enqueue_async_action(self::HOOK, [$orderId], self::GROUP);
        }
    }
}
