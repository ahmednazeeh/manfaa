<?php

declare(strict_types=1);

namespace App\Http\Controllers\Devices;

use App\Domain\Mobile\MobileAudience;

/**
 * The merchant app's signed-in devices, for the staff member's OWN account.
 *
 * Scoped to self deliberately. An owner who needs to cut off a departed
 * cashier deactivates the account — StaffService revokes their tokens as
 * part of that, which is the same act with an audit trail, rather than a
 * separate screen for reaching into another person's devices.
 */
final class MerchantDevicesController extends DevicesController
{
    protected function audience(): MobileAudience
    {
        return MobileAudience::Merchant;
    }
}
