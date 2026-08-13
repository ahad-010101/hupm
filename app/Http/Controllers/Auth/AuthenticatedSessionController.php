<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login and logout.  [FR-AUTH-01]
 */
class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request, AuditLogger $audit): RedirectResponse
    {
        $request->authenticate();

        // AC-AUTH-04: the pre-login session ID must not survive. Without this,
        // an attacker who plants a session cookie before login holds a valid
        // authenticated session afterwards — session fixation.
        $request->session()->regenerate();

        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();

        $audit->record('auth.login.success', $user);

        // By role, not to a single dashboard (FR-AUTH-01 step 5).
        return redirect()->intended($user->homeRoute());
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->record('auth.logout', $request->user());

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        // Regenerating the CSRF token too, so a token captured while logged in
        // cannot be replayed against the next session (TDD §4).
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
