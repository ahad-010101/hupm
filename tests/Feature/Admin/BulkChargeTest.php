<?php

use App\Domain\Charges\BulkChargeService;
use App\Domain\Charges\ChargePostingService;
use App\Domain\Ledger\BalanceCalculator;
use App\Jobs\PostScheduledCharges;
use App\Models\ChargeBatch;
use App\Models\ChargeType;
use App\Models\Lease;
use App\Models\LeaseChargeSchedule;
use App\Models\LedgerEntry;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Charge types & bulk charging  [WP-41, FR-CHG-01]
|--------------------------------------------------------------------------
|
| The engine underneath is WP-10's and is untouched: a monthly charge becomes
| a lease_charge_schedule and ChargePostingService posts it, keyed so a re-run
| cannot double-charge. What is new is creating them for many leases at once,
| and being able to take a mistake back in one act rather than twenty-six.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->admin = User::factory()->admin()->create();
    $this->balances = app(BalanceCalculator::class);
    $this->charges = app(BulkChargeService::class);

    $this->property = Property::factory()->create(['name' => 'Peachtree House']);
    $this->other = Property::factory()->create(['name' => 'Elm Court']);

    // Three leases at Peachtree, one at Elm.
    $this->leases = collect(['1', '2', '3'])
        ->map(fn ($n) => chargeableLease($this->property, $n))
        ->values();

    $this->elmLease = chargeableLease($this->other, 'A');

    $this->type = ChargeType::create([
        'name' => 'Garbage collection',
        'category' => 'utility',
        'default_description' => 'Garbage collection',
        'default_amount' => '25.00',
        'payer' => 'tenant',
        'active' => true,
    ]);
});

