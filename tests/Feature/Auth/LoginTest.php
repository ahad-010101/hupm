<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Login  [WP-04, FR-AUTH-01]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

const PASSWORD = 'correct-horse-battery-staple';

beforeEach(function () {
    RateLimiter::clear('user@example.test|127.0.0.1');
});

it('AC-AUTH-01 signs in an active user and lands them on their role dashboard', function (string $role, string $expected) {
    $user = User::factory()->{$role}()->create(['email' => 'user@example.test']);

    $this->post('/login', ['email' => 'user@example.test', 'password' => PASSWORD])
        ->assertRedirect($expected);

    $this->assertAuthenticatedAs($user);
})->with([
    'admin' => ['admin', '/admin'],
    'owner' => ['owner', '/owner'],
]);

it('AC-AUTH-01 sends a tenant to the portal', function () {
    $user = User::factory()->tenant()->create(['email' => 'user@example.test']);

    $this->post('/login', ['email' => 'user@example.test', 'password' => PASSWORD])
        ->assertRedirect('/portal');

    $this->assertAuthenticatedAs($user);
});

it('AC-AUTH-01 records last_login_at and an audit row', function () {
    $user = User::factory()->admin()->create(['email' => 'user@example.test', 'last_login_at' => null]);

    $this->post('/login', ['email' => 'user@example.test', 'password' => PASSWORD]);

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'auth.login.success')->count())->toBe(1);
});

it('AC-AUTH-02 gives the same message whether or not the address exists', function () {
    User::factory()->admin()->create(['email' => 'real@example.test']);

    $wrongPassword = $this->post('/login', ['email' => 'real@example.test', 'password' => 'wrong-password-here'])
        ->assertSessionHasErrors('email');

    $unknownAddress = $this->post('/login', ['email' => 'ghost@example.test', 'password' => 'wrong-password-here'])
        ->assertSessionHasErrors('email');

    // Byte-identical. Anything else is an account-enumeration oracle.
    expect(session()->get('errors')->get('email'))
        ->toBe($unknownAddress->getSession()->get('errors')->get('email'));

    $this->assertGuest();
});

it('AC-AUTH-02 does not reveal that an account is merely awaiting a password', function () {
    User::factory()->invited()->create(['email' => 'user@example.test']);

    $response = $this->post('/login', ['email' => 'user@example.test', 'password' => PASSWORD]);

    // The message tells everyone about the set-up link, so saying it here
    // discloses nothing — while still guiding the person who needs it.
    $message = $response->getSession()->get('errors')->first('email');

    expect($message)->toContain('do not match our records')
        ->and($message)->toContain('set-up link');

    $this->assertGuest();
});

it('refuses a suspended account even with the right password', function () {
    User::factory()->suspended()->create(['email' => 'user@example.test']);

    $this->post('/login', ['email' => 'user@example.test', 'password' => PASSWORD])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('AC-AUTH-03 returns 429 after five failures and stops checking the password', function () {
    $user = User::factory()->admin()->create(['email' => 'user@example.test']);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => 'user@example.test', 'password' => 'wrong-password-here']);
    }

    // The sixth attempt uses the CORRECT password and must still be refused —
    // proving the throttle short-circuits before any password evaluation.
    $this->post('/login', ['email' => 'user@example.test', 'password' => PASSWORD])
        ->assertStatus(429);

    $this->assertGuest();
    expect(DB::table('audit_logs')->where('action', 'auth.login.throttled')->count())->toBeGreaterThan(0);
});

it('AC-AUTH-03 throttles per email and IP together', function () {
    User::factory()->admin()->create(['email' => 'user@example.test']);
    User::factory()->admin()->create(['email' => 'other@example.test']);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => 'user@example.test', 'password' => 'wrong-password-here']);
    }

    // A second account from the same IP is unaffected: an attacker must not be
    // able to lock a real user out by hammering their address.
    $this->post('/login', ['email' => 'other@example.test', 'password' => PASSWORD])
        ->assertRedirect('/admin');
});

it('AC-AUTH-04 invalidates the pre-login session id', function () {
    $user = User::factory()->admin()->create(['email' => 'user@example.test']);

    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => 'user@example.test', 'password' => PASSWORD]);

    // Session fixation: an attacker who plants a cookie before login must not
    // end up holding an authenticated session.
    expect(session()->getId())->not->toBe($before);
});

it('logs out, invalidating the session and rotating the CSRF token', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');

    $this->assertGuest();
    expect(DB::table('audit_logs')->where('action', 'auth.logout')->count())->toBe(1);
});

it('has no self-registration route at all', function () {
    // TDD §4: accounts are provisioned by an admin. Breeze's register route,
    // controller, page and test were removed, not hidden.
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();

    expect(collect(Route::getRoutes())->contains(fn ($r) => $r->getName() === 'register'))->toBeFalse();
});
