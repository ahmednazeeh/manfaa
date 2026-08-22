<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Tests\Integration;

use Manfaa\Cashback\Api\Client;
use Manfaa\Cashback\Api\RateCard;
use Manfaa\Cashback\Orders\Meta;
use Manfaa\Cashback\Orders\State;
use Manfaa\Cashback\Support\Crypto;
use Manfaa\Cashback\Support\Options;
use Manfaa\Cashback\Webhooks\Receiver;
use WP_REST_Request;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_testsecret';

    public function set_up(): void
    {
        parent::set_up();
        update_option(Receiver::SECRET_OPTION, Crypto::encrypt(self::SECRET), false);
        update_option(Receiver::ENDPOINT_OPTION, 41, false);
        do_action('rest_api_init');
    }

    private function deliver(array $event, ?string $secret = self::SECRET, ?int $timestamp = null): \WP_REST_Response
    {
        $body = wp_json_encode($event);
        $request = new WP_REST_Request('POST', '/manfaa/v1/webhook');
        $request->set_body($body);
        $request->set_header('content-type', 'application/json');

        if ($secret !== null) {
            $request->set_header('x-manfaa-signature', hash_hmac('sha256', $body, $secret));
        }

        $request->set_header('x-manfaa-timestamp', (string) ($timestamp ?? time()));

        return rest_get_server()->dispatch($request);
    }

    public function test_registers_itself_and_stores_the_secret_once(): void
    {
        $this->answer(201, ['secret' => 'whsec_fresh', 'endpoint' => ['id' => 77]]);
        Receiver::register(Client::fromSettings());

        $request = $this->lastRequest();
        self::assertStringEndsWith('/v1/webhooks', $request['url']);
        $body = json_decode((string) $request['body'], true);
        self::assertSame(rest_url('manfaa/v1/webhook'), $body['url']);
        self::assertSame(Receiver::EVENTS, $body['events']);
        self::assertSame('whsec_fresh', Crypto::decrypt(get_option(Receiver::SECRET_OPTION)));
        self::assertSame('77', (string) get_option(Receiver::ENDPOINT_OPTION));
        self::assertTrue(Receiver::registered());
    }

    public function test_refuses_bad_signatures_and_stale_deliveries(): void
    {
        self::assertSame(401, $this->deliver(['id' => 'evt_1', 'type' => 'webhook.test', 'data' => []], 'wrong')->get_status());
        self::assertSame(401, $this->deliver(['id' => 'evt_1', 'type' => 'webhook.test', 'data' => []], null)->get_status());
        self::assertSame(401, $this->deliver(['id' => 'evt_1', 'type' => 'webhook.test', 'data' => []], self::SECRET, time() - 3600)->get_status());
        self::assertSame(200, $this->deliver(['id' => 'evt_1', 'type' => 'webhook.test', 'data' => []])->get_status());
    }

    public function test_a_reversed_event_marks_the_order_and_is_deduplicated(): void
    {
        Options::update(['post_on_status' => 'completed']);
        $order = $this->order([[$this->product('Tea', 100.00), 1]]);
        $this->answer(201, ['status' => 'created', 'reason' => null, 'transaction' => ['id' => 501, 'state' => 'awaiting_validation', 'cashback_laari' => 500]]);
        $order->update_status('completed');
        $this->runJobs();

        $event = ['id' => 'evt_rev', 'type' => 'transaction.reversed', 'created_at' => '2026-08-22T10:00:00+05:00', 'data' => ['transaction_id' => 501, 'merchant_id' => 1, 'invoice_no' => 'X', 'reason' => 'other', 'reversed_at' => '2026-08-22T10:00:00+05:00']];

        self::assertSame(200, $this->deliver($event)->get_status());
        $order = wc_get_order($order->get_id());
        self::assertSame(State::REVERSED, Meta::get($order, Meta::STATE));
        $notesBefore = count(wc_get_order_notes(['order_id' => $order->get_id()]));

        $again = $this->deliver($event);
        self::assertSame(200, $again->get_status());
        self::assertTrue($again->get_data()['duplicate']);
        self::assertCount($notesBefore, wc_get_order_notes(['order_id' => $order->get_id()]));
    }

    public function test_rate_changed_drops_the_cache_and_resyncs(): void
    {
        $this->answer(200, ['cashback_rate_percent' => '3.00', 'min_eligible_laari' => 1000, 'has_category_overrides' => false, 'currency' => 'MVR']);
        self::assertSame(200, $this->deliver(['id' => 'evt_rate', 'type' => 'merchant.rate_changed', 'data' => ['merchant_id' => 1]])->get_status());

        self::assertSame(300, RateCard::cached()->rateBp);
        self::assertSame(1000, RateCard::cached()->minEligibleLaari);
    }
}
