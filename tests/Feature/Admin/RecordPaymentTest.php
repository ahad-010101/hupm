<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Domain\Payments\AllocationService;
use App\Models\HousingAuthority;
use App\Models\Lease;
use App\Models\LedgerEntry;
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
| Admin-recorded payments  [WP-12, FR-PAY-05, API-ADM-15, BR-12]
|--------------------------------------------------------------------------
|
| Money that arrived without the gateway: a cheque at the office, a money
| order, an authority remittance. It has already happened by the time anyone
| types it in, so it posts `cleared` and the balance moves at once.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);
    $this->allocations = app(AllocationService::class);

    $this->authority = HousingAuthority::factory()->create(['name' => 'Fulton County HA']);
    $this->tenant = Tenant::factory()->create();

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $this->tenant->id,
        'housing_authority_id' => $this->authority->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '700.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ])->save();

    $this->actingAs($this->admin);
});

/** A posted charge to pay against. */
function chargeOf(string $amount, string $payer = 'tenant', string $postedOn = '2026-02-01'): LedgerEntry
{
    static $n = 0;

    return test()->ledger->postCharge(
        test()->lease, 'rent', $payer, Money::fromString($amount),
        'Rent — February 2026', 'rec:'.(++$n), CarbonImmutable::parse($postedOn), '2026-02',
    );
}

/** @return array<string, mixed> */
function paymentPayload(array $overrides = []): array
{
    return array_merge([
        'lease_id' => test()->lease->id,
        'payer' => 'tenant',
        'amount' => '200.00',
        'received_on' => now()->toDateString(),
        'method' => 'cheque',
        'reference' => '10412',
        'note' => '',
        'idempotency_key' => (string) Str::uuid(),
    ], $overrides);
}

/*
 |--------------------------------------------------------------------------
 | Recording
 |--------------------------------------------------------------------------
 */

it('records a cheque, clears it immediately and moves the balance', function () {
    chargeOf('500.00');

    $this->post('/admin/payments/record', paymentPayload())
        ->assertRedirect("/admin/ledger/{$this->tenant->id}");

    $payment = Payment::sole();
    $entry = LedgerEntry::where('type', 'payment')->sole();

    expect($payment->status)->toBe('settled')
        ->and($payment->gateway)->toBe('manual')
        ->and($payment->recorded_by_user_id)->toBe($this->admin->id)
        // Money already in hand has no clearing period.
        ->and($entry->status)->toBe('cleared')
        // Stored negative; the caller passed the amount paid.
        ->and($entry->amount->toDecimalString())->toBe('-200.00')
        ->and($entry->description)->toBe('Cheque 10412')
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('300.00');
});

it('allocates the payment against outstanding charges as it records it', function () {
    $rent = chargeOf('500.00');

    $this->post('/admin/payments/record', paymentPayload());

    expect(PaymentAllocation::count())->toBe(1)
        ->and(PaymentAllocation::first()->charge_entry_id)->toBe($rent->id)
        ->and(PaymentAllocation::first()->amount->toDecimalString())->toBe('200.00');
});

it('tells the admin when a payment lands as a credit', function () {
    // Nothing owed. The money is real; it just has nothing to meet yet.
    $this->post('/admin/payments/record', paymentPayload(['amount' => '200.00']))
        ->assertSessionHas('status', fn (string $s) => str_contains($s, 'unapplied'));

    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('-200.00');
});

it('keeps the admin note on the ledger row, not only in the audit log', function () {
    chargeOf('500.00');

    $this->post('/admin/payments/record', paymentPayload([
        'note' => 'Left at the office Friday, banked Monday.',
    ]));

    expect(LedgerEntry::where('type', 'payment')->sole()->reason)
        ->toBe('Left at the office Friday, banked Monday.');
});

it('writes an audit row naming the admin who recorded it', function () {
    chargeOf('500.00');

    $this->post('/admin/payments/record', paymentPayload());

    $audit = DB::table('audit_logs')->where('action', 'payment.recorded')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($this->admin->id);
});

