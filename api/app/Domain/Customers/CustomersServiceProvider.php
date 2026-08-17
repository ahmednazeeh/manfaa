<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Customer;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Mirrors the admin- and merchant-side pattern (PlatformServiceProvider,
 * MerchantSettingsServiceProvider): a suspended customer's live WEB session
 * dies on their next request — session user resolution fires Authenticated,
 * and a non-active customer is logged out on the spot (login itself is
 * refused in CustomerAuthController, and the mobile bearer path never gets
 * here: EnsureMobileToken checks mayUseMobileApp BEFORE setting the user,
 * and re-asks the guard after, precisely for listeners like this one).
 *
 * The predicate is the model's own mayUseMobileApp() — strictly
 * status === 'active', so `suspended`, `closed` and any status added later
 * all default to logged out rather than silently allowed.
 */
final class CustomersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(Authenticated::class, function (Authenticated $event): void {
            if ($event->guard === 'customer' && $event->user instanceof Customer && ! $event->user->mayUseMobileApp()) {
                Auth::guard('customer')->logout();
            }
        });
    }
}
