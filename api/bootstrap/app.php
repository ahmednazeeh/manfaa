<?php

use App\Http\Middleware\EnsureMerchantPermission;
use App\Http\Middleware\EnsureMerchantRole;
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
        ]);

        // Origin sits behind nginx (same host) with Cloudflare in front;
        // trust the local proxy so X-Forwarded-Proto marks requests secure
        // and secure cookies survive the fastcgi hop.
        $middleware->trustProxies(at: '*');

        // API-only app: never redirect guests to a (nonexistent) login page —
        // an unauthenticated request without an Accept header would otherwise
        // 500 on route('login'). Null → AuthenticationException → JSON 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
