<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // [DEVIATION D-05] The eight public pages render in Blade, not Inertia.
        // They are registered against the 'public' middleware group below, which
        // deliberately omits HandleInertiaRequests.
        then: function (): void {
            Route::middleware('public')
                ->group(base_path('routes/public.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // [DEVIATION D-05] The public middleware group is the 'web' stack minus
        // Inertia. Session and CSRF are retained because the contact form posts
        // server-side (FR-PUB-01). Omitting HandleInertiaRequests is what makes
        // AC-PUB-01 structural: a public response has no shared Inertia props to
        // leak a tenant name, balance or lease detail, so the guarantee does not
        // depend on remembering to scrub them.
        $middleware->group('public', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
