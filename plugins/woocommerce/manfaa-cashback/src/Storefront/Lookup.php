<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Storefront;

use Manfaa\Cashback\Api\ApiException;
use Manfaa\Cashback\Api\Client;
use Manfaa\Cashback\Api\Connect;
use Manfaa\Cashback\Support\Options;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Live confirmation of the code as the buyer completes it: a tick and the
 * account holder's first name, through this plugin's own REST route so the
 * merchant's token never reaches the browser.
 *
 * Advisory only — it NEVER blocks checkout. Throttled per session because
 * lookups share the token's request budget and the store's daily
 * failed-lookup allowance (see the guide, §4.4): a buyer confirming one
 * code never approaches it; a script walking codes would.
 */
final class Lookup
{
    public const ROUTE = 'manfaa/v1';

    private const PER_WINDOW = 8;
    private const WINDOW = 10 * MINUTE_IN_SECONDS;

    public static function enabled(): bool
    {
        return Options::bool('confirm_code_live')
            && Connect::hasAbility('customers:lookup')
            && Client::fromSettings()->connected();
    }

    public static function register(): void
    {
        register_rest_route(self::ROUTE, '/lookup', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            // Public by design (guests check out too); the nonce ties the
            // call to a page we rendered, and the session throttle bounds it.
            'permission_callback' => '__return_true',
            'args' => ['code' => ['required' => true, 'type' => 'string']],
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $nonce = (string) $request->get_header('x-manfaa-nonce');

        if (! wp_verify_nonce($nonce, 'manfaa_lookup')) {
            return new WP_REST_Response(['valid' => null, 'message' => __('Please reload the page and try again.', 'manfaa-cashback')], 403);
        }

        $code = Session::clean((string) $request->get_param('code'));

        if ($code === '') {
            return new WP_REST_Response(['valid' => null, 'message' => __('Enter the 6-digit code from your Manfaa app.', 'manfaa-cashback')], 200);
        }

        // The code is stored whether or not the lookup is on or succeeds —
        // the lookup is a courtesy, the code is the point.
        Session::set($code);

        if (! self::enabled()) {
            return new WP_REST_Response(['valid' => null, 'code' => $code, 'message' => ''], 200);
        }

        if (! self::allow()) {
            return new WP_REST_Response(['valid' => null, 'code' => $code, 'message' => __("We'll check this code when your order is placed.", 'manfaa-cashback')], 200);
        }

        try {
            $answer = Client::fromSettings()->get('v1/customers/lookup', ['ref' => $code]);
        } catch (ApiException $e) {
            if ($e->status === 404) {
                return new WP_REST_Response(['valid' => false, 'code' => $code, 'unknown' => true, 'message' => __('No Manfaa account has this code. Check the digits in your app.', 'manfaa-cashback')], 200);
            }

            return new WP_REST_Response(['valid' => null, 'code' => $code, 'message' => __("We'll check this code when your order is placed.", 'manfaa-cashback')], 200);
        }

        $name = trim((string) ($answer['name'] ?? ''));
        $first = $name === '' ? '' : explode(' ', $name)[0];

        if (empty($answer['valid'])) {
            return new WP_REST_Response(['valid' => false, 'code' => $code, 'first_name' => $first, 'message' => __('This Manfaa account cannot earn cashback right now.', 'manfaa-cashback')], 200);
        }

        return new WP_REST_Response(['valid' => true, 'code' => $code, 'first_name' => $first, 'message' => $first === '' ? __('Manfaa code confirmed.', 'manfaa-cashback') : sprintf(
            /* translators: %s: first name */
            __('Cashback will go to %s.', 'manfaa-cashback'),
            $first,
        )], 200);
    }

    private static function allow(): bool
    {
        $session = function_exists('WC') ? WC()->session : null;

        if (! $session) {
            return true;
        }

        $window = (array) $session->get('manfaa_lookups', []);
        $now = time();
        $window = array_values(array_filter($window, fn ($t) => is_int($t) && $t > $now - self::WINDOW));

        if (count($window) >= self::PER_WINDOW) {
            return false;
        }

        $window[] = $now;
        $session->set('manfaa_lookups', $window);

        return true;
    }
}
