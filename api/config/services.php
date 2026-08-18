<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'discovery' => [
        // Shared secret the Next SSR origin presents (X-Discovery-Internal
        // header) to bypass the public per-IP discovery throttle — every SSR
        // render leaves one server IP, so without this the whole storefront
        // shares a single 60/min bucket. Empty disables the bypass entirely.
        'internal_token' => env('DISCOVERY_INTERNAL_TOKEN', ''),
    ],

    'msgowl' => [
        'key' => env('MSGOWL_API_KEY', ''),
        'sender_id' => env('MSGOWL_SENDER_ID', ''),
        'base_url' => env('MSGOWL_BASE_URL', 'https://rest.msgowl.com'),
        'timeout' => env('MSGOWL_TIMEOUT_SECONDS', 15),
    ],

    /*
     * The bank transfer API (owner spec 2026-08-19). The URL, profile and
     * debited account are admin-editable rows; only the KEY lives here,
     * because `x-api-key` is the whole of the upstream's authentication and
     * a secret in a table an admin session can read is a leaked bank.
     */
    'transfer' => [
        'api_key' => env('TETHERX_TRANSFER_API_KEY'),
    ],

];
