<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureNotBlocked;
use App\Services\IntroService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'not_blocked' => EnsureNotBlocked::class,
        ]);

        // The intro "already played" flag is written by intro.js as a plain
        // session cookie so the browser and the server read the same value.
        $middleware->encryptCookies(except: [
            IntroService::COOKIE_NAME,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
