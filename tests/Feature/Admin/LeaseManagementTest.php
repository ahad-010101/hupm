<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Models\HousingAuthority;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Lease management  [WP-07, FR-REG-02, FR-REG-03]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->tenant = Tenant::factory()->create();
    $this->unit = Unit::factory()->create();
    $this->authority = HousingAuthority::factory()->create();
});

function leasePayload(array $overrides = []): array
{
    return array_merge([
        'unit_id' => test()->unit->id,
        'tenant_id' => test()->tenant->id,
        'housing_authority_id' => null,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '900.00',
        'tenant_portion' => '900.00',
        'ha_portion' => '0.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'late_fee_flat' => '50.00',
        'late_fee_daily' => '5.00',
        'late_fee_max' => '150.00',
        'returned_payment_fee' => '35.00',
        'security_deposit' => '900.00',
        'is_subsidised' => false,
        'hap_contract_number' => null,
        'utility_responsibility' => null,
        'partial_payment_policy' => 'full_only',
        'partial_minimum_amount' => null,
        'partial_policy_expires_on' => null,
        'partial_requires_approval' => true,
        'ledger_review_required' => false,
        'status' => 'active',
    ], $overrides);
}

it('creates a lease and marks the unit occupied', function () {
    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload())
        ->assertSessionHasNoErrors();

    expect(Lease::count())->toBe(1)
        ->and($this->unit->fresh()->status)->toBe('occupied')
        ->and(DB::table('audit_logs')->where('action', 'lease.created')->count())->toBe(1);
});

it('AC-REG-03 rejects portions that do not sum, with the exact message', function () {
    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload([
            'total_contract_rent' => '900.00',
            'tenant_portion' => '300.00',
            'ha_portion' => '500.00', // 800, not 900
        ]))
        ->assertSessionHasErrors('tenant_portion');

    expect(session('errors')->first('tenant_portion'))
        ->toBe('Portions must sum to total contract rent.');

    expect(Lease::count())->toBe(0);
});

it('AC-REG-03 accepts an exact split to the cent', function () {
    // Compared with Money, not floats: 300.33 + 599.67 is exactly 900.00 in
    // integer cents but 899.9999999999999 in IEEE-754.
    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload([
            'total_contract_rent' => '900.00',
            'tenant_portion' => '300.33',
            'ha_portion' => '599.67',
            'is_subsidised' => true,
            'housing_authority_id' => $this->authority->id,
        ]))
        ->assertSessionHasNoErrors();

    expect(Lease::count())->toBe(1);
});

it('AC-REG-04 rejects a second active lease on the same unit', function () {
    $this->actingAs($this->admin)->post('/admin/leases', leasePayload());

    $second = Tenant::factory()->create();

    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload(['tenant_id' => $second->id]))
        ->assertSessionHasErrors('unit_id');

    expect(Lease::where('status', 'active')->count())->toBe(1);
});

it('AC-REG-04 is enforced by the database, not only by validation', function () {
    // D-03. The FormRequest check races; two admins submitting at the same
    // moment both pass it. Writing straight past the validation layer proves
    // the generated column is the real guarantee.
    $this->actingAs($this->admin)->post('/admin/leases', leasePayload());

    $second = Tenant::factory()->create();

    expect(fn () => DB::table('leases')->insert([
        'unit_id' => $this->unit->id,
        'tenant_id' => $second->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => 900, 'tenant_portion' => 900, 'ha_portion' => 0,
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('AC-REG-04 still allows a new lease once the previous one ended', function () {
    // Turnover has to remain possible — the constraint is on ACTIVE leases.
    $this->actingAs($this->admin)->post('/admin/leases', leasePayload());
    $first = Lease::first();

    $this->actingAs($this->admin)
        ->post("/admin/leases/{$first->id}/terminate", ['effective_date' => '2026-06-30']);

    $second = Tenant::factory()->create();

    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload([
            'tenant_id' => $second->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
        ]))
        ->assertSessionHasNoErrors();

    expect(Lease::where('unit_id', $this->unit->id)->count())->toBe(2);
});

it('AC-REG-05 rejects a subsidised lease with no agency', function () {
    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload([
            'is_subsidised' => true,
            'housing_authority_id' => null,
            'tenant_portion' => '300.00',
            'ha_portion' => '600.00',
        ]))
        ->assertSessionHasErrors('housing_authority_id');

    expect(Lease::count())->toBe(0);
});

it('AC-REG-06 rejects a rent due day outside 1 to 28', function (int $day) {
    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload(['rent_due_day' => $day]))
        ->assertSessionHasErrors('rent_due_day');

    expect(Lease::count())->toBe(0);
})->with([0, 29, 30, 31, -1]);

it('AC-REG-06 accepts the boundaries', function (int $day) {
    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload(['rent_due_day' => $day]))
        ->assertSessionHasNoErrors();
})->with([1, 28]);

it('rejects money with more precision than the column holds', function () {
    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload(['total_contract_rent' => '900.005', 'tenant_portion' => '900.005']))
        ->assertSessionHasErrors('total_contract_rent');
});

