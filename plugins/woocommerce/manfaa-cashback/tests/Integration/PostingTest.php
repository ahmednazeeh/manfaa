<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Tests\Integration;

use Manfaa\Cashback\Orders\Meta;
use Manfaa\Cashback\Orders\Poster;
use Manfaa\Cashback\Orders\State;
use Manfaa\Cashback\Support\Options;

final class PostingTest extends TestCase
{
    private function created(int $id = 501, int $cashback = 2360, string $state = 'awaiting_validation'): array
    {
        return ['status' => 'created', 'reason' => null, 'transaction' => ['id' => $id, 'state' => $state, 'cashback_laari' => $cashback]];
    }

    public function test_completing_an_order_posts_the_sale_once_with_a_frozen_body(): void
    {
        Options::update(['post_on_status' => 'completed']);
        $product = $this->product('Tea', 1180.00);
        $order = $this->order([[$product, 1]]);

        $this->answer(201, $this->created());
        $order->update_status('completed');

        self::assertSame(State::QUEUED, Meta::get(wc_get_order($order->get_id()), Meta::STATE));
        self::assertSame(1, $this->pendingJobs(Poster::HOOK));

        $this->runJobs();

        $order = wc_get_order($order->get_id());
        self::assertSame(State::POSTED, Meta::get($order, Meta::STATE));
        self::assertSame('501', Meta::get($order, Meta::TRANSACTION_ID));
        self::assertSame('2360', Meta::get($order, Meta::CASHBACK_LAARI));

        $request = $this->lastRequest();
        self::assertSame('POST', $request['method']);
        self::assertStringEndsWith('/v1/transactions', $request['url']);
        self::assertSame('Bearer 12|testtoken', $request['headers']['Authorization']);
        self::assertSame(Poster::idempotencyKey($order->get_id(), 'create'), $request['headers']['Idempotency-Key']);

        $body = json_decode((string) $request['body'], true);
        self::assertSame('482917', $body['customer_ref']);
        self::assertSame('online_link', $body['origin']);
        self::assertSame(118000, $body['eligible_amount']);
        self::assertSame(118000, $body['sale_amount']);
        self::assertArrayNotHasKey('lines', $body);
        self::assertArrayNotHasKey('occurred_at', $body);
        self::assertSame(Options::invoicePrefix().$order->get_order_number(), $body['invoice_no']);
        // The body the job sent is exactly the body the hook froze.
        self::assertSame(Meta::get($order, Meta::REQUEST), $request['body']);

        // The status hook firing again (re-save, re-complete) posts nothing more.
        $order->update_status('processing');
        $order->update_status('completed');
        self::assertSame(0, $this->pendingJobs(Poster::HOOK));
        self::assertCount(1, $this->requests);
    }

    public function test_lines_are_built_per_category_and_sum_to_eligible(): void
    {
        $fruitsTerm = $this->term('Fresh fruit');
        $veggiesTerm = $this->term('Vegetables');
        Options::update(['post_on_status' => 'completed', 'pricing_mode' => Options::PRICING_PER_CATEGORY, 'category_map' => ['fruits' => [$fruitsTerm], 'veggies' => [$veggiesTerm]]]);

        $order = $this->order([
            [$this->product('Mango', 300.00, [$fruitsTerm]), 1],
            [$this->product('Carrot', 125.00, [$veggiesTerm]), 2],
            [$this->product('Bread', 450.00), 1],
        ]);

        $this->answer(201, $this->created(77, 2750));
        $order->update_status('completed');
        $this->runJobs();

        $body = json_decode((string) $this->lastRequest()['body'], true);
        self::assertSame(100000, $body['eligible_amount']);
        self::assertSame([
            ['category' => 'fruits', 'amount_laari' => 30000],
            ['category' => 'veggies', 'amount_laari' => 25000],
            ['category' => null, 'amount_laari' => 45000],
        ], $body['lines']);
        self::assertSame(array_sum(array_column($body['lines'], 'amount_laari')), $body['eligible_amount']);
    }

