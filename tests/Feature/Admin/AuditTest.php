<?php

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The audit browser  [API-ADM-41, AC-AUD-02, WP-29]
|--------------------------------------------------------------------------
|
| Writing happens everywhere; this is the one place that reads. It is read-only
| by construction — no update route, no delete route — and the model refuses
| both regardless, so a mistake in a policy cannot become a mistake in the
| record.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Office']);
    $this->colleague = User::factory()->create(['role' => 'admin', 'name' => 'Marta']);
    $this->resident = Tenant::factory()->create(['first_name' => 'Uriel', 'last_name' => 'Pouros']);
});

/** Write one row as `$actor` (or as the system when null). */
function auditEntry(string $action, ?User $actor = null, $subject = null, array $changes = []): void
{
    $actor ? test()->actingAs($actor) : auth()->logout();

    app(AuditLogger::class)->record($action, $subject, $changes);
}

/** Backdate the newest row, since created_at is set on insert. */
function backdateNewestAudit(string $date): void
{
    DB::table('audit_logs')
        ->whereIn('id', DB::table('audit_logs')->orderByDesc('id')->limit(1)->pluck('id'))
        ->update(['created_at' => $date]);
}

/*
 |--------------------------------------------------------------------------
 | Reaching it
 |--------------------------------------------------------------------------
 */

it('API-ADM-41 shows the trail to an admin', function () {
    auditEntry('ledger.charge.posted', $this->admin, $this->resident);

    $this->actingAs($this->admin)
        ->get('/admin/audit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Audit/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'ledger.charge.posted')
            ->where('logs.data.0.actor', 'Office')
            ->where('logs.data.0.subject_type', 'Tenant'));
});

it('I-9 keeps a resident out of the audit trail entirely', function () {
    $resident = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->resident->id]);

    // Wrong role is 403, not 404: the page's existence is not a secret, the
    // access is what is refused (I-9).
    $this->actingAs($resident)->get('/admin/audit')->assertForbidden();
});

it('is unreachable signed out', function () {
    $this->get('/admin/audit')->assertRedirect('/login');
});

/*
 |--------------------------------------------------------------------------
 | Filters
 |--------------------------------------------------------------------------
 */

it('filters by who acted', function () {
    auditEntry('property.created', $this->admin);
    auditEntry('property.deleted', $this->colleague);

    $this->actingAs($this->admin)
        ->get('/admin/audit?actor='.$this->colleague->id)
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.actor', 'Marta'));
});

it('filters to the system, which is a question in its own right', function () {
    auditEntry('fees.late.posted', null);
    auditEntry('property.created', $this->admin);

    // "What did the overnight jobs do" is the question behind most complaints
    // about a fee nobody remembers applying.
    $this->actingAs($this->admin)
        ->get('/admin/audit?actor=system')
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'fees.late.posted')
            ->where('logs.data.0.actor', null));
});

it('filters by action', function () {
    auditEntry('payment.settled', $this->admin);
    auditEntry('payment.returned', $this->admin);

    $this->actingAs($this->admin)
        ->get('/admin/audit?action=payment.returned')
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'payment.returned'));
});

it('filters by subject, both type and record', function () {
    $other = Tenant::factory()->create(['first_name' => 'Alyce', 'last_name' => 'Kerluke']);

    auditEntry('tenant.created', $this->admin, $this->resident);
    auditEntry('tenant.created', $this->admin, $other);
    auditEntry('property.created', $this->admin);

    $this->actingAs($this->admin)
        ->get('/admin/audit?subject_type='.urlencode(Tenant::class))
        ->assertInertia(fn ($page) => $page->has('logs.data', 2));

    $this->actingAs($this->admin)
        ->get('/admin/audit?subject_type='.urlencode(Tenant::class).'&subject_id='.$other->id)
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.subject_id', $other->id));
});

it('D-07 reads a filter date as a Georgia day, not a UTC one', function () {
    auditEntry('notice.sent', $this->admin);

    // 01:30 UTC on the 27th is still the evening of the 26th in New York, which
    // is when the overnight jobs run — so these are exactly the rows a naive
    // UTC comparison would hide from someone searching for "the 26th".
    backdateNewestAudit('2026-08-27 01:30:00');

    $this->actingAs($this->admin)
        ->get('/admin/audit?from=2026-08-26&to=2026-08-26')
        ->assertInertia(fn ($page) => $page->has('logs.data', 1));

    $this->actingAs($this->admin)
        ->get('/admin/audit?from=2026-08-27&to=2026-08-27')
        ->assertInertia(fn ($page) => $page->has('logs.data', 0));
});

it('survives a hand-edited date rather than throwing', function () {
    auditEntry('notice.sent', $this->admin);

    $this->actingAs($this->admin)
        ->get('/admin/audit?from=not-a-date')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1));
});

it('offers only filters that have an answer', function () {
    auditEntry('fees.late.posted', null);
    auditEntry('property.created', $this->colleague);

    $this->actingAs($this->admin)
        ->get('/admin/audit')
        ->assertInertia(fn ($page) => $page
            // Grouped by prefix so seventy-odd actions stay navigable.
            ->where('actions.fees', ['fees.late.posted'])
            ->where('actions.property', ['property.created'])
            // Marta acted; the signed-in admin has not, so offering them would
            // be offering a filter that returns nothing.
            ->has('actors', 1)
            ->where('actors.0.label', 'Marta'));
});

/*
 |--------------------------------------------------------------------------
 | Append-only
 |--------------------------------------------------------------------------
 */

it('AC-AUD-02 refuses to change an audit row', function () {
    auditEntry('ledger.charge.posted', $this->admin, $this->resident);

    $log = AuditLog::sole();

    expect(fn () => $log->forceFill(['action' => 'nothing.happened'])->save())
        ->toThrow(ImmutableRecordException::class);

    expect(AuditLog::sole()->action)->toBe('ledger.charge.posted');
});

it('AC-AUD-02 refuses to delete an audit row', function () {
    auditEntry('ledger.charge.posted', $this->admin, $this->resident);

    expect(fn () => AuditLog::sole()->delete())->toThrow(ImmutableRecordException::class);

    expect(AuditLog::count())->toBe(1);
});

it('AC-AUD-02 has no route that could reach a mutation in the first place', function () {
    $verbs = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'admin/audit'))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()
        ->values()
        ->all();

    // A guard in the model is the backstop. Not declaring the route is the
    // actual defence: there is nothing for a mistaken policy to let through.
    expect($verbs)->toEqualCanonicalizing(['GET', 'HEAD']);
});

it('refuses a mass-assigned change loudly rather than discarding it quietly', function () {
    auditEntry('ledger.charge.posted', $this->admin, $this->resident);

    // `$guarded = ['*']` makes the model totally guarded, so an update() throws
    // instead of silently dropping the attribute. That is the better of the two
    // behaviours here: code that believes it edited the audit trail should
    // fail, not appear to succeed.
    expect(fn () => AuditLog::sole()->update(['action' => 'nothing.happened']))
        ->toThrow(MassAssignmentException::class);

    expect(AuditLog::sole()->action)->toBe('ledger.charge.posted');
});
