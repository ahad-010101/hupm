<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Session management  [WP-04, FR-AUTH-04, AC-AUTH-07, AC-AUTH-08]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('configures the session cookie as HttpOnly, Secure-capable and SameSite=Lax', function () {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');

    // The driver is deliberately NOT asserted here: phpunit.xml overrides it to
    // `array` for speed, so this suite would only be checking its own override.
    // Whether production runs the database driver — which AC-AUTH-07 depends on,
    // since it deletes session rows — is a property of the deployed
    // environment, so `hupm:preflight` checks it instead.
});

it('FR-AUTH-04 sets a 120 minute idle timeout and a 12 hour ceiling', function () {
    expect(config('session.lifetime'))->toBe(120)
        // Laravel's lifetime is idle-only and slides forward on every request.
        // The absolute ceiling is ours (EnforceAbsoluteSessionLifetime).
        ->and(config('session.absolute_lifetime'))->toBe(720);
});

it('AC-AUTH-08 ends a session that reaches the absolute ceiling, returning no data', function () {
    $user = User::factory()->admin()->create();

    Carbon::setTestNow('2026-09-01 08:00:00');
    $this->actingAs($user)->get('/admin')->assertOk();

    // Still active but 13 hours in. Activity must not extend it past the
    // ceiling — a session left open on a shared machine has to end.
    Carbon::setTestNow('2026-09-01 21:00:01');

    $response = $this->get('/admin');

    $response->assertRedirect(route('login'));
    // The controller never ran, so nothing was loaded to leak.
    expect($response->getContent())->not->toContain('Dashboard');
    $this->assertGuest();
});

it('keeps a session alive inside the ceiling', function () {
    $user = User::factory()->admin()->create();

    Carbon::setTestNow('2026-09-01 08:00:00');
    $this->actingAs($user)->get('/admin')->assertOk();

    Carbon::setTestNow('2026-09-01 18:00:00'); // 10 hours — inside 12
    $this->get('/admin')->assertOk();

    $this->assertAuthenticated();
});

it('AC-AUTH-07 invalidates every other session when a password is reset', function () {
    $user = User::factory()->admin()->create(['email' => 'user@example.test']);

    // A session belonging to whoever knew the old password.
    DB::table('sessions')->insert([
        'id' => 'other-session-id',
        'user_id' => $user->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'attacker',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->timestamp,
    ]);

    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'user@example.test',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertRedirect(route('login'));

    // The whole point of a reset is usually that someone else has the old
    // password. Leaving their session alive defeats it.
    expect(DB::table('sessions')->where('id', 'other-session-id')->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'auth.password.reset')->count())->toBe(1);
});

it('rotates the remember token on reset, killing old remember-me cookies', function () {
    $user = User::factory()->admin()->create(['email' => 'user@example.test']);
    $before = $user->remember_token;

    $this->post('/reset-password', [
        'token' => Password::createToken($user),
        'email' => 'user@example.test',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    expect($user->fresh()->remember_token)->not->toBe($before);
});

it('does not silently reactivate a suspended account through a reset', function () {
    $user = User::factory()->suspended()->create(['email' => 'user@example.test']);

    $this->post('/reset-password', [
        'token' => Password::createToken($user),
        'email' => 'user@example.test',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    // Only an admin lifts a suspension.
    expect($user->fresh()->status)->toBe(User::STATUS_SUSPENDED);
});
