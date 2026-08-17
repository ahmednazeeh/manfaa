<?php

declare(strict_types=1);

namespace App\Http\Controllers\Devices;

use App\Domain\Mobile\MobileAudience;

/** Push registration for the customer app. */
final class CustomerPushTokenController extends PushTokenController
{
    protected function audience(): MobileAudience
    {
        return MobileAudience::Customer;
    }
}
