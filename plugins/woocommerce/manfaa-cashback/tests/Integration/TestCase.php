<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Tests\Integration;

use Manfaa\Cashback\Api\Client;
use Manfaa\Cashback\Api\Connect;
use Manfaa\Cashback\Api\RateCard;
use Manfaa\Cashback\Support\Options;
use WC_Order;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * Shared fixtures: a connected plugin, a synced rate card, and an HTTP
 * capture so every request the plugin makes can be asserted byte by byte
 * and answered with a canned Manfaa response.
 */
abstract class TestCase extends WP_UnitTestCase
{
    /** @var list<array{method:string,url:string,headers:array,body:?string}> */
    protected array $requests = [];

    /** @var list<array{status:int,body:array,headers?:array}> */
    protected array $answers = [];

    public function set_up(): void
    {
        parent::set_up();

        $this->requests = [];
        $this->answers = [];
        Options::flush();
        update_option('woocommerce_currency', 'MVR');
        update_option('manfaa_cashback_activated_at', 1);
        delete_option('manfaa_cashback_disconnected');
        Client::storeToken('12|testtoken');
        update_option(Connect::PROFILE_OPTION, ['merchant_id' => 1, 'merchant_name' => 'Test Shop', 'abilities' => Connect::SCOPES, 'connected_from' => null]);

        $this->card([
            ['slug' => 'fruits', 'name_en' => 'Fruits', 'name_dv' => null, 'mode' => 'excluded', 'rate_bp' => 0, 'position' => 0, 'active' => true],
            ['slug' => 'veggies', 'name_en' => 'Veggies', 'name_dv' => null, 'mode' => 'rate', 'rate_bp' => 200, 'position' => 1, 'active' => true],
        ]);

        add_filter('pre_http_request', [$this, 'captureHttp'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'captureHttp'], 10);
        parent::tear_down();
    }

    protected function card(array $categories = [], int $rateBp = 500, int $min = 5000): void
    {
        $card = new RateCard($rateBp, $min, $categories !== [], $categories, time());
        set_transient(RateCard::TRANSIENT, $card->toArray(), HOUR_IN_SECONDS);
        update_option('manfaa_cashback_rate_card', $card->toArray(), false);
    }

    /** @param array<string, mixed> $body */
    protected function answer(int $status, array $body, array $headers = []): void
    {
        $this->answers[] = ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    public function captureHttp(mixed $pre, array $args, string $url): mixed
    {
        $this->requests[] = [
            'method' => $args['method'] ?? 'GET',
            'url' => $url,
            'headers' => $args['headers'] ?? [],
            'body' => isset($args['body']) ? (string) $args['body'] : null,
        ];

        $answer = array_shift($this->answers) ?? ['status' => 500, 'body' => ['error' => ['code' => 'unanswered', 'message' => 'No canned answer for '.$url]]];

        return [
            'response' => ['code' => $answer['status'], 'message' => ''],
            'headers' => $answer['headers'] ?? [],
            'body' => wp_json_encode($answer['body']),
            'cookies' => [],
            'filename' => null,
        ];
    }

    protected function lastRequest(): array
    {
        return $this->requests[count($this->requests) - 1];
    }

    protected function product(string $name, float $price, array $categoryIds = []): WC_Product_Simple
    {
        $product = new WC_Product_Simple;
        $product->set_name($name);
        $product->set_regular_price((string) $price);
        $product->set_category_ids($categoryIds);
        $product->save();

        return $product;
    }

    /** @param list<array{0:WC_Product_Simple,1:int}> $lines */
    protected function order(array $lines, ?string $code = '482917', string $status = 'processing'): WC_Order
    {
        $order = wc_create_order(['status' => $status]);

        foreach ($lines as [$product, $qty]) {
            $order->add_product($product, $qty);
        }

        $order->set_currency('MVR');
        $order->set_billing_phone('7712345');
        $order->calculate_totals(wc_tax_enabled());

        if ($code !== null) {
            $order->update_meta_data('_manfaa_code', $code);
        }

        $order->save();

        return wc_get_order($order->get_id());
    }

    protected function term(string $name): int
    {
        $term = wp_insert_term($name, 'product_cat');

        return (int) $term['term_id'];
    }

    /** Run Action Scheduler's pending jobs for our group synchronously. */
    protected function runJobs(): void
    {
        $store = \ActionScheduler::store();

        foreach ($store->query_actions(['group' => 'manfaa', 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50]) as $id) {
            $action = $store->fetch_action($id);

            if ($action->get_hook() === \Manfaa\Cashback\Orders\Sweep::HOOK) {
                continue; // the daily sweep is exercised by its own test
            }

            $store->mark_complete($id);
            do_action_ref_array($action->get_hook(), $action->get_args());
        }
    }

    /** Hooks bound at boot read options then; rebind after changing post_on_status. */
    protected function reboot(): void
    {
        \Manfaa\Cashback\Plugin::instance()->boot();
    }

    protected function pendingJobs(string $hook): int
    {
        return count(\ActionScheduler::store()->query_actions(['hook' => $hook, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50]));
    }
}
