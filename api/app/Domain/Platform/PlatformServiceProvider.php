<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Models\AdminUser;
use App\Models\Merchant;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the platform settings domain into the running app:
 *
 * - New merchants inherit validation_window_days / min_eligible_laari from
 *   PlatformConfig when the caller did not set them — replacing the static
 *   column defaults with the admin-managed ones. An explicitly supplied
 *   value always wins.
 * - A deactivated admin's live session dies on their next request: session
 *   user resolution fires Authenticated, and an inactive admin is logged
 *   out on the spot (login itself is refused in AdminAuthController).
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Merchant::creating(function (Merchant $merchant): void {
            $config = app(PlatformConfig::class);
            $attributes = $merchant->getAttributes();

            if (! array_key_exists('validation_window_days', $attributes)) {
                $merchant->validation_window_days = $config->defaultValidationWindowDays();
            }

            if (! array_key_exists('min_eligible_laari', $attributes)) {
                $merchant->min_eligible_laari = $config->defaultMinEligibleLaari();
            }
        });

        Event::listen(Authenticated::class, function (Authenticated $event): void {
            // Strict === false: before the is_active migration lands the
            // attribute reads null, which must never log anyone out.
            if ($event->guard === 'admin' && $event->user instanceof AdminUser && $event->user->is_active === false) {
                Auth::guard('admin')->logout();
            }
        });
    }
}