/*
 |--------------------------------------------------------------------------
 | The rules that protect the money
 |--------------------------------------------------------------------------
 */

it('AC-PAY-15 records a cheque against an account in Management Review', function () {
    chargeOf('500.00');

    $this->lease->forceFill(['delinquency_state' => 'management_review'])->save();

    $this->post('/admin/payments/record', paymentPayload())
        ->assertSessionHasNoErrors();

    // BR-12 / I-12. The tenant in Management Review is the one most likely to
    // be paying by cheque at the office; refusing it would leave the arrears
    // overstated.
    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('300.00');
});

it('AC-PAY-16 leaves the tenant balance untouched when the payer is the housing authority', function () {
    chargeOf('500.00', 'tenant');
    $haRent = chargeOf('700.00', 'housing_authority');

    $this->post('/admin/payments/record', paymentPayload([
        'payer' => 'housing_authority',
        'amount' => '700.00',
        'method' => 'ha_remittance',
        'reference' => 'RA-99',
    ]));

    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00')
        ->and($this->balances->haBalance($this->tenant->id)->toDecimalString())->toBe('0.00')
        // [D-20] The HA payment allocates against HA charges, so "which months
        // has the authority paid?" has an answer.
        ->and(PaymentAllocation::sole()->charge_entry_id)->toBe($haRent->id);
});

it('R-13 a double submit with the same key records one payment, not two', function () {
    chargeOf('500.00');
    $payload = paymentPayload();

    $this->post('/admin/payments/record', $payload);
    $this->post('/admin/payments/record', $payload);

    expect(Payment::count())->toBe(1)
        ->and(LedgerEntry::where('type', 'payment')->count())->toBe(1)
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('300.00');
});

it('refuses a payment dated in the future', function () {
    $this->post('/admin/payments/record', paymentPayload([
        'received_on' => now()->addDay()->toDateString(),
    ]))->assertSessionHasErrors('received_on');

    expect(Payment::count())->toBe(0);
});

it('refuses a cheque with no cheque number', function () {
    // A cheque that cannot be matched to a bank statement is not a record.
    $this->post('/admin/payments/record', paymentPayload(['reference' => '']))
        ->assertSessionHasErrors('reference');
});

it('refuses a zero payment', function () {
    $this->post('/admin/payments/record', paymentPayload(['amount' => '0.00']))
        ->assertSessionHasErrors('amount');
});

it('refuses an authority payment on a lease with no authority', function () {
    $this->lease->forceFill(['housing_authority_id' => null])->save();

    $this->post('/admin/payments/record', paymentPayload([
        'payer' => 'housing_authority',
        'method' => 'ha_remittance',
        'reference' => 'RA-1',
    ]))->assertSessionHasErrors('payer');
});

it('refuses to record against an ended lease', function () {
    $this->lease->forceFill(['status' => 'ended'])->save();

    $this->post('/admin/payments/record', paymentPayload())
        ->assertSessionHasErrors('lease_id');
});

it('refuses a tenant', function () {
    $tenantUser = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $this->actingAs($tenantUser)
        ->post('/admin/payments/record', paymentPayload())
        ->assertForbidden();

    expect(Payment::count())->toBe(0);
});

it('shows the form with a fresh idempotency key each time', function () {
    $keys = [];

    foreach (range(1, 2) as $ignored) {
        $this->get('/admin/payments/record')
            ->assertOk()
            ->assertInertia(function ($page) use (&$keys) {
                $page->component('Admin/Payments/Record');
                $keys[] = $page->toArray()['props']['idempotencyKey'];
            });
    }

    // A key reused across renders would make the second genuine payment of the
    // day look like a double submit of the first.
    expect($keys[0])->not->toBe($keys[1])
        ->and(strlen($keys[0]))->toBe(36);
});

it('surfaces Management Review on the form rather than hiding the lease', function () {
    $this->lease->forceFill(['delinquency_state' => 'management_review'])->save();

    $this->get('/admin/payments/record')
        ->assertInertia(fn ($page) => $page
            ->has('leases', 1)
            ->where('leases.0.in_management_review', true));
});
