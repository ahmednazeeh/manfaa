<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Tests\Integration;

use Manfaa\Cashback\Orders\Amender;
use Manfaa\Cashback\Orders\Meta;
use Manfaa\Cashback\Orders\Poster;
use Manfaa\Cashback\Orders\Reverser;
use Manfaa\Cashback\Orders\State;
use Manfaa\Cashback\Storefront\Badge;
use Manfaa\Cashback\Support\Options;

final class AmendTest extends TestCase
{
    private function posted(int $qty = 3, float $price = 100.00): \WC_Order
    {
        Options::update(['post_on_status' => 'completed', 'partial_refund_policy' => Options::PARTIAL_AMEND]);
        $order = $this->order([[$this->product('Tea', $price), $qty]]);
        $this->answer(201, ['status' => 'created', 'reason' => null, 'transaction' => ['id' => 501, 'state' => 'awaiting_validation', 'cashback_laari' => 1500]]);
        $order->update_status('completed');
        $this->runJobs();
        $this->requests = [];

        return wc_get_order($order->get_id());
    }

    public function test_a_partial_refund_amends_the_sale_to_what_was_kept(): void
    {
        $order = $this->posted(); // 3 × 100 = 300.00
        $item = array_values($order->get_items())[0];

        $this->answer(200, ['status' => 'amended', 'transaction' => ['id' => 501, 'state' => 'awaiting_validation', 'eligible_laari' => 20000, 'cashback_laari' => 1000]]);

        $refund = wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => 100.00,
            'line_items' => [$item->get_id() => ['qty' => 1, 'refund_total' => 100.00]],
        ]);
        self::assertSame(1, $this->pendingJobs(Amender::HOOK));
        $this->runJobs();

        $request = $this->lastRequest();
        self::assertSame('PATCH', $request['method']);
        self::assertStringEndsWith('/v1/transactions/501', $request['url']);
        self::assertSame(Poster::idempotencyKey($order->get_id(), 'amend:'.$refund->get_id()), $request['headers']['Idempotency-Key']);
        self::assertSame(['eligible_amount' => 20000, 'sale_amount' => 20000], json_decode((string) $request['body'], true));

        $order = wc_get_order($order->get_id());
        self::assertSame(State::POSTED, Meta::get($order, Meta::STATE));
        self::assertSame('1000', Meta::get($order, Meta::CASHBACK_LAARI));
        self::assertSame('', Meta::get($order, Meta::AMEND_REQUEST.'_'.$refund->get_id()));
    }

    public function test_a_confirmed_sale_keeps_its_cashback_and_says_so(): void
    {
        $order = $this->posted();
        $item = array_values($order->get_items())[0];
        $this->answer(409, ['error' => ['code' => 'not_amendable_state', 'message' => 'confirmed', 'meta' => ['state' => 'confirmed']]]);

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 100.00, 'line_items' => [$item->get_id() => ['qty' => 1, 'refund_total' => 100.00]]]);
        $this->runJobs();

        $order = wc_get_order($order->get_id());
        self::assertSame('1500', Meta::get($order, Meta::CASHBACK_LAARI));
        self::assertSame(0, $this->pendingJobs(Amender::HOOK));
        $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
        self::assertStringContainsString('already confirmed', $notes[0]->content);
    }

    public function test_refunding_everything_in_parts_becomes_a_reversal(): void
    {
        $order = $this->posted(qty: 1);
        $item = array_values($order->get_items())[0];
        $this->answer(200, ['outcome' => 'reversed', 'cause' => null, 'adjustment' => null, 'transaction' => ['id' => 501, 'state' => 'reversed']]);

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 100.00, 'line_items' => [$item->get_id() => ['qty' => 1, 'refund_total' => 100.00]]]);
        // A full-amount refund fires the fully_refunded path too; one reverse either way.
        $order = wc_get_order($order->get_id());

        if ($order->get_status() !== 'refunded') {
            $order->update_status('refunded');
        }

        self::assertSame(0, $this->pendingJobs(Amender::HOOK));
        self::assertSame(1, $this->pendingJobs(Reverser::HOOK));
        $this->runJobs();
        self::assertSame(State::REVERSED, Meta::get(wc_get_order($order->get_id()), Meta::STATE));
        self::assertCount(1, $this->requests);
    }

    public function test_the_product_badge_prices_one_unit_in_its_bucket(): void
    {
        Options::update(['show_product_badge' => true]);
        $fruits = $this->term('Fruit');
        $veggies = $this->term('Veg');
        Options::update(['pricing_mode' => Options::PRICING_PER_CATEGORY, 'category_map' => ['fruits' => [$fruits], 'veggies' => [$veggies]]]);

        self::assertSame(0, Badge::forProduct($this->product('Mango', 300.00, [$fruits])));   // excluded
        self::assertSame(600, Badge::forProduct($this->product('Carrot', 300.00, [$veggies]))); // 2.00 %
        self::assertSame(1500, Badge::forProduct($this->product('Bread', 300.00)));            // standing 5.00 %

        global $product;
        $product = $this->product('Bread', 300.00);
        ob_start();
        Badge::render();
        self::assertStringContainsString('Earn up to MVR 15.00 Manfaa cashback', ob_get_clean());

        Options::update(['show_product_badge' => false]);
        ob_start();
        Badge::render();
        self::assertSame('', ob_get_clean());
    }
}