it('AC-REG-07 ends a lease and stops it being due for charging', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    $this->actingAs($this->admin)->post('/admin/leases', leasePayload());
    $lease = Lease::first();

    $this->actingAs($this->admin)
        ->post("/admin/leases/{$lease->id}/terminate", [
            'effective_date' => '2026-06-10',
            'reason' => 'Tenant moved out',
        ])
        ->assertSessionHasNoErrors();

    $lease->refresh();

    expect($lease->status)->toBe('ended')
        ->and($lease->end_date->toDateString())->toBe('2026-06-10')
        // The charge job (WP-10) selects active leases. An ended one is
        // excluded from the next due day by status alone.
        ->and(Lease::where('status', 'active')->count())->toBe(0)
        ->and($this->unit->fresh()->status)->toBe('vacant');

    Carbon::setTestNow();
});

it('FR-REG-03 leaves existing entries and the balance untouched when a lease ends', function () {
    $this->actingAs($this->admin)->post('/admin/leases', leasePayload());
    $lease = Lease::first();

    DB::table('ledger_entries')->insert([
        'lease_id' => $lease->id, 'tenant_id' => $this->tenant->id,
        'type' => 'charge', 'category' => 'rent', 'payer' => 'tenant',
        'amount' => '900.00', 'status' => 'posted', 'posted_on' => '2026-06-01',
        'description' => 'Rent', 'charge_key' => "{$lease->id}:rent:2026-06:tenant",
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/leases/{$lease->id}/terminate", ['effective_date' => '2026-06-10']);

    // Ending a tenancy is not a way to make a debt disappear.
    expect(DB::table('ledger_entries')->where('lease_id', $lease->id)->count())->toBe(1)
        ->and(DB::table('ledger_entries')->sum('amount'))->toEqual(900.00);
});

it('updates a lease and records the before and after', function () {
    $this->actingAs($this->admin)->post('/admin/leases', leasePayload());
    $lease = Lease::first();

    $this->actingAs($this->admin)
        ->patch("/admin/leases/{$lease->id}", leasePayload([
            'late_fee_flat' => '75.00',
            'lock_version' => $lease->lockVersion(),
        ]))
        ->assertSessionHasNoErrors();

    expect((string) $lease->fresh()->late_fee_flat)->toBe('75.00');

    $changes = json_decode(
        DB::table('audit_logs')->where('action', 'lease.updated')->value('changes'),
        true,
    );

    expect($changes)->toHaveKey('late_fee_flat');
});

it('FS §18.4 warns the second admin when a lease changed underneath them', function () {
    $this->actingAs($this->admin)->post('/admin/leases', leasePayload());
    $lease = Lease::first();

    // Both admins opened the form at the same moment.
    $staleVersion = $lease->lockVersion();

    // First admin saves.
    $this->actingAs($this->admin)->patch("/admin/leases/{$lease->id}", leasePayload([
        'late_fee_flat' => '75.00',
        'lock_version' => $staleVersion,
    ]));

    // Second admin submits the form they rendered before that save. Silent
    // last-write-wins would replace the first admin's fee configuration with
    // neither of them knowing.
    $this->actingAs($this->admin)
        ->patch("/admin/leases/{$lease->id}", leasePayload([
            'late_fee_flat' => '25.00',
            'lock_version' => $staleVersion,
        ]))
        ->assertSessionHasErrors('lock_version');

    expect((string) $lease->fresh()->late_fee_flat)->toBe('75.00');
});

it('D-18 distinguishes two saves inside the same second', function () {
    // The whole reason lease timestamps carry millisecond precision. On a
    // whole-second TIMESTAMP these two saves produce an identical updated_at,
    // the stale check passes, and one admin's edit disappears silently — which
    // is exactly the case FS §18.4 exists to prevent.
    //
    // The clock is frozen so the two writes are provably within one second;
    // relying on real elapsed time would make this pass or fail by luck.
    Carbon::setTestNow('2026-06-01 09:15:30.100');

    $this->actingAs($this->admin)->post('/admin/leases', leasePayload());
    $lease = Lease::first();
    $firstVersion = $lease->lockVersion();

    Carbon::setTestNow('2026-06-01 09:15:30.400'); // same second, 300ms later

    $this->actingAs($this->admin)->patch("/admin/leases/{$lease->id}", leasePayload([
        'late_fee_flat' => '75.00',
        'lock_version' => $firstVersion,
    ]));

    $secondVersion = $lease->fresh()->lockVersion();

    expect(substr($firstVersion, 0, 19))->toBe(substr($secondVersion, 0, 19)) // same second
        ->and($firstVersion)->not->toBe($secondVersion)                       // still distinguishable
        ->and($secondVersion)->toEndWith('.400');

    Carbon::setTestNow();
});

it('does not overwrite an off-market unit when a lease ends', function () {
    // Off-market is a deliberate decision — a unit taken out of service for
    // renovation must not silently become vacant.
    $this->unit->update(['status' => 'off_market']);

    $this->actingAs($this->admin)->post('/admin/leases', leasePayload());

    expect($this->unit->fresh()->status)->toBe('off_market');
});

it('keeps non-admins out of leases', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get('/admin/leases')->assertForbidden();
    $this->actingAs($user)->post('/admin/leases', leasePayload())->assertForbidden();

    expect(Lease::count())->toBe(0);
})->with(['tenant', 'owner']);

it('AC-REG-01 offers a property with no units on the lease form, ordered by name', function () {
    // The reported bug: the form carried a flat list of units, so a property
    // with none contributed no options and looked as though it had never saved.
    $empty = Property::factory()->create(['name' => 'Aardvark Place']);
    $stocked = Property::factory()->create(['name' => 'Zebra Court']);
    Unit::factory()->create(['property_id' => $stocked->id, 'unit_number' => '1']);

    $this->actingAs($this->admin)
        ->get('/admin/leases/create')
        ->assertOk()
        ->assertInertia(function ($page) use ($empty, $stocked) {
            $properties = collect($page->toArray()['props']['properties']);
            $ids = $properties->pluck('id');

            expect($properties->firstWhere('id', $empty->id))->not->toBeNull()
                ->and($properties->firstWhere('id', $empty->id)['units'])->toBe([])
                ->and($properties->firstWhere('id', $stocked->id)['units'])->toHaveCount(1)
                // By name, not by insertion order — a newly added property is
                // not exiled to the bottom of a list of twenty-five.
                ->and($ids->search($empty->id))->toBeLessThan($ids->search($stocked->id));
        });
});

/*
|--------------------------------------------------------------------------
| List ordering and column sorting  [WP-38]
|--------------------------------------------------------------------------
*/

/**
 * Leases have no factory and the model is fully guarded, so build them the way
 * the rest of the suite does. Each gets its own unit: D-03 permits one active
 * lease per unit and the database enforces it.
 */
function sortableLease(array $overrides = []): Lease
{
    $lease = new Lease;
    $lease->forceFill(array_merge([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => Tenant::factory()->create()->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '900.00',
        'tenant_portion' => '900.00',
        'ha_portion' => '0.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ], $overrides))->save();

    return $lease;
}

it('WP-38 lists leases newest-added first by default', function () {
    $older = sortableLease(['created_at' => now()->subDays(3)]);
    $newer = sortableLease(['created_at' => now()->subDay()]);

    $this->actingAs($this->admin)
        ->get('/admin/leases')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Leases/Index')
            ->where('sort.key', 'created_at')
            ->where('sort.direction', 'desc')
            ->where('leases.data.0.id', $newer->id)
            ->where('leases.data.1.id', $older->id));
});

it('WP-38 orders lease status by a curated precedence, not alphabetically', function () {
    // Alphabetically these run active, draft, ended — so a plain descending
    // string sort buries every live lease beneath the dead ones, which is what
    // this replaces.
    $ended = sortableLease(['status' => 'ended']);
    $active = sortableLease(['status' => 'active']);
    $draft = sortableLease(['status' => 'draft']);

    $this->actingAs($this->admin)
        ->get('/admin/leases?sort=status&direction=asc')
        ->assertInertia(fn ($page) => $page
            ->where('leases.data.0.id', $active->id)
            ->where('leases.data.1.id', $draft->id)
            ->where('leases.data.2.id', $ended->id));

    $this->actingAs($this->admin)
        ->get('/admin/leases?sort=status&direction=desc')
        ->assertInertia(fn ($page) => $page->where('leases.data.0.id', $ended->id));
});

it('WP-38 sorts leases by tenant surname without disturbing the eager loads', function () {
    $zephyr = sortableLease(['tenant_id' => Tenant::factory()->create(['last_name' => 'Zephyr'])->id]);
    $abbott = sortableLease(['tenant_id' => Tenant::factory()->create(['last_name' => 'Abbott'])->id]);

    // The correlated subquery must not break the `with()` that populates these
    // names in the first place — asserting on the rendered name proves both.
    $this->actingAs($this->admin)
        ->get('/admin/leases?sort=tenant&direction=asc')
        ->assertInertia(fn ($page) => $page
            ->where('leases.data.0.id', $abbott->id)
            ->where('leases.data.1.id', $zephyr->id)
            ->where('leases.data.0.tenant', fn ($name) => str_contains($name, 'Abbott')));
});

it('WP-38 falls back to the default when a lease sort key is not whitelisted', function () {
    sortableLease();

    $this->actingAs($this->admin)
        ->get('/admin/leases?sort='.urlencode('status; drop table leases--'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sort.key', 'created_at'));
});

/*
 |--------------------------------------------------------------------------
 | Security deposit on the ledger  [WP-40, Q-12 closed 2026-09-04]
 |--------------------------------------------------------------------------
 |
 | Off by default and never retrospective: the leases already loaded carry
 | deposits their tenants paid years ago.
 |
 */

it('AC-REG-08 adds the security deposit to the tenant balance on a new lease', function () {
    app(Settings::class)->set('deposits.tracked_in_ledger', 'true');

    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload(['security_deposit' => '900.00']))
        ->assertSessionHasNoErrors();

    $deposit = LedgerEntry::where('category', 'deposit')->sole();

    expect($deposit->amount->toDecimalString())->toBe('900.00')
        ->and($deposit->payer)->toBe('tenant')
        // NULL period is the mechanism, not an oversight: the late-fee engine
        // reads outstandingByPeriod(), which skips rows without one.
        ->and($deposit->period)->toBeNull()
        ->and(app(BalanceCalculator::class)->tenantBalance($this->tenant->id)->toDecimalString())
        ->toBe('900.00');
});

it('AC-REG-08 adds nothing while the setting is off', function () {
    // The shipped default, and the state the 27 loaded leases were created in.
    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload(['security_deposit' => '900.00']))
        ->assertSessionHasNoErrors();

    expect(LedgerEntry::where('category', 'deposit')->count())->toBe(0)
        ->and(app(BalanceCalculator::class)->tenantBalance($this->tenant->id)->toDecimalString())
        ->toBe('0.00');
});

it('AC-REG-08 charges no deposit when the lease records none', function () {
    app(Settings::class)->set('deposits.tracked_in_ledger', 'true');

    $this->actingAs($this->admin)
        ->post('/admin/leases', leasePayload(['security_deposit' => '0.00']))
        ->assertSessionHasNoErrors();

    expect(LedgerEntry::where('category', 'deposit')->count())->toBe(0);
});

it('AC-REG-08 does not charge a second deposit when the lease is edited', function () {
    app(Settings::class)->set('deposits.tracked_in_ledger', 'true');

    $this->actingAs($this->admin)->post('/admin/leases', leasePayload(['security_deposit' => '900.00']));

    $lease = Lease::first();

    // Correcting a typo in the deposit figure is an amendment, not a new
    // tenancy, and must never raise a second charge.
    $this->actingAs($this->admin)->patch("/admin/leases/{$lease->id}", leasePayload([
        'security_deposit' => '950.00',
        'lock_version' => $lease->lockVersion(),
    ]));

    expect(LedgerEntry::where('category', 'deposit')->count())->toBe(1)
        ->and(LedgerEntry::where('category', 'deposit')->sole()->amount->toDecimalString())->toBe('900.00');
});
