<?php

namespace App\Providers;

use App\Domain\Customers\MsgOwlSmsSender;
use App\Domain\Customers\SmsSender;
use App\Domain\Push\FcmPushSender;
use App\Domain\Push\PushSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
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

        // Push provider selection, same stance: FCM only when a service
        // account is actually configured, otherwise the interface's #[Bind]
        // default (LogPushSender) stands. An unconfigured platform must
        // degrade to a log line, never to an exception beside the money path.
        if (config('push.driver') === 'fcm' && (string) config('push.fcm.project_id') !== '') {
            $this->app->singleton(PushSender::class, fn (): FcmPushSender => new FcmPushSender(
                (string) config('push.fcm.project_id'),
                (string) config('push.fcm.client_email'),
                (string) config('push.fcm.private_key'),
                (string) config('push.fcm.token_uri'),
            ));
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
        /*
         * The till's credit endpoint.
         *
         * NAMED rather than inline `throttle:60,1` because the inline form
         * keys on sha1($user->getAuthIdentifier()) with NO model
         * discriminator, while ThrottleRequests sorts above
         * EnsureMobileToken in the middleware priority. A customer token
         * spending sixty rejected requests against the merchant credit route
         * would therefore fill the bucket belonging to the merchant USER who
         * happens to share its numeric id — locking a cashier at an
         * unrelated store out of their till. Keying on class + id removes
         * the collision whatever the ordering does.
         *
         * A Merchant (POS vendor) principal is not Authenticatable and would
         * throw on getAuthIdentifier(), so it falls to the address instead
         * of 500-ing; it cannot reach this route anyway.
         */
        RateLimiter::for('mobile-credits', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(60)->by(
                $user instanceof Authenticatable
                    ? $user::class.':'.$user->getAuthIdentifier()
                    : 'ip:'.$request->ip(),
            );
        });

        // The mobile setup wizard's POST routes (logo, submit) — the web
        // mounts these at throttle:20,1 behind a session; on the mobile tree
        // the inline form would key on the bare numeric id and collide
        // across audiences exactly as described above, so the same named
        // class-discriminated key at the same 20/min.
        RateLimiter::for('mobile-setup-writes', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(20)->by(
                $user instanceof Authenticatable
                    ? $user::class.':'.$user->getAuthIdentifier()
                    : 'ip:'.$request->ip(),
            );
        });

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
