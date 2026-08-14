<?php

declare(strict_types=1);

namespace App\Domain\MerchantSettings;

use App\Models\MerchantUser;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Mirrors the admin-side pattern (PlatformServiceProvider): a deactivated
 * merchant user's live session dies on their next request — session user
 * resolution fires Authenticated, and an inactive user is logged out on
 * the spot (login itself is refused in MerchantAuthController).
 */
final class MerchantSettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(Authenticated::class, function (Authenticated $event): void {
            // Strict === false: before the is_active migration lands the
            // attribute reads null, which must never log anyone out.
            if ($event->guard === 'merchant' && $event->user instanceof MerchantUser && $event->user->is_active === false) {
                Auth::guard('merchant')->logout();
            }
        });
    }
}
