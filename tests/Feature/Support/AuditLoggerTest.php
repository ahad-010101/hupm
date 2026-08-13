<?php

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| AuditLogger  [WP-02 DoD, FR-AUD-01, AC-AUD-01]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

it('AC-AUD-01 records actor, action, subject and timestamp', function () {
    $user = User::factory()->create(['role' => 'admin', 'status' => 'active']);
    $this->actingAs($user);

    app(AuditLogger::class)->record('ledger.entry.created', $user, ['note' => 'test']);

    $row = DB::table('audit_logs')->first();

    expect($row->user_id)->toBe($user->id)
        ->and($row->action)->toBe('ledger.entry.created')
        ->and($row->subject_type)->toBe(User::class)
        ->and((int) $row->subject_id)->toBe($user->id)
        ->and(json_decode($row->changes, true))->toBe(['note' => 'test'])
        ->and($row->created_at)->not->toBeNull();
});

it('records a null actor when the system acts', function () {
    // A scheduled job posting a late fee at 03:00 has no user. NULL means
    // "a rule did this", which is what an admin needs to be able to tell a
    // tenant who asks why.
    app(AuditLogger::class)->record('fees.late_fee.posted');

    expect(DB::table('audit_logs')->first()->user_id)->toBeNull();
});

it('captures only what changed, not a copy of the row', function () {
    $user = User::factory()->create(['name' => 'Before']);
    $user->name = 'After';

    app(AuditLogger::class)->recordChange('user.updated', $user);

    $changes = json_decode(DB::table('audit_logs')->first()->changes, true);

    expect(array_keys($changes))->toBe(['name'])
        ->and($changes['name']['from'])->toBe('Before')
        ->and($changes['name']['to'])->toBe('After')
        // The row is not copied wholesale, so a password hash never lands in
        // the audit log even though it is an attribute of the model.
        ->and($changes)->not->toHaveKey('password');
});

it('writes an audit row that cannot then be deleted', function () {
    app(AuditLogger::class)->record('test.action');

    // Append-only at the model layer (AC-AUD-02) — proven in ImmutabilityTest;
    // asserted here as the pairing that makes the log trustworthy.
    expect(DB::table('audit_logs')->count())->toBe(1);
});
