<?php

use App\Http\Middleware\EnsureMerchantPermission;
use App\Http\Middleware\EnsureMerchantRole;
use App\Http\Middleware\EnsureMobileToken;
use App\Http\Responses\MobileError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum stateful SPA auth: first-party panels and the customer web
        // app authenticate over session cookies against the api routes.
        $middleware->statefulApi();

        // The merchant panel's permission gate, parameterised by the one
        // permission a route needs: merchant.can:settlements.create (PLAN
        // §13b staff permissions).
        //
        // `merchant.role` stays registered on purpose. It is retired, and
        // EnsureMerchantRole now throws — but an alias Laravel cannot
        // resolve is a worse failure than one that refuses loudly, and a
        // route file the refactor missed must never end up ungated.
        $middleware->alias([
            'merchant.can' => EnsureMerchantPermission::class,
            'merchant.role' => EnsureMerchantRole::class,

            // The mobile API's token-type gate, parameterised by audience:
            // mobile.token:customer / mobile.token:merchant. Sanctum resolves
            // a bearer token to ANY tokenable that uses HasApiTokens — all
            // four models do — so this is what proves which app is calling.
            // See EnsureMobileToken; no /api/mobile route may skip it.
            'mobile.token' => EnsureMobileToken::class,
        ]);

        // Origin sits behind nginx (same host) with Cloudflare in front;
        // trust the proxy so X-Forwarded-Proto marks requests secure and
        // secure cookies survive the fastcgi hop.
        //
        // X-Forwarded-FOR is deliberately EXCLUDED from that trust. nginx
        // already resolves the true client address correctly — it accepts
        // real-ip only from the Cloudflare ranges and reads CF-Connecting-IP
        // (/etc/nginx/snippets/cloudflare-realip.conf) — so REMOTE_ADDR
        // reaching PHP is right. Trusting X-Forwarded-For threw that away:
        // Symfony treats every hop as trusted and returns the LEFTMOST
        // entry, which is whatever the caller typed. `$request->ip()` was
        // therefore attacker-controlled, and since ThrottleRequests keys
        // unauthenticated limits on exactly that, rotating one header bought
        // a fresh rate-limit bucket on every request to every login on the
        // platform. Dropping the header from the trust list makes ip() fall
        // back to nginx's REMOTE_ADDR, which a client cannot forge.
        //
        // Proto/host/port stay trusted, which is the whole reason the
        // original '*' was here.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        // API-only app: never redirect guests to a (nonexistent) login page —
        // an unauthenticated request without an Accept header would otherwise
        // 500 on route('login'). Null → AuthenticationException → JSON 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The mobile tree — and ONLY the mobile tree — answers in the
        // {error:{code,message,meta}} envelope (PLAN-mobile-api.md M2).
        //
        // Scoped by path on purpose. The panels deploy in lockstep with their
        // API and are happy with Laravel's stock shapes; the /v1 vendor
        // contract is published in docs/openapi.yaml and POS vendors parse it
        // today. Neither may change shape because the apps wanted one parser.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/mobile/*')) {
                return null;
            }

            return MobileError::fromException($e);
        });
    })->create();
