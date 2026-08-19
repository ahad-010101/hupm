<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Models\HousingAuthority;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Lump-sum Housing Authority remittance  [WP-12, GATE Q-2, risk R-9]
|--------------------------------------------------------------------------
|
| One cheque, many residents. The ledger is per tenant, so the cheque has to
| be split — and the split comes from the authority's remittance advice, not
| from a guess. What the system guarantees is that the arithmetic is right and
| that none of it touches a tenant balance.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);

    $this->authority = HousingAuthority::factory()->create([
        'name' => 'DeKalb County HA',
        'remittance_type' => HousingAuthority::REMITTANCE_LUMP_SUM,
    ]);

    $this->actingAs($this->admin);
});

/** A tenant with an active lease under the authority, and one month of rent charged. */
function subsidised(string $haPortion, ?HousingAuthority $authority = null): Lease
{
    $authority ??= test()->authority;
    $tenant = Tenant::factory()->create();

    $lease = new Lease;
    $lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $tenant->id,
        'housing_authority_id' => $authority->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => Money::fromString($haPortion)->plus(Money::fromString('200.00'))->toDecimalString(),
        'tenant_portion' => '200.00',
        'ha_portion' => $haPortion,
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ])->save();

    test()->ledger->postCharge(
        $lease, 'rent', 'housing_authority', Money::fromString($haPortion),
        'Rent — February 2026', "rem:{$lease->id}:ha", CarbonImmutable::parse('2026-02-01'), '2026-02',
    );

    test()->ledger->postCharge(
        $lease, 'rent', 'tenant', Money::fromString('200.00'),
        'Rent — February 2026', "rem:{$lease->id}:tenant", CarbonImmutable::parse('2026-02-01'), '2026-02',
    );

    return $lease;
}

