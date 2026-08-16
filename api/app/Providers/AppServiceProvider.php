<?php

namespace App\Providers;

use App\Domain\Customers\MsgOwlSmsSender;
use App\Domain\Customers\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        /*
         * Refuse the commands that empty a database, whenever this is
         * production.
         *
         * This is not hypothetical caution. On 2026-08-16 a `migrate:fresh`
         * meant for the test database ran against the live one and destroyed
         * it, and ScorePath on the same host had gone the same way three
         * weeks earlier. The trap is that only `php artisan test` reads
         * .env.testing — every other artisan invocation uses the production
         * connection, so the safe-looking command and the catastrophic one
         * differ by nothing you can see at the prompt.
         *
         * Covers migrate:fresh, migrate:refresh, migrate:reset,
         * migrate:rollback, db:wipe and schema:dump. Rollback is in that list
         * on purpose: on live data it drops columns, and a migration is
         * re-tested against the test database, never against this one.
         *
         * Seeding is deliberately NOT prohibited — it is additive, and
         * DemoSeeder already refuses to run outside local/testing because its
         * credentials are public.
         */
        DB::prohibitDestructiveCommands($this->app->isProduction());

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
