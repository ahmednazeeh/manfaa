<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Tests\Integration;

use Manfaa\Cashback\Orders\Meta;
use Manfaa\Cashback\Orders\Poster;
use Manfaa\Cashback\Orders\Reverser;
use Manfaa\Cashback\Orders\State;
use Manfaa\Cashback\Support\Options;

final class ReversalTest extends TestCase
{
    private function posted(): \WC_Order
    {
        Options::update(['post_on_status' => 'completed']);
        $order = $this->order([[$this->product('Tea', 100.00), 1]]);
        $this->answer(201, ['status' => 'created', 'reason' => null, 'transaction' => ['id' => 501, 'state' => 'awaiting_validation', 'cashback_laari' => 500]]);
        $order->update_status('completed');
        $this->runJobs();
        $this->requests = [];

        return wc_get_order($order->get_id());
    }

    public function test_cancelling_a_posted_order_reverses_it_once(): void
    {
        $order = $this->posted();
        $this->answer(200, ['outcome' => 'reversed', 'cause' => null, 'adjustment' => null, 'transaction' => ['id' => 501, 'state' => 'reversed']]);

        $order->update_status('cancelled');
        self::assertSame(State::QUEUED, Meta::get(wc_get_order($order->get_id()), Meta::REVERSE_STATE));
        $this->runJobs();

        $order = wc_get_order($order->get_id());
        self::assertSame(State::REVERSED, Meta::get($order, Meta::STATE));
        self::assertSame('done', Meta::get($order, Meta::REVERSE_STATE));

        $request = $this->lastRequest();
        self::assertStringEndsWith('/v1/transactions/501/reverse', $request['url']);
        self::assertSame(Poster::idempotencyKey($order->get_id(), 'reverse'), $request['headers']['Idempotency-Key']);
        self::assertSame(['reason' => 'till_void'], json_decode((string) $request['body'], true));

        // Re-completing a reversed order is final: nothing is posted again.
        $order->update_status('completed');
        self::assertSame(State::FINAL_REVERSED, Meta::get(wc_get_order($order->get_id()), Meta::STATE));
        self::assertCount(1, $this->requests);

        // Trashing it afterwards does not reverse again either.
        $order->delete(false);
        self::assertSame(0, $this->pendingJobs(Reverser::HOOK));
        self::assertCount(1, $this->requests);
    }

    public function test_a_full_refund_reverses_exactly_once_with_the_refund_reason(): void
    {
        $order = $this->posted();
        $this->answer(200, ['outcome' => 'reversed', 'cause' => null, 'adjustment' => null, 'transaction' => ['id' => 501, 'state' => 'reversed']]);

        // wc_create_refund for the full amount fires both the fully_refunded
        // hook and the status change to refunded.
        wc_create_refund(['order_id' => $order->get_id(), 'amount' => $order->get_total(), 'reason' => 'returned']);
        $order->update_status('refunded');

        self::assertSame(1, $this->pendingJobs(Reverser::HOOK));
        $this->runJobs();
        self::assertCount(1, $this->requests);
        self::assertSame(['reason' => 'customer_refund'], json_decode((string) $this->lastRequest()['body'], true));
        self::assertSame(State::REVERSED, Meta::get(wc_get_order($order->get_id()), Meta::STATE));
    }

    public function test_adjustment_created_is_recorded_with_its_cause(): void
    {
        $order = $this->posted();
        $this->answer(200, ['outcome' => 'adjustment_created', 'cause' => 'locked_in_settlement', 'adjustment' => ['id' => 9], 'transaction' => ['id' => 501, 'state' => 'confirmed']]);
        $order->update_status('cancelled');
        $this->runJobs();

        $order = wc_get_order($order->get_id());
        self::assertSame(State::ADJUSTED, Meta::get($order, Meta::STATE));
        $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
        self::assertStringContainsString('submitted settlement', $notes[0]->content);
    }

    public function test_refused_reversals_are_recorded_not_retried(): void
    {
        $order = $this->posted();
        $this->answer(409, ['error' => ['code' => 'backdated_irreversible', 'message' => 'final']]);
        $order->update_status('cancelled');
        $this->runJobs();

        $order = wc_get_order($order->get_id());
        self::assertSame(State::REVERSE_REFUSED, Meta::get($order, Meta::STATE));
        self::assertSame('refused', Meta::get($order, Meta::REVERSE_STATE));
        self::assertSame(0, $this->pendingJobs(Reverser::HOOK));
    }

    public function test_partial_refund_follows_the_policy(): void
    {
        $order = $this->posted();
        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 10.00]);
        self::assertSame(0, $this->pendingJobs(Reverser::HOOK));
        self::assertSame('', Meta::get(wc_get_order($order->get_id()), Meta::REVERSE_STATE));

        Options::update(['partial_refund_policy' => Options::PARTIAL_REVERSE_ALL]);
        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 10.00]);
        self::assertSame(1, $this->pendingJobs(Reverser::HOOK));
    }

    public function test_cancelling_before_the_post_ran_sends_nothing(): void
    {
        Options::update(['post_on_status' => 'completed']);
        $order = $this->order([[$this->product('Tea', 100.00), 1]]);
        $order->update_status('completed');
        self::assertSame(1, $this->pendingJobs(Poster::HOOK));

        $order->update_status('cancelled');
        $this->runJobs();

        self::assertCount(0, $this->requests);
        self::assertSame('unposted', Meta::get(wc_get_order($order->get_id()), Meta::REVERSE_STATE));
    }

    public function test_reverse_on_cancel_off_leaves_the_sale(): void
    {
        $order = $this->posted();
        Options::update(['reverse_on_cancel' => false]);
        $order->update_status('cancelled');
        self::assertSame(0, $this->pendingJobs(Reverser::HOOK));
    }
}
