<?php

declare(strict_types=1);

namespace App\Http\Controllers\Devices;

use App\Domain\Mobile\MobileAudience;

/** The customer app's signed-in devices. Reachable from the website too. */
final class CustomerDevicesController extends DevicesController
{
    protected function audience(): MobileAudience
    {
        return MobileAudience::Customer;
    }
}