function chargeableLease(Property $property, string $unitNumber): Lease
{
    $lease = new Lease;
    $lease->forceFill([
        'unit_id' => Unit::factory()->create([
            'property_id' => $property->id,
            'unit_number' => $unitNumber,
        ])->id,
        'tenant_id' => Tenant::factory()->create()->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '900.00',
        'tenant_portion' => '900.00',
        'ha_portion' => '0.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ])->save();

    return $lease;
}

/** Run the nightly charge job, exactly as the scheduler does. */
function postCharges(): int
{
    return app(PostScheduledCharges::class)->handle(
        app(ChargePostingService::class),
        app(BusinessCalendar::class),
        app(AuditLogger::class),
    );
}

/** @return array<string, mixed> */
function bulkPayload(array $overrides = []): array
{
    return array_merge([
        'charge_type_id' => test()->type->id,
        'description' => 'Garbage collection',
        'amount' => '25.00',
        'scope' => 'all',
        'timing' => 'once',
        'confirmed_lease_count' => 4,
    ], $overrides);
}

/*
 |--------------------------------------------------------------------------
 | Posting once
 |--------------------------------------------------------------------------
 */

it('AC-CHG-05 charges exactly the leases selected, and no others', function () {
    $chosen = $this->leases->take(2);

    $this->actingAs($this->admin)
        ->post('/admin/charges', bulkPayload([
            'scope' => 'selected',
            'lease_ids' => $chosen->pluck('id')->all(),
            'confirmed_lease_count' => 2,
        ]))
        ->assertSessionHasNoErrors();

    expect(LedgerEntry::where('category', 'utility')->count())->toBe(2);

    foreach ($chosen as $lease) {
        expect($this->balances->tenantBalance($lease->tenant_id)->toDecimalString())->toBe('25.00');
    }

    // The third Peachtree lease and the Elm one are untouched.
    expect($this->balances->tenantBalance($this->leases->last()->tenant_id)->toDecimalString())->toBe('0.00')
        ->and($this->balances->tenantBalance($this->elmLease->tenant_id)->toDecimalString())->toBe('0.00');
});

it('AC-CHG-05 charges everyone at one property', function () {
    $this->actingAs($this->admin)
        ->post('/admin/charges', bulkPayload([
            'scope' => 'property',
            'property_id' => $this->property->id,
            'confirmed_lease_count' => 3,
        ]))
        ->assertSessionHasNoErrors();

    expect(LedgerEntry::where('category', 'utility')->count())->toBe(3)
        ->and($this->balances->tenantBalance($this->elmLease->tenant_id)->toDecimalString())->toBe('0.00');
});

it('AC-CHG-06 records the batch with a total that matches what was posted', function () {
    $this->actingAs($this->admin)->post('/admin/charges', bulkPayload());

    $batch = ChargeBatch::sole();

    expect($batch->lease_count)->toBe(4)
        ->and($batch->amount->toDecimalString())->toBe('25.00')
        ->and($batch->total_amount->toDecimalString())->toBe('100.00')
        ->and($batch->posted_by_user_id)->toBe($this->admin->id)
        ->and($batch->entries()->count())->toBe(4);
});

it('AC-CHG-06 refuses to post when the resident count moved under the admin', function () {
    // They read "4 residents" and, while they were deciding, a lease ended.
    $this->elmLease->forceFill(['status' => 'ended'])->save();

    $this->actingAs($this->admin)
        ->post('/admin/charges', bulkPayload(['confirmed_lease_count' => 4]))
        ->assertSessionHasErrors('scope');

    // Nothing posted. A bulk charge must apply to the list that was read.
    expect(LedgerEntry::count())->toBe(0)
        ->and(ChargeBatch::count())->toBe(0);
});

it('AC-CHG-06 never charges an ended tenancy', function () {
    $this->elmLease->forceFill(['status' => 'ended'])->save();

    $this->actingAs($this->admin)
        ->post('/admin/charges', bulkPayload(['confirmed_lease_count' => 3]))
        ->assertSessionHasNoErrors();

    expect(LedgerEntry::where('category', 'utility')->count())->toBe(3)
        ->and($this->balances->tenantBalance($this->elmLease->tenant_id)->toDecimalString())->toBe('0.00');
});

/*
 |--------------------------------------------------------------------------
 | Taking it back
 |--------------------------------------------------------------------------
 */

it('AC-CHG-07 reverses a whole batch in one act and restores every balance', function () {
    $this->actingAs($this->admin)->post('/admin/charges', bulkPayload());

    $batch = ChargeBatch::sole();

    $this->actingAs($this->admin)
        ->post("/admin/charges/batches/{$batch->id}/reverse", ['reason' => 'Wrong month, posted in error'])
        ->assertSessionHasNoErrors();

    expect($batch->fresh()->isReversed())->toBeTrue()
        // I-3: reversing entries, never deletes. Both rows stay.
        ->and(LedgerEntry::where('type', 'charge')->where('category', 'utility')->count())->toBe(4)
        ->and(LedgerEntry::where('type', 'reversal')->count())->toBe(4);

    foreach ($this->leases->push($this->elmLease) as $lease) {
        expect($this->balances->tenantBalance($lease->tenant_id)->toDecimalString())->toBe('0.00');
    }
});

it('AC-CHG-07 refuses to reverse the same batch twice', function () {
    $this->actingAs($this->admin)->post('/admin/charges', bulkPayload());
    $batch = ChargeBatch::sole();

    $this->actingAs($this->admin)
        ->post("/admin/charges/batches/{$batch->id}/reverse", ['reason' => 'Posted in error']);

    // A second reversal would credit everybody a second time.
    $this->actingAs($this->admin)
        ->post("/admin/charges/batches/{$batch->id}/reverse", ['reason' => 'Again'])
        ->assertSessionHasErrors('reason');

    expect(LedgerEntry::where('type', 'reversal')->count())->toBe(4);
});

it('AC-CHG-07 requires a reason to reverse', function () {
    $this->actingAs($this->admin)->post('/admin/charges', bulkPayload());
    $batch = ChargeBatch::sole();

    $this->actingAs($this->admin)
        ->post("/admin/charges/batches/{$batch->id}/reverse", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(LedgerEntry::where('type', 'reversal')->count())->toBe(0);
});

/*
 |--------------------------------------------------------------------------
 | Monthly
 |--------------------------------------------------------------------------
 */

it('AC-CHG-08 sets a monthly charge and posts nothing yet', function () {
    $this->actingAs($this->admin)
        ->post('/admin/charges', bulkPayload(['timing' => 'monthly']))
        ->assertSessionHasNoErrors();

    expect(LeaseChargeSchedule::where('charge_type_id', $this->type->id)->count())->toBe(4)
        // Setting up a monthly charge is not charging anybody today. The
        // nightly job posts it with the rent.
        ->and(LedgerEntry::count())->toBe(0)
        ->and(ChargeBatch::count())->toBe(0);
});

it('AC-CHG-08 posts the monthly charge on the next run, once only', function () {
    Carbon::setTestNow('2026-03-01 02:00:00');

    $this->actingAs($this->admin)->post('/admin/charges', bulkPayload(['timing' => 'monthly']));

    // The schedule rides the existing engine, unchanged since WP-10.
    postCharges();

    // Every lease got the charge, for every period the job was catching up on
    // — the leases start in January and this is March, so two are due. Counted
    // rather than hard-coded, because the catch-up is the engine's business and
    // this test is about the schedule, not about how far behind the cron is.
    $afterFirstRun = LedgerEntry::where('category', 'utility')->count();

    expect($afterFirstRun)->toBeGreaterThan(0)
        ->and(LedgerEntry::where('category', 'utility')->distinct()->count('lease_id'))->toBe(4);

    postCharges();

    // The point: {lease}:sched{id}:{period} means a second run adds nothing.
    expect(LedgerEntry::where('category', 'utility')->count())->toBe($afterFirstRun);

    Carbon::setTestNow();
});

it('AC-CHG-09 updates existing schedules rather than duplicating them', function () {
    $this->actingAs($this->admin)->post('/admin/charges', bulkPayload(['timing' => 'monthly']));

    // The price went up. Re-run the same purpose over the same residents.
    $this->actingAs($this->admin)
        ->post('/admin/charges', bulkPayload(['timing' => 'monthly', 'amount' => '30.00']))
        ->assertSessionHasNoErrors();

    $schedules = LeaseChargeSchedule::where('charge_type_id', $this->type->id)->get();

    expect($schedules)->toHaveCount(4)
        ->and($schedules->every(fn ($s) => $s->amount->toDecimalString() === '30.00'))->toBeTrue();
});

it('AC-CHG-09 is enforced by the database, not only by the service', function () {
    $this->actingAs($this->admin)->post('/admin/charges', bulkPayload(['timing' => 'monthly']));

    $lease = $this->leases->first();

    // Two admins pressing the button at once both pass the service's read.
    // Only the unique index refuses the second (D-03's trick, on a nullable
    // column so hand-made schedules with no type are unaffected).
    expect(fn () => DB::table('lease_charge_schedules')->insert([
        'lease_id' => $lease->id,
        'charge_type_id' => $this->type->id,
        'category' => 'utility',
        'description' => 'Garbage collection',
        'amount' => '25.00',
        'payer' => 'tenant',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('AC-CHG-10 stops a monthly charge without touching what was posted', function () {
    Carbon::setTestNow('2026-03-01 02:00:00');

    $this->actingAs($this->admin)->post('/admin/charges', bulkPayload(['timing' => 'monthly']));
    postCharges();

    $beforeStopping = LedgerEntry::where('category', 'utility')->count();

    $this->actingAs($this->admin)
        ->post("/admin/charges/types/{$this->type->id}/stop")
        ->assertSessionHasNoErrors();

    // A month later, with rent still posting normally.
    Carbon::setTestNow('2026-04-01 02:00:00');
    postCharges();

    expect(LeaseChargeSchedule::where('active', true)->count())->toBe(0)
        // Stopping is not an undo: what was charged stays charged, to the row.
        ->and(LedgerEntry::where('category', 'utility')->count())->toBe($beforeStopping)
        // And rent kept posting, so the job itself did run.
        ->and(LedgerEntry::where('category', 'rent')->where('period', '2026-03')->count())->toBeGreaterThan(0);

    Carbon::setTestNow();
});

/*
 |--------------------------------------------------------------------------
 | Guards
 |--------------------------------------------------------------------------
 */

it('I-7 charges the tenant portion and never the housing authority', function () {
    $this->actingAs($this->admin)->post('/admin/charges', bulkPayload());

    expect(LedgerEntry::where('payer', 'housing_authority')->count())->toBe(0)
        ->and(LedgerEntry::where('payer', 'tenant')->count())->toBe(4);
});

it('refuses a charge of zero', function () {
    $this->actingAs($this->admin)
        ->post('/admin/charges', bulkPayload(['amount' => '0.00']))
        ->assertSessionHasErrors('amount');

    expect(LedgerEntry::count())->toBe(0);
});

it('keeps non-admins out of bulk charging', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get('/admin/charges')->assertForbidden();
    $this->actingAs($user)->post('/admin/charges', bulkPayload())->assertForbidden();

    expect(LedgerEntry::count())->toBe(0);
})->with(['tenant', 'owner']);
