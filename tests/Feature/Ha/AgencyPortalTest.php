<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Models\HousingAuthority;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Housing authority portal  [WP-43, D-29]
|--------------------------------------------------------------------------
|
| D-29 is the mirror of I-4: a tenant never sees the agency's portion, and an
| agency never sees a resident's own arrears. The second half was never
| written down before this package and matters as much — it is somebody's
| financial standing being shown to a third party.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    config([
        'services.authorize_net.login_id' => 'sandbox-login',
        'services.authorize_net.transaction_key' => 'sandbox-key',
        'services.authorize_net.environment' => 'sandbox',
    ]);

    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);

    $this->authority = HousingAuthority::factory()->create(['name' => 'Atlanta Housing Authority']);
    $this->rival = HousingAuthority::factory()->create(['name' => 'DeKalb Housing Authority']);

    $this->officer = User::factory()->create([
        'role' => User::ROLE_HOUSING_AUTHORITY,
        'housing_authority_id' => $this->authority->id,
    ]);

    $property = Property::factory()->create(['name' => 'Peachtree House']);

    $this->lease = agencyLease($this->authority, $property, '1');
    $this->second = agencyLease($this->authority, $property, '2');
    $this->rivalLease = agencyLease($this->rival, $property, '3');
});

function agencyLease(HousingAuthority $authority, Property $property, string $unit): Lease
{
    $lease = new Lease;
    $lease->forceFill([
        'unit_id' => Unit::factory()->create(['property_id' => $property->id, 'unit_number' => $unit])->id,
        'tenant_id' => Tenant::factory()->create()->id,
        'housing_authority_id' => $authority->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '300.00',
        'ha_portion' => '900.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'is_subsidised' => true,
        'status' => 'active',
    ])->save();

    return $lease;
}

/** Rent for one period: the tenant's share and the agency's, as the job posts them. */
function postSplitRent(Lease $lease, string $period = '2026-02'): void
{
    test()->ledger->postCharge(
        $lease, 'rent', 'tenant', Money::fromString('300.00'),
        "Rent — {$period}", "{$lease->id}:rent:{$period}:tenant",
        CarbonImmutable::parse("{$period}-01"), $period,
    );
    test()->ledger->postCharge(
        $lease, 'rent', 'housing_authority', Money::fromString('900.00'),
        "Rent — {$period}", "{$lease->id}:rent:{$period}:ha",
        CarbonImmutable::parse("{$period}-01"), $period,
    );
}

/*
 |--------------------------------------------------------------------------
 | What an agency sees
 |--------------------------------------------------------------------------
 */

it('AC-HA-01 shows every lease it funds, and none that it does not', function () {
    postSplitRent($this->lease);
    postSplitRent($this->second);
    postSplitRent($this->rivalLease);

    $this->actingAs($this->officer)
        ->get('/agency')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Ha/Dashboard')
            ->has('leases', 2)
            // 900 + 900. The agency's own portion only.
            ->where('total', '1800.00'));
});

it('AC-HA-02 D-29 never discloses what a resident owes', function () {
    postSplitRent($this->lease);

    // The resident pays nothing; their own arrears are $300 and climbing.
    $this->actingAs($this->officer)
        ->get('/agency')
        ->assertInertia(function ($page) {
            $props = $page->toArray()['props'];
            $encoded = json_encode($props);

            // The mirror of I-4. Whether this resident is behind on their own
            // rent is not the agency's business, and 300.00 is that figure.
            expect($encoded)->not->toContain('300.00');

            foreach ($props['leases'] as $lease) {
                expect($lease)->not->toHaveKey('tenant_portion')
                    ->and($lease)->not->toHaveKey('tenant_balance');
            }
        });
});

it('AC-HA-02 the statement carries only the authority portion', function () {
    postSplitRent($this->lease);

    $this->actingAs($this->officer)
        ->get('/agency/statement')
        ->assertOk()
        ->assertInertia(function ($page) {
            $entries = $page->toArray()['props']['entries'];

            // One row: the agency's $900. The tenant's $300 charge for the same
            // period is not filtered out downstream — the query never selects it.
            expect($entries)->toHaveCount(1)
                ->and($entries[0]['amount'])->toBe('900.00');
        });
});