    public function test_a_product_in_two_mapped_categories_takes_the_first_in_the_synced_list(): void
    {
        $a = $this->term('A');
        $b = $this->term('B');
        Options::update(['post_on_status' => 'completed', 'pricing_mode' => Options::PRICING_PER_CATEGORY, 'category_map' => ['veggies' => [$a], 'fruits' => [$b]]]);

        // fruits is position 0 in the card, so it wins although veggies is mapped first.
        $order = $this->order([[$this->product('Both', 100.00, [$a, $b]), 1]]);
        $this->answer(201, $this->created());
        $order->update_status('completed');
        $this->runJobs();

        $body = json_decode((string) $this->lastRequest()['body'], true);
        self::assertSame([['category' => 'fruits', 'amount_laari' => 10000]], $body['lines']);
    }

    public function test_gst_inclusive_policy_adds_line_tax(): void
    {
        update_option('woocommerce_calc_taxes', 'yes');
        \WC_Tax::_insert_tax_rate(['tax_rate_country' => '', 'tax_rate' => '8.0000', 'tax_rate_name' => 'GST', 'tax_rate_priority' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '']);
        Options::update(['post_on_status' => 'completed', 'awarding_policy' => Options::POLICY_ITEMS_INC_TAX]);

        $order = $this->order([[$this->product('Tea', 100.00), 1]]);
        $this->answer(201, $this->created());
        $order->update_status('completed');
        $this->runJobs();

        $body = json_decode((string) $this->lastRequest()['body'], true);
        self::assertSame(10800, $body['eligible_amount']);
    }

    public function test_no_code_skips_unless_phone_fallback_is_on(): void
    {
        Options::update(['post_on_status' => 'completed']);
        $order = $this->order([[$this->product('Tea', 100.00), 1]], code: null);
        $order->update_status('completed');
        self::assertSame(State::SKIPPED_NO_CODE, Meta::get(wc_get_order($order->get_id()), Meta::STATE));
        self::assertCount(0, $this->requests);

        Options::update(['phone_fallback' => true]);
        $order = $this->order([[$this->product('Tea', 100.00), 1]], code: null);
        $this->answer(201, $this->created());
        $order->update_status('completed');
        $this->runJobs();
        $body = json_decode((string) $this->lastRequest()['body'], true);
        self::assertSame('+9607712345', $body['customer_ref']);
    }

    public function test_zero_eligible_and_foreign_currency_and_pre_activation_are_skipped(): void
    {
        Options::update(['post_on_status' => 'completed']);

        $free = $this->order([[$this->product('Free', 0.00), 1]]);
        $free->update_status('completed');
        self::assertSame(State::SKIPPED_ZERO, Meta::get(wc_get_order($free->get_id()), Meta::STATE));

        $usd = $this->order([[$this->product('Tea', 10.00), 1]]);
        $usd->set_currency('USD');
        $usd->save();
        $usd->update_status('completed');
        self::assertSame(State::SKIPPED_CURRENCY, Meta::get(wc_get_order($usd->get_id()), Meta::STATE));

        update_option('manfaa_cashback_activated_at', time() + 3600);
        $old = $this->order([[$this->product('Tea', 10.00), 1]]);
        $old->update_status('completed');
        self::assertSame(State::SKIPPED_PRE_ACTIVATION, Meta::get(wc_get_order($old->get_id()), Meta::STATE));

        self::assertCount(0, $this->requests);
    }

    public function test_below_minimum_and_ineligible_store_record_zero(): void
    {
        Options::update(['post_on_status' => 'completed']);
        $order = $this->order([[$this->product('Gum', 10.00), 1]]);
        $this->answer(200, ['status' => 'below_minimum', 'reason' => 'below_minimum', 'transaction' => ['id' => 9, 'state' => 'tracked', 'cashback_laari' => 0]]);
        $order->update_status('completed');
        $this->runJobs();

        $order = wc_get_order($order->get_id());
        self::assertSame(State::POSTED_ZERO, Meta::get($order, Meta::STATE));
        self::assertSame('below_minimum', Meta::get($order, Meta::TRANSACTION_STATE));
    }

