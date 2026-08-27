<?php

use App\Exceptions\ImmutableRecordException;
use App\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;

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

            // Webhooks carry no cookie and cannot supply a CSRF token; their
            // authenticity is the request signature. They get no middleware at
            // all rather than CSRF exemptions bolted onto the `web` group,
            // which would weaken every other route to accommodate two.
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         | TDD 6.3 security headers, on every response.  [WP-34]
         |
         | Global, not per-group. There are four ways out of this application --
         | the `web` group, the `public` group (D-05), the two webhook routes
         | which have no group at all, and the error responses rendered below.
         | A header attached to a group is missing from at least two of them.
        */
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Laravel's session.lifetime is an idle timeout that slides forward
            // on every request. FR-AUTH-04 also demands a hard 12-hour ceiling,
            // which nothing in the framework provides.
            EnforceAbsoluteSessionLifetime::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // [DEVIATION D-05] The public middleware group is the 'web' stack minus
        // Inertia. Session and CSRF are retained because the contact form posts
        // server-side (FR-PUB-01). Omitting HandleInertiaRequests is what makes
        // AC-PUB-01 structural: a public response has no shared Inertia props to
        // leak a tenant name, balance or lease detail, so the guarantee does not
        // depend on remembering to scrub them.
        $middleware->group('public', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // FS §18.3 error states.
        //
        // Error pages render in Blade for both pipelines. An Inertia error page
        // needs the JS bundle to have loaded, which is exactly what cannot be
        // relied on when something has already gone wrong — and the public site
        // has no bundle at all (D-05).

        // Ownership violations return 404, never 403. A 403 confirms the record
        // exists, which tells tenant A that tenant B is a customer here
        // (invariant I-9, BR-20). Policies throw ModelNotFound for this reason.
        $exceptions->dontReport(ImmutableRecordException::class);

        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if ($status === 419) {
                // Session expired. Say what happened and what to do — never a
                // bare "page expired".
                return redirect()->guest(route('login'))
                    ->with('status', 'Your session expired for security. Please sign in again to continue.');
            }

            if ($status === 500 && ! config('app.debug')) {
                // A reference the tenant can quote and an admin can grep for.
                // No stack trace reaches the browser.
                $reference = strtoupper(Str::random(8));

                Log::error('Unhandled exception', [
                    'reference' => $reference,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'url' => $request->fullUrl(),
                    'user_id' => $request->user()?->id,
                ]);

                return response()->view('errors.500', ['reference' => $reference], 500);
            }

            return $response;
        });
    })->create();
