<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Tests\Integration;

use Manfaa\Cashback\Orders\Meta;
use Manfaa\Cashback\Storefront\Session;
use Manfaa\Cashback\Storefront\StoreApi;
use Manfaa\Cashback\Support\Options;
use WP_REST_Request;

final class StorefrontTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        WC()->session = new \WC_Session_Handler;
        WC()->session->init();
        WC()->cart = new \WC_Cart;
        WC()->customer = new \WC_Customer;
        do_action('rest_api_init');
    }

    public function test_only_a_complete_six_digit_code_is_kept(): void
    {
        self::assertSame('482917', Session::set('48 29 17'));
        self::assertSame('482917', Session::code());
        self::assertSame('', Session::set('4829'));
        self::assertSame('', Session::code());
    }

    public function test_the_cart_endpoint_data_carries_the_code_and_the_estimate(): void
    {
        Session::set('482917');
        $fruits = $this->term('Fruit');
        Options::update(['pricing_mode' => Options::PRICING_PER_CATEGORY, 'category_map' => ['fruits' => [$fruits]]]);

        WC()->cart->add_to_cart($this->product('Mango', 300.00, [$fruits])->get_id(), 1);
        WC()->cart->add_to_cart($this->product('Bread', 450.00)->get_id(), 2);
        WC()->cart->calculate_totals();

        $data = StoreApi::cartData();
        self::assertSame('482917', $data['code']);
        self::assertTrue($data['lookup']);
        self::assertTrue($data['estimate']['available']);
        self::assertSame(120000, $data['estimate']['eligible_laari']);
        // fruits excluded → 0; 900.00 at 5.00 % → 4500
        self::assertSame(4500, $data['estimate']['estimate_laari']);
        self::assertSame('45.00', $data['estimate']['estimate_mvr']);
    }

    public function test_the_estimate_shows_the_shortfall_below_the_minimum(): void
    {
        WC()->cart->add_to_cart($this->product('Gum', 10.00)->get_id(), 1);
        WC()->cart->calculate_totals();

        $estimate = StoreApi::cartData()['estimate'];
        self::assertSame(0, $estimate['estimate_laari']);
        self::assertSame(4000, $estimate['shortfall_laari']);
    }

    public function test_the_estimate_is_hidden_when_switched_off_or_not_mvr(): void
    {
        WC()->cart->add_to_cart($this->product('Tea', 100.00)->get_id(), 1);
        WC()->cart->calculate_totals();

        Options::update(['show_estimate' => false]);
        self::assertFalse(StoreApi::cartData()['estimate']['available']);

        Options::update(['show_estimate' => true]);
        update_option('woocommerce_currency', 'USD');
        self::assertFalse(StoreApi::cartData()['estimate']['available']);
    }

    public function test_the_lookup_route_stores_the_code_and_relays_the_answer(): void
    {
        $this->answer(200, ['ref' => '482917', 'valid' => true, 'name' => 'Aisha Mohamed', 'masked_name' => 'Ais*** Moh***']);

        $request = new WP_REST_Request('POST', '/manfaa/v1/lookup');
        $request->set_header('x-manfaa-nonce', wp_create_nonce('manfaa_lookup'));
        $request->set_body_params(['code' => '482917']);
        $response = rest_get_server()->dispatch($request);

        self::assertSame(200, $response->get_status());
        self::assertTrue($response->get_data()['valid']);
        self::assertSame('Aisha', $response->get_data()['first_name']);
        self::assertSame('482917', Session::code());
        self::assertStringContainsString('ref=482917', $this->lastRequest()['url']);

        // Unknown code: 404 from Manfaa becomes a plain "no account" answer.
        $this->answer(404, ['error' => ['code' => 'customer_not_found', 'message' => 'nope']]);
        $request = new WP_REST_Request('POST', '/manfaa/v1/lookup');
        $request->set_header('x-manfaa-nonce', wp_create_nonce('manfaa_lookup'));
        $request->set_body_params(['code' => '000000']);
        $response = rest_get_server()->dispatch($request);
        self::assertFalse($response->get_data()['valid']);
        self::assertTrue($response->get_data()['unknown']);

        // Without the nonce: refused, nothing sent.
        $count = count($this->requests);
        $request = new WP_REST_Request('POST', '/manfaa/v1/lookup');
        $request->set_body_params(['code' => '482917']);
        self::assertSame(403, rest_get_server()->dispatch($request)->get_status());
        self::assertCount($count, $this->requests);
    }

    public function test_the_lookup_is_throttled_per_session(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->answer(200, ['ref' => '482917', 'valid' => true, 'name' => 'A B']);
        }

        $answers = [];

        for ($i = 0; $i < 10; $i++) {
            $request = new WP_REST_Request('POST', '/manfaa/v1/lookup');
            $request->set_header('x-manfaa-nonce', wp_create_nonce('manfaa_lookup'));
            $request->set_body_params(['code' => '482917']);
            $answers[] = rest_get_server()->dispatch($request)->get_data()['valid'];
        }

        self::assertCount(8, $this->requests);
        self::assertNull($answers[9]);
    }

    public function test_the_code_is_stamped_on_the_order_at_creation(): void
    {
        Session::set('482917');
        $order = wc_create_order();
        do_action('woocommerce_checkout_create_order', $order, []);
        $order->save();

        self::assertSame('482917', Meta::get(wc_get_order($order->get_id()), Meta::CODE));
    }
}
