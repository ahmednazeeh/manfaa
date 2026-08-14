<?php

use App\Domain\MerchantSettings\MerchantSettingsServiceProvider;
use App\Domain\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    MerchantSettingsServiceProvider::class,
    PlatformServiceProvider::class,
];
