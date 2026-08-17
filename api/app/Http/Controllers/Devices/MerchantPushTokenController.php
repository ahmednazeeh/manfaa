<?php

declare(strict_types=1);

namespace App\Http\Controllers\Devices;

use App\Domain\Mobile\MobileAudience;

/** Push registration for the merchant app. */
final class MerchantPushTokenController extends PushTokenController
{
    protected function audience(): MobileAudience
    {
        return MobileAudience::Merchant;
    }
}