/** @return array<string, mixed> the Inertia props of a GET */
function inertiaProps(string $url): array
{
    $props = [];

    test()->get($url)->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/*
 |--------------------------------------------------------------------------
 | The screen
 |--------------------------------------------------------------------------
 */

it('suggests what each tenant owes on the authority portion', function () {
    $a = subsidised('700.00');
    $b = subsidised('845.86');

    $lines = inertiaProps("/admin/payments/remittance?housing_authority_id={$this->authority->id}")['lines'];

    expect($lines)->toHaveCount(2)
        ->and(collect($lines)->pluck('outstanding')->sort()->values()->all())
        ->toBe(['700.00', '845.86'])
        ->and(collect($lines)->pluck('lease_id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('offers nothing before an authority is chosen', function () {
    subsidised('700.00');

    $props = inertiaProps('/admin/payments/remittance');

    // The system cannot guess which authority sent a cheque either.
    expect($props['lines'])->toBe([])
        ->and($props['authority'])->toBeNull()
        ->and($props['authorities'])->toHaveCount(1)
        ->and($props['authorities'][0]['is_lump_sum'])->toBeTrue();
});

it('excludes a lease that has ended', function () {
    subsidised('700.00');
    $ended = subsidised('500.00');
    $ended->forceFill(['status' => 'ended'])->save();

    $props = inertiaProps("/admin/payments/remittance?housing_authority_id={$this->authority->id}");

    expect($props['lines'])->toHaveCount(1);
});

/*
 |--------------------------------------------------------------------------
 | Recording the split
 |--------------------------------------------------------------------------
 */

it('GATE Q-2 splits one cheque into a payment per tenant, sharing a batch', function () {
    $a = subsidised('700.00');
    $b = subsidised('845.86');

    $this->post('/admin/payments/remittance', [
        'housing_authority_id' => $this->authority->id,
        'total' => '1545.86',
        'received_on' => businessToday(),
        'reference' => 'RA-2026-02',
        'note' => '',
        'idempotency_key' => (string) Str::uuid(),
        'lines' => [
            ['lease_id' => $a->id, 'amount' => '700.00'],
            ['lease_id' => $b->id, 'amount' => '845.86'],
        ],
    ])->assertSessionHasNoErrors();

    $payments = Payment::orderBy('id')->get();

    expect($payments)->toHaveCount(2)
        ->and($payments->pluck('batch_id')->unique())->toHaveCount(1)
        ->and($payments->first()->batch_id)->toStartWith('HA-')
        ->and($payments->pluck('payer')->unique()->all())->toBe(['housing_authority'])
        ->and($payments->pluck('method')->unique()->all())->toBe(['ha_remittance'])
        // Each tenant's own obligation is settled, and each allocation lands on
        // that tenant's HA charge.
        ->and(PaymentAllocation::count())->toBe(2)
        ->and($this->balances->haBalance($a->tenant_id)->toDecimalString())->toBe('0.00')
        ->and($this->balances->haBalance($b->tenant_id)->toDecimalString())->toBe('0.00');
});

it('I-4 leaves every tenant balance untouched', function () {
    $a = subsidised('700.00');
    $b = subsidised('845.86');

    $this->post('/admin/payments/remittance', [
        'housing_authority_id' => $this->authority->id,
        'total' => '1545.86',
        'received_on' => businessToday(),
        'idempotency_key' => (string) Str::uuid(),
        'lines' => [
            ['lease_id' => $a->id, 'amount' => '700.00'],
            ['lease_id' => $b->id, 'amount' => '845.86'],
        ],
    ]);

    // BR-01: two separate obligations. The authority paying its share does
    // nothing for what the resident owes.
    expect($this->balances->tenantBalance($a->tenant_id)->toDecimalString())->toBe('200.00')
        ->and($this->balances->tenantBalance($b->tenant_id)->toDecimalString())->toBe('200.00');
});

it('refuses a split that does not add up to the cheque', function () {
    $a = subsidised('700.00');
    $b = subsidised('845.86');

    $this->post('/admin/payments/remittance', [
        'housing_authority_id' => $this->authority->id,
        'total' => '1545.86',
        'received_on' => businessToday(),
        'idempotency_key' => (string) Str::uuid(),
        'lines' => [
            ['lease_id' => $a->id, 'amount' => '700.00'],
            ['lease_id' => $b->id, 'amount' => '800.00'],
        ],
    ])->assertSessionHasErrors('lines');

    // Nothing partial is left behind. A cheque that does not reconcile is not
    // half-recorded.
    expect(Payment::count())->toBe(0);
});

it('says how far off the split is, not just that it is wrong', function () {
    $a = subsidised('700.00');

    $this->post('/admin/payments/remittance', [
        'housing_authority_id' => $this->authority->id,
        'total' => '1000.00',
        'received_on' => businessToday(),
        'idempotency_key' => (string) Str::uuid(),
        'lines' => [['lease_id' => $a->id, 'amount' => '700.00']],
    ])->assertSessionHasErrors([
        'lines' => '$300.00 of this remittance is not yet assigned to a tenant.',
    ]);
});

it('refuses a lease belonging to a different authority', function () {
    $ours = subsidised('700.00');
    $theirs = subsidised('300.00', HousingAuthority::factory()->create(['name' => 'Cobb County HA']));

    $this->post('/admin/payments/remittance', [
        'housing_authority_id' => $this->authority->id,
        'total' => '1000.00',
        'received_on' => businessToday(),
        'idempotency_key' => (string) Str::uuid(),
        'lines' => [
            ['lease_id' => $ours->id, 'amount' => '700.00'],
            ['lease_id' => $theirs->id, 'amount' => '300.00'],
        ],
    ])->assertSessionHasErrors('lines');

    // One agency's money must never post against another's obligation, and a
    // half-written batch would do exactly that.
    expect(Payment::count())->toBe(0);
});

it('drops zero lines rather than writing empty payments', function () {
    $a = subsidised('700.00');
    $b = subsidised('845.86');

    $this->post('/admin/payments/remittance', [
        'housing_authority_id' => $this->authority->id,
        'total' => '700.00',
        'received_on' => businessToday(),
        'idempotency_key' => (string) Str::uuid(),
        'lines' => [
            ['lease_id' => $a->id, 'amount' => '700.00'],
            ['lease_id' => $b->id, 'amount' => '0.00'],
        ],
    ])->assertSessionHasNoErrors();

    expect(Payment::count())->toBe(1)
        ->and($this->balances->haBalance($b->tenant_id)->toDecimalString())->toBe('845.86');
});

it('R-13 records one batch when the form is submitted twice', function () {
    $a = subsidised('700.00');
    $key = (string) Str::uuid();

    $payload = [
        'housing_authority_id' => $this->authority->id,
        'total' => '700.00',
        'received_on' => businessToday(),
        'idempotency_key' => $key,
        'lines' => [['lease_id' => $a->id, 'amount' => '700.00']],
    ];

    $this->post('/admin/payments/remittance', $payload);
    $this->post('/admin/payments/remittance', $payload);

    expect(Payment::count())->toBe(1)
        ->and($this->balances->haBalance($a->tenant_id)->toDecimalString())->toBe('0.00');
});

it('audits the batch as a whole, not only the payments inside it', function () {
    $a = subsidised('700.00');

    $this->post('/admin/payments/remittance', [
        'housing_authority_id' => $this->authority->id,
        'total' => '700.00',
        'received_on' => businessToday(),
        'reference' => 'RA-2026-02',
        'idempotency_key' => (string) Str::uuid(),
        'lines' => [['lease_id' => $a->id, 'amount' => '700.00']],
    ]);

    $audit = DB::table('audit_logs')->where('action', 'payment.remittance.recorded')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($this->admin->id)
        ->and(json_decode($audit->changes, true)['total'])->toBe('700.00');
});

it('lists a batch back on the payments screen', function () {
    $a = subsidised('700.00');
    $b = subsidised('845.86');

    $this->post('/admin/payments/remittance', [
        'housing_authority_id' => $this->authority->id,
        'total' => '1545.86',
        'received_on' => businessToday(),
        'idempotency_key' => (string) Str::uuid(),
        'lines' => [
            ['lease_id' => $a->id, 'amount' => '700.00'],
            ['lease_id' => $b->id, 'amount' => '845.86'],
        ],
    ]);

    $batch = Payment::first()->batch_id;

    $props = inertiaProps('/admin/payments?batch='.urlencode($batch));

    expect($props['payments']['data'])->toHaveCount(2)
        ->and($props['filters']['batch'])->toBe($batch);
});

it('refuses a tenant', function () {
    $lease = subsidised('700.00');
    $tenantUser = User::factory()->create(['role' => 'tenant', 'tenant_id' => $lease->tenant_id]);

    $this->actingAs($tenantUser)
        ->post('/admin/payments/remittance', [
            'housing_authority_id' => $this->authority->id,
            'total' => '700.00',
            'received_on' => businessToday(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['lease_id' => $lease->id, 'amount' => '700.00']],
        ])
        ->assertForbidden();
});
