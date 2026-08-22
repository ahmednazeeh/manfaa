<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Settlement payment slips (PLAN §1 receipt-first). PRIVATE by
        // construction: its own root outside storage/app/public, no `url`,
        // and `serve` off — there is no route, signed or otherwise, that
        // Laravel will generate for it. Slips reach a human only by streaming
        // through the admin-authenticated controller.
        'slips' => [
            'driver' => 'local',
            'root' => storage_path('app/slips'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // Merchant store logos. PRIVATE by construction for the same reason
        // as `slips`: no `url`, `serve` off, its own root outside
        // storage/app/public — so there is no path, signed or otherwise,
        // that reaches a logo file directly.
        //
        // A logo is public information ONLY while its store is (PLAN §1:
        // "the store is invisible publicly until approved"). That is a
        // question about the merchant row, not about the file, so every
        // logo — pre-approval and live alike — is answered by
        // MerchantLogoController, which reads the status and decides. See
        // App\Domain\Onboarding\MerchantLogo.
        // Platform brand marks uploaded by a superadmin. Private disk
        // served through a controller, like every other upload here: the
        // same url then works on every app host with no second nginx block.
        'brand' => [
            'driver' => 'local',
            'root' => storage_path('app/brand'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'logos' => [
            'driver' => 'local',
            'root' => storage_path('app/logos'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // Customer profile pictures. Same construction as `logos`: no `url`,
        // `serve` off, its own root outside storage/app/public — no path
        // reaches an avatar file directly. Serving goes through
        // CustomerAvatarController, whose URL carries the uuid filename as
        // an unguessable token (capability URL) so the customer's own
        // clients — web <img>, the app's plain image loader — can render it
        // without an auth header while strangers cannot enumerate it. See
        // App\Domain\Customers\CustomerAvatar.
        'avatars' => [
            'driver' => 'local',
            'root' => storage_path('app/avatars'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // Uploaded icons for the superadmin-curated store categories. These
        // ARE public — the category rail is the storefront's navigation —
        // but they are served through StoreCategoryIconController like the
        // logos above, so one route works on every app host and no second
        // nginx location block has to exist. See
        // App\Domain\Storefront\StoreCategoryIcon.
        'store-category-icons' => [
            'driver' => 'local',
            'root' => storage_path('app/store-category-icons'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // Featured-offer banner artwork. Public like the category icons
        // above and served the same way — see App\Domain\Storefront\OfferImage.
        'offer-images' => [
            'driver' => 'local',
            'root' => storage_path('app/offer-images'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
