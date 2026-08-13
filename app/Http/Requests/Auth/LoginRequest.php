<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login.  [FR-AUTH-01, AC-AUTH-02, AC-AUTH-03]
 *
 * Two departures from Breeze's default, both required by the spec:
 *
 *   - the throttle window is 15 minutes, not 60 seconds, and it returns
 *     HTTP 429 rather than a 422 validation error (AC-AUTH-03);
 *   - the failure message is identical in every case, so the response never
 *     discloses whether an address exists (AC-AUTH-02).
 */
class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 900; // 15 minutes

    /**
     * One message for every failure: wrong password, unknown address,
     * suspended account, or an account that has not set a password yet.
     *
     * The spec's A1 flow asks for "Check your email to set your password" when
     * the account is `invited`, while AC-AUTH-02 forbids disclosing whether an
     * address exists. Those conflict: showing that message only to invited
     * users confirms the account. Resolved by giving everyone both sentences —
     * the guidance reaches the person who needs it and tells an attacker
     * nothing.
     */
    private const FAILURE_MESSAGE = 'Those details do not match our records. '
        .'If you have not set your password yet, check your email for the set-up link.';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /** @throws ValidationException */
    public function authenticate(): void
    {
        // Before any password work: AC-AUTH-03 requires the 6th attempt not to
        // evaluate the password at all, so a throttled endpoint cannot be used
        // as a timing oracle.
        $this->ensureIsNotRateLimited();

        $user = User::where('email', $this->string('email')->lower())->first();

        // An `invited` user has a NULL password and a `suspended` one must be
        // refused even with the right password. Checking here rather than
        // relying on Auth::attempt keeps the reason out of the response.
        if (! $user || ! $user->canAuthenticate() || ! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            app(AuditLogger::class)->record('auth.login.failed', null, [
                'email' => (string) $this->string('email'),
                // Why it failed is recorded for an admin, never returned to the
                // browser.
                'reason' => match (true) {
                    ! $user => 'no such account',
                    $user->status !== User::STATUS_ACTIVE => "account {$user->status}",
                    $user->password === null => 'no password set',
                    default => 'bad password',
                },
            ]);

            throw ValidationException::withMessages(['email' => self::FAILURE_MESSAGE]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        app(AuditLogger::class)->record('auth.login.throttled', null, [
            'email' => (string) $this->string('email'),
        ]);

        $seconds = RateLimiter::availableIn($this->throttleKey());

        // 429, not a validation error. A brute-force client should see the
        // status code that means "stop", and monitoring should be able to count
        // it without parsing bodies.
        throw new HttpResponseException(response()->json([
            'message' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
            'errors' => ['email' => [trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ])]],
        ], 429));
    }

    /** Per email AND per IP, so one attacker cannot lock out a real user by name alone. */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
