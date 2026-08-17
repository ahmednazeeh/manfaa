<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile app release gates
    |--------------------------------------------------------------------------
    |
    | `minimum_build` is the oldest build the API will keep serving. A client
    | below it should refuse to continue and send the user to the store; the
    | apps read this from GET /api/mobile/v1/config on launch.
    |
    | It exists because an installed app cannot be recalled. When a defect
    | ships — a credit posted with the wrong rate, a token stored insecurely —
    | raising this number is the ONLY lever that stops the bad build talking
    | to the API, and it has to be in place before the first release rather
    | than added once a build is already in the field.
    |
    | These env values are the DEFAULTS. The admin panel can override every
    | one of them (App\Domain\Platform\AppReleaseConfig, stored in
    | platform_settings) — an override wins the moment it is saved, and the
    | env value answers until then. NOTE: this project must never run
    | `config:cache` (it bakes a stale .env), so these are read live.
    |
    */

    'customer' => [
        'ios' => [
            'minimum_build' => (int) env('MOBILE_CUSTOMER_IOS_MIN_BUILD', 1),
            'latest_build' => (int) env('MOBILE_CUSTOMER_IOS_BUILD', 1),
            'store_url' => env('MOBILE_CUSTOMER_IOS_URL', ''),
        ],
        'android' => [
            'minimum_build' => (int) env('MOBILE_CUSTOMER_ANDROID_MIN_BUILD', 1),
            'latest_build' => (int) env('MOBILE_CUSTOMER_ANDROID_BUILD', 1),
            'store_url' => env('MOBILE_CUSTOMER_ANDROID_URL', ''),
        ],
    ],

    'merchant' => [
        'ios' => [
            'minimum_build' => (int) env('MOBILE_MERCHANT_IOS_MIN_BUILD', 1),
            'latest_build' => (int) env('MOBILE_MERCHANT_IOS_BUILD', 1),
            'store_url' => env('MOBILE_MERCHANT_IOS_URL', ''),
        ],
        'android' => [
            'minimum_build' => (int) env('MOBILE_MERCHANT_ANDROID_MIN_BUILD', 1),
            'latest_build' => (int) env('MOBILE_MERCHANT_ANDROID_BUILD', 1),
            'store_url' => env('MOBILE_MERCHANT_ANDROID_URL', ''),
        ],
    ],

];
