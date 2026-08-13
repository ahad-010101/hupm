<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\SetPasswordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication  [FR-AUTH-01…04, TDD §4]
|--------------------------------------------------------------------------
|
| There is NO registration route. Accounts are provisioned by an admin
| (FR-AUTH-02) and public self-registration does not exist — Breeze's register
| routes, controller, page and test were removed in WP-04, not merely hidden.
|
| There is NO email-verification route either. The set-password link proves
| control of the mailbox, so verification is implicit (TDD §4). Adding a second
| confirmation step would be ceremony that teaches tenants to click through
| emails without reading them.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // Resend after expiry (AC-AUTH-05). Not signed — the link that would carry
    // the signature is the expired one. Throttled instead, and the response is
    // identical whether or not the account exists.
    //
    // Declared BEFORE the {token} routes below: `resend` would otherwise match
    // {token} first and be handled by the signed route, which rejects it with a
    // 403 for having no signature. The `where` constraint below makes that
    // impossible regardless of order, but the ordering is kept as well because
    // relying on a single guard for a silent, confusing failure is thin.
    Route::post('set-password/{user}/resend', [SetPasswordController::class, 'resend'])
        ->middleware('throttle:3,15')
        ->name('password.set.resend');

    // Set-password from an invitation. `signed` enforces the 7-day expiry;
    // single use is enforced by the state token inside the controller. The
    // token is always 64 hex characters (HMAC-SHA256), so constraining it keeps
    // any literal path segment from being swallowed as a token.
    Route::get('set-password/{user}/{token}', [SetPasswordController::class, 'create'])
        ->where('token', '[0-9a-f]{64}')
        ->middleware('signed')
        ->name('password.set');

    Route::post('set-password/{user}/{token}', [SetPasswordController::class, 'store'])
        ->where('token', '[0-9a-f]{64}')
        ->middleware(['signed', 'throttle:6,1']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    // 60-minute expiry, single use (config/auth.php passwords.users.expire).
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
