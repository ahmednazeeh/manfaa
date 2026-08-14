<?php

namespace App\Providers;

use App\Domain\Customers\MsgOwlSmsSender;
use App\Domain\Customers\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SMS provider selection: MsgOwl whenever a key is configured,
        // otherwise the interface's #[Bind] default (LogSmsSender) stands.
        // Tests configure no key, so the log driver keeps covering them.
        if ((string) config('services.msgowl.key') !== '') {
            $this->app->bind(SmsSender::class, MsgOwlSmsSender::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Public discovery throttle: the standard 60/min per IP, except the
        // trusted Next SSR origin — all its storefront renders leave one
        // server IP, so a valid shared secret (X-Discovery-Internal) exempts
        // it; otherwise >60 distinct store pages a minute would 429 every
        // SSR fetch. Fails closed: an empty configured token never matches.
        RateLimiter::for('discovery', function (Request $request): Limit {
            $token = (string) config('services.discovery.internal_token');

            if ($token !== '' && hash_equals($token, (string) $request->header('X-Discovery-Internal', ''))) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by(
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        });
    }
}
