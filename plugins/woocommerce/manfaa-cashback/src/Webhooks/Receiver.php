<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Webhooks;

use Manfaa\Cashback\Api\Client;
use Manfaa\Cashback\Api\RateCard;
use Manfaa\Cashback\Orders\Meta;
use Manfaa\Cashback\Orders\State;
use Manfaa\Cashback\Support\Crypto;
use Manfaa\Cashback\Support\Log;
use WP_REST_Request;
use WP_REST_Response;

/**
 * This site's own Manfaa webhook endpoint, registered by the plugin itself
 * over `POST /v1/webhooks` at connect time — the no-manual-setup path.
 *
 * Verification as the guide says: HMAC-SHA256 of the raw body with the
 * secret issued once at registration, constant-time compare, a timestamp
 * window, and de-duplication on the event id (delivery is at-least-once).
 */
final class Receiver
{
    public const ROUTE = 'manfaa/v1';

    public const SECRET_OPTION = 'manfaa_cashback_webhook_secret';
    public const ENDPOINT_OPTION = 'manfaa_cashback_webhook_endpoint';

    public const EVENTS = ['merchant.rate_changed', 'merchant.suspended', 'merchant.reinstated', 'transaction.reversed'];

    private const TOLERANCE = 5 * MINUTE_IN_SECONDS;

    public static function url(): string
    {
        return rest_url(self::ROUTE.'/webhook');
    }

    public static function registerRoute(): void
    {
        register_rest_route(self::ROUTE, '/webhook', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** Register with Manfaa; the secret comes back once and is stored encrypted. */
    public static function register(Client $client): void
    {
        $answer = $client->post('v1/webhooks', [
            'url' => self::url(),
            'label' => 'WooCommerce — '.wp_parse_url(home_url(), PHP_URL_HOST),
            'events' => self::EVENTS,
        ]);

        $secret = (string) ($answer['secret'] ?? '');
        $id = (int) ($answer['endpoint']['id'] ?? 0);

        if ($secret !== '') {
            update_option(self::SECRET_OPTION, Crypto::encrypt($secret), false);
        }

        update_option(self::ENDPOINT_OPTION, $id, false);
        Log::info('Webhook endpoint registered', ['id' => $id]);
    }

    public static function unregister(Client $client): void
    {
        $id = (int) get_option(self::ENDPOINT_OPTION, 0);

        if ($id > 0) {
            $client->delete('v1/webhooks/'.$id);
        }

        self::forgetSecret();
    }

    public static function forgetSecret(): void
    {
        delete_option(self::SECRET_OPTION);
        delete_option(self::ENDPOINT_OPTION);
    }

    public static function registered(): bool
    {
        return (int) get_option(self::ENDPOINT_OPTION, 0) > 0 && Crypto::decrypt(get_option(self::SECRET_OPTION) ?: null) !== null;
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $secret = Crypto::decrypt(get_option(self::SECRET_OPTION) ?: null);

        if ($secret === null) {
            return new WP_REST_Response(['message' => 'No webhook secret on this site.'], 503);
        }

        $raw = (string) $request->get_body();
        $signature = strtolower((string) $request->get_header('x-manfaa-signature'));
        $timestamp = (int) $request->get_header('x-manfaa-timestamp');

        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
            Log::warning('Webhook with a bad signature refused');

            return new WP_REST_Response(['message' => 'Bad signature.'], 401);
        }

        if ($timestamp > 0 && abs(time() - $timestamp) > self::TOLERANCE) {
            return new WP_REST_Response(['message' => 'Stale delivery.'], 401);
        }

        $event = json_decode($raw, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return new WP_REST_Response(['message' => 'Unreadable event.'], 400);
        }

        $seenKey = 'manfaa_evt_'.md5((string) $event['id']);

        if (get_transient($seenKey)) {
            return new WP_REST_Response(['ok' => true, 'duplicate' => true], 200);
        }

        set_transient($seenKey, 1, 2 * DAY_IN_SECONDS);

        self::dispatch((string) $event['type'], (array) ($event['data'] ?? []));

        return new WP_REST_Response(['ok' => true], 200);
    }

    /** @param array<string, mixed> $data */
    private static function dispatch(string $type, array $data): void
    {
        switch ($type) {
            case 'merchant.rate_changed':
                RateCard::forget();
                $client = Client::fromSettings();

                if ($client->connected()) {
                    try {
                        RateCard::sync($client);
                    } catch (\Throwable) {
                        // The next storefront read re-syncs from the option copy.
                    }
                }
                break;

            case 'merchant.suspended':
                update_option('manfaa_cashback_store_notice', 'suspended', false);
                break;

            case 'merchant.reinstated':
                delete_option('manfaa_cashback_store_notice');
                break;

            case 'transaction.reversed':
                self::reversed((int) ($data['transaction_id'] ?? $data['id'] ?? 0));
                break;

            default:
                // webhook.test and anything newer than this plugin: acknowledged, ignored.
                break;
        }
    }

    /** Manfaa (or the merchant panel) reversed a sale: reflect it on the order. */
    private static function reversed(int $transactionId): void
    {
        if ($transactionId <= 0) {
            return;
        }

        $orders = \Manfaa\Cashback\Orders\Query::byMeta([['key' => Meta::TRANSACTION_ID, 'value' => (string) $transactionId]], ['limit' => 1]);
        $order = $orders[0] ?? null;

        if (! $order instanceof \WC_Order) {
            return;
        }

        if (in_array(Meta::get($order, Meta::STATE), State::LIVE, true)) {
            Meta::setMany($order, [Meta::STATE => State::REVERSED, Meta::TRANSACTION_STATE => 'reversed', Meta::REVERSE_STATE => 'done']);
            $order->add_order_note(__('Manfaa: the cashback on this sale was reversed on Manfaa\'s side.', 'manfaa-cashback'));
        }
    }
}
