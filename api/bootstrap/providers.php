<?php

use App\Domain\Customers\CustomersServiceProvider;
use App\Domain\MerchantSettings\MerchantSettingsServiceProvider;
use App\Domain\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    CustomersServiceProvider::class,
    MerchantSettingsServiceProvider::class,
    PlatformServiceProvider::class,
];