    public function test_duplicate_invoice_is_adopted_by_reading_the_sale_back(): void
    {
        Options::update(['post_on_status' => 'completed']);
        $order = $this->order([[$this->product('Tea', 100.00), 1]]);
        $this->answer(409, ['error' => ['code' => 'duplicate_invoice', 'message' => 'exists', 'meta' => ['transaction_id' => 321]]]);
        $this->answer(200, ['transaction' => ['id' => 321, 'state' => 'confirmed', 'cashback_laari' => 500]]);
        $order->update_status('completed');
        $this->runJobs();

        $order = wc_get_order($order->get_id());
        self::assertSame(State::ADOPTED, Meta::get($order, Meta::STATE));
        self::assertSame('321', Meta::get($order, Meta::TRANSACTION_ID));
        self::assertSame('confirmed', Meta::get($order, Meta::TRANSACTION_STATE));
        self::assertStringEndsWith('/v1/transactions/321', $this->lastRequest()['url']);
    }

    public function test_retryable_failures_reschedule_with_the_same_key_and_body(): void
    {
        Options::update(['post_on_status' => 'completed']);
        $order = $this->order([[$this->product('Tea', 100.00), 1]]);
        $this->answer(503, ['message' => 'down']);
        $this->answer(429, ['message' => 'Too Many Attempts.'], ['retry-after' => '7']);
        $this->answer(201, $this->created());
        $order->update_status('completed');

        $this->runJobs();
        self::assertSame(State::QUEUED, Meta::get(wc_get_order($order->get_id()), Meta::STATE));
        self::assertSame(1, $this->pendingJobs(Poster::HOOK));

        $this->runJobs();
        $this->runJobs();

        self::assertSame(State::POSTED, Meta::get(wc_get_order($order->get_id()), Meta::STATE));
        self::assertCount(3, $this->requests);
        $keys = array_unique(array_map(fn ($r) => $r['headers']['Idempotency-Key'], $this->requests));
        $bodies = array_unique(array_map(fn ($r) => $r['body'], $this->requests));
        self::assertCount(1, $keys);
        self::assertCount(1, $bodies);
    }

    public function test_terminal_refusals_need_attention_and_auth_failures_disconnect(): void
    {
        Options::update(['post_on_status' => 'completed']);

        $a = $this->order([[$this->product('Tea', 100.00), 1]]);
        $this->answer(422, ['error' => ['code' => 'customer_not_found', 'message' => 'No customer matches ref "482917".']]);
        $a->update_status('completed');
        $this->runJobs();
        self::assertSame(State::NEEDS_ATTENTION, Meta::get(wc_get_order($a->get_id()), Meta::STATE));
        self::assertStringStartsWith('customer_not_found', Meta::get(wc_get_order($a->get_id()), Meta::ERROR));

        $b = $this->order([[$this->product('Tea', 100.00), 1]]);
        $this->answer(401, ['message' => 'Unauthenticated.']);
        $b->update_status('completed');
        $this->runJobs();
        self::assertSame(State::DISCONNECTED, Meta::get(wc_get_order($b->get_id()), Meta::STATE));
        self::assertNotEmpty(get_option('manfaa_cashback_disconnected'));

        // Retry puts it back in the queue with the same frozen body.
        self::assertTrue(Poster::retry(wc_get_order($b->get_id())));
        self::assertSame(State::QUEUED, Meta::get(wc_get_order($b->get_id()), Meta::STATE));
    }

    public function test_processing_trigger_when_chosen(): void
    {
        Options::update(['post_on_status' => 'processing']);
        // The hook is bound at boot from the option; rebind for this test.
        \Manfaa\Cashback\Plugin::instance()->boot();

        $order = $this->order([[$this->product('Tea', 100.00), 1]], status: 'pending');
        $this->answer(201, $this->created());
        $order->update_status('processing');
        $this->runJobs();
        self::assertSame(State::POSTED, Meta::get(wc_get_order($order->get_id()), Meta::STATE));
    }
}