it('AC-HA-03 a late fee never reaches an agency', function () {
    postSplitRent($this->lease);

    // I-7 already confines fees to the tenant; this proves the portal inherits it.
    $this->ledger->postCharge(
        $this->lease, 'late_fee', 'tenant', Money::fromString('50.00'),
        'Late fee — February', "{$this->lease->id}:latefee_flat:2026-02",
        CarbonImmutable::parse('2026-02-06'), '2026-02',
    );

    $this->actingAs($this->officer)
        ->get('/agency/statement')
        ->assertInertia(fn ($page) => $page->has('entries', 1));

    expect($this->balances->authorityBalance($this->authority->id)->toDecimalString())->toBe('900.00');
});

/*
 |--------------------------------------------------------------------------
 | Paying
 |--------------------------------------------------------------------------
 */

it('AC-HA-04 pays one lease, as the agency, without moving the balance yet', function () {
    postSplitRent($this->lease);

    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'hosted-token-abc']))]);

    $this->actingAs($this->officer)
        ->postJson('/agency/pay', [
            'lease_id' => $this->lease->id,
            'amount' => '900.00',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertOk()
        ->assertJsonStructure(['payment_id', 'hosted_token', 'redirect_url']);

    $payment = Payment::sole();

    expect($payment->payer)->toBe('housing_authority')
        ->and($payment->lease_id)->toBe($this->lease->id)
        ->and($payment->amount->toDecimalString())->toBe('900.00')
        // I-6 holds for an agency exactly as for a resident.
        ->and($payment->status)->toBe('pending')
        ->and($this->balances->authorityBalance($this->authority->id)->toDecimalString())->toBe('900.00');
});

it('AC-HA-04 charges an agency no convenience fee and offers no card', function () {
    postSplitRent($this->lease);

    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    // Even with cards switched on and a fee set for residents.
    app(Settings::class)->set('payments.cards_enabled', 'true');
    app(Settings::class)->set('payments.card_convenience_fee_percent', '2.90');

    $this->actingAs($this->officer)->postJson('/agency/pay', [
        'lease_id' => $this->lease->id,
        'amount' => '900.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertOk();

    $payment = Payment::sole();

    // An agency remits by bank transfer. Adding a card fee to public money
    // would be indefensible.
    expect($payment->method)->toBe('echeck')
        ->and($payment->convenience_fee)->toBeNull()
        ->and($payment->amount->toDecimalString())->toBe('900.00');
});

it('AC-HA-05 returns 404, not 403, for another authority\'s lease', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    // A 403 would confirm the lease exists, and which tenancies a rival agency
    // funds is not this one's business (I-9).
    $this->actingAs($this->officer)
        ->postJson('/agency/pay', [
            'lease_id' => $this->rivalLease->id,
            'amount' => '900.00',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertNotFound();

    expect(Payment::count())->toBe(0);
});

it('AC-HA-05 is not blocked by a resident being in Management Review', function () {
    postSplitRent($this->lease);

    // BR-11 suspends the RESIDENT's online payment. The agency is not in
    // review, cannot be, and its remittance must not be collateral damage.
    $this->lease->forceFill(['delinquency_state' => 'management_review'])->save();

    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    $this->actingAs($this->officer)
        ->postJson('/agency/pay', [
            'lease_id' => $this->lease->id,
            'amount' => '900.00',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertOk();

    expect(Payment::sole()->payer)->toBe('housing_authority');
});

/*
 |--------------------------------------------------------------------------
 | The boundary
 |--------------------------------------------------------------------------
 */

it('AC-HA-06 keeps an agency out of every other part of the system', function (string $path) {
    $this->actingAs($this->officer)->get($path)->assertForbidden();
})->with(['/admin', '/admin/ledger', '/admin/tenants', '/portal', '/portal/pay', '/work']);

it('AC-HA-06 keeps everybody else out of the agency portal', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get('/agency')->assertForbidden();
})->with(['admin', 'tenant']);

it('AC-PAY-23 charges an agency nothing now that the bank rail has a fee of its own', function () {
    postSplitRent($this->lease);

    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    // [WP-47] Until 8 Sep an agency was safe from the fee by accident: it pays
    // by bank transfer and only cards were charged. Now the bank rail has a fee
    // too, so the only thing between a HAP remittance and a charge on public
    // money is the payer guard in convenienceFee(). This is the test that
    // notices if it is ever removed.
    app(Settings::class)->set('payments.echeck_fee_percent', '0.75');

    $this->actingAs($this->officer)->postJson('/agency/pay', [
        'lease_id' => $this->lease->id,
        'amount' => '900.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertOk();

    $payment = Payment::sole();

    expect($payment->payer)->toBe('housing_authority')
        ->and($payment->convenience_fee)->toBeNull()
        // Not $902.50. The agency is billed the remittance and nothing else.
        ->and($payment->amount->toDecimalString())->toBe('900.00');
});
