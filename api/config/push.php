<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Push delivery
    |--------------------------------------------------------------------------
    |
    | `log` is the default ON PURPOSE. Everything around push — registration,
    | revocation, language, the moments themselves — ships and is tested
    | without a Firebase project existing, and an unconfigured platform
    | degrades to a log line rather than to an exception beside the money
    | path.
    |
    | Switch to `fcm` once the service account exists. The private key is a
    | credential: it belongs in the environment, never in the repository.
    | Multi-line keys go in .env with literal \n escapes, which is why it is
    | passed through str_replace below.
    |
    */

    'driver' => env('PUSH_DRIVER', 'log'),

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID', ''),
        'client_email' => env('FCM_CLIENT_EMAIL', ''),
        'private_key' => str_replace('\n', "\n", (string) env('FCM_PRIVATE_KEY', '')),
        'token_uri' => env('FCM_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
    ],

];
