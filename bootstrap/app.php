<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrackLastSeen;
use App\Tenancy\EnsureUserBelongsToTenant;
use App\Tenancy\IdentifyTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The tenant must be known before the session resolves the user.
        $middleware->web(prepend: [IdentifyTenant::class]);
        $middleware->web(append: [
            EnsureUserBelongsToTenant::class,
            HandleInertiaRequests::class,
            TrackLastSeen::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
