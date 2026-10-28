<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureNotBlocked;
use App\Http\Middleware\EnsureStoreIsOpen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'admin.permission' => EnsureAdminPermission::class,
            'not_blocked' => EnsureNotBlocked::class,
        ]);

        // The store's maintenance switch. It is a global middleware so
        // it runs once for every request, and it lets the back office
        // through — the person who turned the switch on has to be able
        // to turn it off again.
        $middleware->append(EnsureStoreIsOpen::class);

        // The admin check has to happen BEFORE the route parameters are
        // bound. Route-model binding lives in SubstituteBindings, which
        // the framework runs late in the stack; a bound model that does
        // not exist raises a 404 on the spot. With the check running
        // after it, a guest could tell a real row from a made-up id by
        // the answer alone — a 302 for one and a 404 for the other.
        //
        // Moving EnsureAdmin just ahead of SubstituteBindings in the
        // priority list keeps the session available (it is started
        // earlier in the same list) while making every admin route
        // answer a guest the same way.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            EnsureAdmin::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
