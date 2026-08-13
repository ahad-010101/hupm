<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\InvitationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting a password from an invitation link.  [FR-AUTH-02, AC-AUTH-05]
 *
 * The route carries the `signed` middleware, so an expired or tampered link is
 * rejected before this controller runs. What is left to check here is reuse: the
 * state token stops matching once a password exists.
 *
 * Either way the outcome is the same and deliberate — an expiry notice with an
 * offer to resend, and **no password set**.
 */
class SetPasswordController extends Controller
{
    public function create(Request $request, User $user, string $token): Response
    {
        return Inertia::render('Auth/SetPassword', [
            'valid' => $this->linkIsUsable($user, $token),
            'email' => $user->email,
            'userId' => $user->id,
            'token' => $token,
        ]);
    }

    public function store(Request $request, User $user, string $token, AuditLogger $audit): RedirectResponse
    {
        if (! $this->linkIsUsable($user, $token)) {
            $audit->record('auth.password.set_rejected', $user, ['reason' => 'link already used or account not invited']);

            // No password is set, and the message says what to do next.
            return back()->withErrors([
                'password' => 'This link has already been used or has expired. Request a new one below.',
            ]);
        }

        $request->validate([
            // Password::defaults() is min 12 + uncompromised, set once in
            // AppServiceProvider so every password path shares one policy.
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($request->string('password')),
            'status' => User::STATUS_ACTIVE,
            // The link proved control of the mailbox, so verification is
            // implicit (TDD §4) — there is no separate confirmation step.
            'email_verified_at' => now(),
        ])->save();

        $audit->record('auth.password.set', $user);

        return redirect()->route('login')->with(
            'status',
            'Your password is set. You can sign in now.',
        );
    }

    /** Resend after an expired link, without disclosing whether the account exists. */
    public function resend(Request $request, User $user, InvitationService $invitations): RedirectResponse
    {
        if ($user->status === User::STATUS_INVITED) {
            $invitations->sendSetPasswordLink($user);
        }

        return back()->with(
            'status',
            'If that account is waiting for a password, a new link is on its way.',
        );
    }

    private function linkIsUsable(User $user, string $token): bool
    {
        return $user->status === User::STATUS_INVITED
            && hash_equals(InvitationService::stateToken($user), $token);
    }
}
