<?php

namespace App\Http\Middleware;

use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Absolute session lifetime.  [FR-AUTH-04, TDD §4]
 *
 * Laravel's `session.lifetime` is an *idle* timeout — it slides forward on every
 * request, so an active session never ends. The spec also requires a hard
 * ceiling of 12 hours regardless of activity, which nothing in the framework
 * provides.
 *
 * This matters on shared machines. A tenant using a library computer, or a
 * manager on an office desktop, should not have a session that stays valid
 * indefinitely because a tab kept polling.
 */
class EnforceAbsoluteSessionLifetime
{
    private const SESSION_KEY = 'auth.started_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $maxSeconds = (int) config('session.absolute_lifetime', 720) * 60;
        $startedAt = $request->session()->get(self::SESSION_KEY);

        if ($startedAt === null) {
            // First authenticated request of this session.
            $request->session()->put(self::SESSION_KEY, now()->timestamp);

            return $next($request);
        }

        if (now()->timestamp - (int) $startedAt < $maxSeconds) {
            return $next($request);
        }

        app(AuditLogger::class)->record('auth.session.expired_absolute', $request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // AC-AUTH-08: redirect to login with an explanation and no data in the
        // response. The controller never runs, so nothing was loaded to leak.
        return redirect()->route('login')->with(
            'status',
            'Your session reached its maximum length and was ended for security. Please sign in again.',
        );
    }
}
