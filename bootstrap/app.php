<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTenantFromRoute;
use App\Http\Middleware\TrackLastSeen;
use App\Http\Middleware\UseAdminGuard;
use App\Tenancy\EnsureUserBelongsToTenant;
use App\Tenancy\IdentifyTenant;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

$isAdminHost = fn (Request $request): bool => strtolower($request->getHost()) === strtolower((string) config('tenancy.admin_domain'));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function () {
            Route::get('/up', fn () => response('ok'))->name('health');

            // The super admin console is registered first so its host never
            // reaches IdentifyTenant, which would answer 404.
            Route::middleware('admin')
                ->domain((string) config('tenancy.admin_domain'))
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')->group(base_path('routes/web.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->withMiddleware(function (Middleware $middleware) use ($isAdminHost): void {
        // The tenant must be known before the session resolves the user.
        $middleware->web(prepend: [IdentifyTenant::class]);
        $middleware->web(append: [
            EnsureUserBelongsToTenant::class,
            HandleInertiaRequests::class,
            TrackLastSeen::class,
        ]);

        $middleware->group('admin', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            UseAdminGuard::class,
            SetTenantFromRoute::class,
            SubstituteBindings::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $isAdminHost($request) ? route('admin.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $isAdminHost($request) ? route('admin.tenants.index') : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
