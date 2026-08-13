<?php

use App\Domain\Auth\InvitationService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Account provisioning and set-password  [WP-04, FR-AUTH-02]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->invitations = app(InvitationService::class);
});

it('AC-AUTH-06 lets a tenant exist with no email and therefore no account', function () {
    $tenant = Tenant::factory()->withoutEmail()->create();

    $user = $this->invitations->invite($tenant->id, $tenant->fullName(), $tenant->email);

    // Q-4 is unanswered and some of the 26 tenants have no address. The tenant
    // record must stand on its own — no exception, no placeholder account.
    expect($user)->toBeNull()
        ->and(User::where('tenant_id', $tenant->id)->exists())->toBeFalse()
        ->and($tenant->exists)->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'auth.invite.skipped')->count())->toBe(1);
});

it('creates an invited account and emails a set-password link', function () {
    $tenant = Tenant::factory()->create(['email' => 'new@example.test']);

    $user = $this->invitations->invite($tenant->id, 'New Tenant', 'new@example.test');

    expect($user->status)->toBe(User::STATUS_INVITED)
        ->and($user->password)->toBeNull()
        ->and($user->role)->toBe(User::ROLE_TENANT)
        ->and($user->tenant_id)->toBe($tenant->id);

    expect(DB::table('notification_logs')
        ->where('template', 'welcome_set_password')
        ->where('recipient', 'new@example.test')
        ->exists())->toBeTrue();
});

it('never mass-assigns role, status or tenant_id from input', function () {
    // I-11: privilege is set explicitly by the service, never accepted from a
    // request payload. Model::preventSilentlyDiscardingAttributes makes this
    // throw rather than quietly drop the fields — a silent drop looks identical
    // to a successful privilege escalation attempt that simply did nothing.
    expect(fn () => new User([
        'name' => 'X',
        'email' => 'x@example.test',
        'role' => 'admin',
        'status' => 'active',
        'tenant_id' => 99,
    ]))->toThrow(Illuminate\Database\Eloquent\MassAssignmentException::class);
});

it('sets a password from a valid link and activates the account', function () {
    $user = User::factory()->invited()->create();
    $url = $this->invitations->setPasswordUrl($user);

    $this->get($url)->assertOk();

    $this->post($url, [
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect(route('login'));

    $user->refresh();

    expect($user->status)->toBe(User::STATUS_ACTIVE)
        ->and($user->password)->not->toBeNull()
        // The link proved control of the mailbox, so verification is implicit
        // (TDD §4) rather than a second email.
        ->and($user->email_verified_at)->not->toBeNull();
});

it('AC-AUTH-05 refuses a reused link and sets no password', function () {
    $user = User::factory()->invited()->create();
    $url = $this->invitations->setPasswordUrl($user);

    $this->post($url, ['password' => 'first-password-here', 'password_confirmation' => 'first-password-here']);

    $hashAfterFirstUse = $user->fresh()->password;

    // The same URL again. A signed URL with an expiry is not single-use on its
    // own — the state token is what closes it, because setting a password
    // changes the state it was signed against.
    $this->post($url, ['password' => 'second-password-xyz', 'password_confirmation' => 'second-password-xyz'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->password)->toBe($hashAfterFirstUse);
});

it('AC-AUTH-05 shows an expiry notice instead of a form for a used link', function () {
    $user = User::factory()->invited()->create();
    $url = $this->invitations->setPasswordUrl($user);

    $this->post($url, ['password' => 'first-password-here', 'password_confirmation' => 'first-password-here']);

    // Offering a form and then rejecting the submission wastes the person's
    // time and teaches them the system is unreliable.
    $this->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/SetPassword')->where('valid', false));
});

it('AC-AUTH-05 rejects a tampered token', function () {
    $user = User::factory()->invited()->create();

    $this->get(route('password.set', ['user' => $user->id, 'token' => str_repeat('a', 64)]))
        ->assertForbidden(); // unsigned URL — the `signed` middleware refuses it

    expect($user->fresh()->password)->toBeNull();
});

it('AC-AUTH-05 rejects an expired link', function () {
    $user = User::factory()->invited()->create();
    $url = $this->invitations->setPasswordUrl($user, now()->subMinute());

    $this->get($url)->assertForbidden();

    expect($user->fresh()->password)->toBeNull();
});

it('AC-AUTH-05 offers a resend that does not disclose whether the account exists', function () {
    $invited = User::factory()->invited()->create();
    $active = User::factory()->create();

    $invitedMessage = $this->from('/login')
        ->post("/set-password/{$invited->id}/resend")
        ->assertRedirect('/login')
        ->getSession()->get('status');

    $activeMessage = $this->from('/login')
        ->post("/set-password/{$active->id}/resend")
        ->assertRedirect('/login')
        ->getSession()->get('status');

    // Identical responses; only the invited account actually gets an email.
    expect($invitedMessage)->not->toBeNull()
        ->and($invitedMessage)->toBe($activeMessage);

    expect(DB::table('notification_logs')->where('template', 'welcome_set_password')->count())->toBe(1);
});

it('enforces a twelve character minimum', function () {
    $user = User::factory()->invited()->create();
    $url = $this->invitations->setPasswordUrl($user);

    $this->post($url, ['password' => 'short', 'password_confirmation' => 'short'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->password)->toBeNull();
});
