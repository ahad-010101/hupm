<?php

namespace App\Http\Middleware;

use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to one or more roles.  [TDD §5.1, AC-AUTH-10]
 *
 * Wrong role returns **403** — the route exists and the user simply may not use
 * it, and saying so is not a disclosure.
 *
 * This is the opposite of an ownership failure, which returns 404 (I-9): there,
 * confirming the record exists would tell tenant A that tenant B is a customer.
 * The two must not be conflated, which is why they live in different classes.
 *
 * Usage: ->middleware('role:admin') or ->middleware('role:admin,owner')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! in_array($user->role, $roles, true)) {
            app(AuditLogger::class)->record('auth.role.denied', $user, [
                'required' => $roles,
                'actual' => $user->role,
                'path' => $request->path(),
            ]);

            abort(403, 'You do not have access to this page.');
        }

        return $next($request);
    }
}
