<?php

namespace App\Providers;

use App\Domain\Customers\ClaudeDhivehiNameWriter;
use App\Domain\Customers\DhivehiNameWriter;
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

        // Writing a customer's name in Thaana. Bound unconditionally, unlike
        // the senders below: the writer itself decides what an absent key
        // means (it returns null), so there is no second driver to pick.
        $this->app->singleton(
            DhivehiNameWriter::class,
            fn (): DhivehiNameWriter => new ClaudeDhivehiNameWriter(
                ($key = (string) config('services.anthropic.api_key')) === '' ? null : $key,
                (string) config('services.anthropic.model'),
            ),
        );

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
        /*
         * The public map-tile proxy. 600/min per IP was far too generous
         * for a route that costs us THREE upstream fetches on a miss
         * against a twenty-worker pool — one address could saturate it.
         * A map pans at a few dozen tiles; 120 a minute is a comfortable
         * ceiling for a human and a hard floor for a script.
         */
        RateLimiter::for('map-tiles', function (Request $request): Limit {
            return Limit::perMinute(120)->by((string) $request->ip());
        });

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

        // The mobile customer lookup (merchant app MR2) — the web mounts it
        // at inline throttle:30,1 behind a session; here the same 30/min per
        // user under the named class-discriminated key, for the same
        // cross-audience id-collision reason as above. Separate bucket from
        // the web's by construction; the per-merchant daily MISS budget is
        // the controller's own and spans both surfaces on purpose.
        RateLimiter::for('mobile-lookup', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(30)->by(
                $user instanceof Authenticatable
                    ? $user::class.':'.$user->getAuthIdentifier()
                    : 'ip:'.$request->ip(),
            );
        });

        /*
         * Sign-in, per ACCOUNT as well as per IP (security audit
         * 2026-08-19).
         *
         * Every web login carried only `throttle:5,1`, which is per IP. A
         * password spray from a botnet gets five attempts PER ADDRESS
         * against one account and is never slowed by the account itself —
         * and the mobile app's per-account lockout was bypassed simply by
         * posting to the web door instead.
         *
         * Two limits, deliberately: the address stops one host hammering,
         * and the identifier stops many hosts hammering one person. The
         * identifier is hashed so an email never lands in a cache key.
         */
        RateLimiter::for('login', function (Request $request): array {
            $identifier = mb_strtolower(trim(
                (string) ($request->input('email') ?? $request->input('phone') ?? ''),
            ));

            return [
                Limit::perMinute(5)->by('login-ip:'.$request->ip()),
                Limit::perMinutes(15, 10)->by(
                    'login-id:'.($identifier === '' ? $request->ip() : sha1($identifier)),
                ),
            ];
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
