<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Tests\Integration;

use Manfaa\Cashback\Admin\Settings;
use Manfaa\Cashback\Api\Client;
use Manfaa\Cashback\Api\Connect;
use Manfaa\Cashback\Api\ConnectException;
use Manfaa\Cashback\Api\RateCard;
use Manfaa\Cashback\Support\Crypto;
use Manfaa\Cashback\Support\Options;
use Manfaa\Cashback\Webhooks\Receiver;

final class ConnectTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        Connect::disconnect();
        $this->requests = [];
        $this->answers = [];
    }

    public function test_connect_with_manfaa_end_to_end(): void
    {
        $user = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user);

        $url = Connect::beginUrl($user);
        self::assertStringStartsWith('https://merchant.manfaa.app/connect?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(Options::string('client_id'), $query['client_id']);
        self::assertSame(Connect::callbackUrl(), $query['redirect_uri']);
        self::assertSame(implode(' ', Connect::SCOPES), $query['scope']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code_challenge']);

        // Manfaa sends the browser back; the plugin exchanges (no secret),
        // reads /v1/me, syncs the rate card and registers its webhook.
        $this->answer(201, ['access_token' => '12|newtoken', 'token_type' => 'Bearer', 'scope' => implode(' ', Connect::SCOPES), 'merchant' => ['id' => 7, 'name' => 'Tea Plus']]);
        $this->answer(200, ['merchant' => ['id' => 7, 'name' => 'Tea Plus', 'slug' => 'tea-plus', 'status' => 'active', 'currency' => 'MVR'], 'credential' => ['id' => 41, 'label' => 'Manfaa for WooCommerce', 'abilities' => Connect::SCOPES, 'connected_from' => 'https://shop.example.mv'], 'rate' => null]);
        $this->answer(200, ['cashback_rate_percent' => '2.00', 'min_eligible_laari' => 5000, 'has_category_overrides' => true]);
        $this->answer(200, ['data' => [['slug' => 'veggies', 'name_en' => 'Veggies', 'name_dv' => 'ތަރުކާރީ', 'mode' => 'rate', 'cashback_rate_percent' => '2.00']]]);
        $this->answer(201, ['secret' => 'whsec_abc', 'endpoint' => ['id' => 9]]);

        $profile = Connect::complete('the-code', $query['state'], $user);

        $exchange = $this->requests[0];
        self::assertStringEndsWith('/v1/connect/token', $exchange['url']);
        $body = json_decode((string) $exchange['body'], true);
        self::assertArrayNotHasKey('client_secret', $body);
        self::assertSame('authorization_code', $body['grant_type']);
        self::assertSame('the-code', $body['code']);
        self::assertSame(Connect::callbackUrl(), $body['redirect_uri']);
        // The verifier hashes to the challenge that was sent.
        self::assertSame($query['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $body['code_verifier'], true)), '+/', '-_'), '='));
        self::assertArrayNotHasKey('Authorization', $exchange['headers']);

        self::assertSame('12|newtoken', Client::storedToken());
        self::assertSame('Tea Plus', $profile['merchant_name']);
        self::assertSame('https://shop.example.mv', $profile['connected_from']);
        self::assertTrue(Connect::hasAbility('webhooks:manage'));
        self::assertSame(200, RateCard::cached()->rateBp);
        self::assertSame('veggies', RateCard::cached()->categories[0]['slug']);
        self::assertTrue(Receiver::registered());
        self::assertSame('Bearer 12|newtoken', $this->requests[1]['headers']['Authorization']);

        // The state is one-time.
        $this->expectException(ConnectException::class);
        Connect::complete('the-code', $query['state'], $user);
    }

    public function test_the_state_is_bound_to_the_user_who_started(): void
    {
        $url = Connect::beginUrl(5);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->expectException(ConnectException::class);
        Connect::complete('code', $query['state'], 6);
        self::assertCount(0, $this->requests);
    }

    public function test_a_pasted_token_is_proved_before_it_is_kept(): void
    {
        $this->answer(401, ['message' => 'Unauthenticated.']);

        try {
            Connect::adoptToken('12|bad');
            self::fail('expected refusal');
        } catch (\Manfaa\Cashback\Api\ApiException $e) {
            self::assertSame(401, $e->status);
        }

        self::assertNull(Client::storedToken());

        $this->answer(200, ['merchant' => ['id' => 7, 'name' => 'Tea Plus', 'status' => 'active'], 'credential' => ['abilities' => ['transactions:write'], 'connected_from' => null, 'label' => 'My shop'], 'rate' => null]);
        $profile = Connect::adoptToken(' 12|good ');

        self::assertSame('12|good', Client::storedToken());
        self::assertSame(['transactions:write'], $profile['abilities']);
        // Without rates:read / webhooks:manage nothing else was attempted.
        self::assertCount(2, $this->requests);
    }

    public function test_the_token_is_encrypted_at_rest_and_survives_a_key_change_as_a_mismatch(): void
    {
        Client::storeToken('12|secret');
        $stored = (string) get_option(Client::TOKEN_OPTION);
        self::assertStringNotContainsString('secret', $stored);
        self::assertSame('12|secret', Crypto::decrypt($stored));
        self::assertFalse(Crypto::keyMismatch($stored));

        // A ciphertext from another key is unreadable and reported as such.
        $foreign = 'deadbeef0000:'.base64_encode(random_bytes(60));
        self::assertNull(Crypto::decrypt($foreign));
        self::assertTrue(Crypto::keyMismatch($foreign));
    }

    public function test_settings_sanitise_to_the_closed_sets(): void
    {
        $clean = Settings::sanitize([
            'pricing_mode' => 'bogus',
            'awarding_policy' => 'items_inc_tax',
            'category_map' => ['veggies' => ['3', 'x', '5'], 'Bad Slug!' => ['1'], 'empty' => []],
            'post_on_status' => 'on-hold',
            'partial_refund_policy' => 'reverse_all',
            'invoice_prefix' => 'te-a#1',
            'confirm_code_live' => '1',
        ]);

        self::assertSame(Options::PRICING_GENERAL, $clean['pricing_mode']);
        self::assertSame(Options::POLICY_ITEMS_INC_TAX, $clean['awarding_policy']);
        self::assertSame(['veggies' => [3, 5], 'badslug' => [1]], $clean['category_map']);
        self::assertSame('completed', $clean['post_on_status']);
        self::assertSame(Options::PARTIAL_REVERSE_ALL, $clean['partial_refund_policy']);
        self::assertSame('TE-A1', $clean['invoice_prefix']);
        self::assertTrue($clean['confirm_code_live']);
        self::assertFalse($clean['phone_fallback']);
        self::assertArrayNotHasKey('on-hold', Settings::postableStatuses());
        self::assertArrayHasKey('processing', Settings::postableStatuses());
    }

    public function test_the_invoice_prefix_is_site_derived_when_blank(): void
    {
        Options::update(['invoice_prefix' => '']);
        self::assertMatchesRegularExpression('/^[A-F0-9]{6}-$/', Options::invoicePrefix());
        Options::update(['invoice_prefix' => 'SHOP']);
        self::assertSame('SHOP-', Options::invoicePrefix());
    }
}
