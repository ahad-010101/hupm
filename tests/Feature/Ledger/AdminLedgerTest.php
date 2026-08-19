<?php

use App\Domain\Ledger\LedgerService;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Admin ledger view and the portal balance  [WP-09 DoD, WP-12 pulled forward]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ledger = app(LedgerService::class);
    $this->admin = User::factory()->admin()->create();
    $this->tenant = Tenant::factory()->create();

    $unit = Unit::factory()->create();
    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => $unit->id, 'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00', 'tenant_portion' => '500.00', 'ha_portion' => '700.00',
        'status' => 'active',
    ])->save();
});

function postBothCharges(): void
{
    test()->ledger->postCharge(test()->lease, 'rent', 'tenant', Money::fromString('500.00'), 'Rent', 'k:t');
    test()->ledger->postCharge(test()->lease, 'rent', 'housing_authority', Money::fromString('700.00'), 'Rent', 'k:h');
}

it('AC-LED-02 shows the tenant $300 with no trace of the $700 in the payload', function () {
    postBothCharges();
    $payment = $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('200.00'), 'Payment');
    $this->ledger->transitionStatus($payment, 'cleared');

    $tenantUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'tenant']);

    $response = $this->actingAs($tenantUser)->get('/portal');

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Portal/Dashboard')
        ->where('balances.balance', '300.00'));

    // I-4 is structural, not cosmetic: the figure is absent from the response
    // entirely, so no future change to the component can reveal it.
    $body = $response->getContent();

    expect($body)->not->toContain('700.00')
        ->and($body)->not->toContain('housing_authority')
        ->and($body)->not->toContain('Housing Authority');
});

it('AC-LED-02 keeps the HA figure out even when the tenant owes nothing', function () {
    $this->ledger->postCharge($this->lease, 'rent', 'housing_authority', Money::fromString('700.00'), 'Rent', 'k:h');

    $tenantUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'tenant']);

    $response = $this->actingAs($tenantUser)->get('/portal');

    $response->assertInertia(fn ($page) => $page->where('balances.balance', '0.00'));
    expect($response->getContent())->not->toContain('700');
});

it('shows an admin both balances separately, never summed', function () {
    postBothCharges();

    $this->actingAs($this->admin)
        ->get("/admin/ledger/{$this->tenant->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Ledger/Show')
            // Two obligations (BR-01). 1200.00 would be the wrong answer to a
            // question nobody asked.
            ->where('balances.tenant', '500.00')
            ->where('balances.ha', '700.00')
            ->has('entries', 2));
});

it('interleaves both payers with a per-payer running balance', function () {
    postBothCharges();

    $this->actingAs($this->admin)
        ->get("/admin/ledger/{$this->tenant->id}")
        ->assertInertia(function ($page) {
            $entries = collect($page->toArray()['props']['entries']);

            // Each payer's running total advances independently; mixing them
            // would produce a column that means nothing.
            expect($entries->firstWhere('payer', 'tenant')['running'])->toBe('500.00')
                ->and($entries->firstWhere('payer', 'housing_authority')['running'])->toBe('700.00');
        });
});

it('AC-LED-07 rejects an adjustment with no reason and posts nothing', function () {
    $this->actingAs($this->admin)
        ->post("/admin/ledger/{$this->tenant->id}/adjustments", [
            'payer' => 'tenant', 'amount' => '25.00', 'description' => 'Something', 'reason' => '',
        ])
        ->assertSessionHasErrors('reason');

    expect(LedgerEntry::count())->toBe(0);
});

it('AC-LED-07 posts an adjustment when a reason is given', function () {
    $this->actingAs($this->admin)
        ->post("/admin/ledger/{$this->tenant->id}/adjustments", [
            'payer' => 'tenant', 'amount' => '-25.00',
            'description' => 'Goodwill credit', 'reason' => 'Heating outage over a weekend',
        ])
        ->assertSessionHasNoErrors();

    $entry = LedgerEntry::first();

    expect($entry->type)->toBe('adjustment')
        ->and($entry->amount->toDecimalString())->toBe('-25.00')
        ->and($entry->reason)->toBe('Heating outage over a weekend')
        ->and(DB::table('audit_logs')->where('action', 'ledger.adjustment.posted')->count())->toBe(1);
});

it('rejects a zero adjustment', function () {
    $this->actingAs($this->admin)
        ->post("/admin/ledger/{$this->tenant->id}/adjustments", [
            'payer' => 'tenant', 'amount' => '0.00',
            'description' => 'Nothing', 'reason' => 'No reason at all',
        ])
        ->assertSessionHasErrors('amount');
});

it('AC-LED-08 reverses through the UI and leaves both rows visible', function () {
    postBothCharges();
    $entry = LedgerEntry::where('payer', 'tenant')->first();

    $this->actingAs($this->admin)
        ->post("/admin/ledger/{$this->tenant->id}/entries/{$entry->id}/reverse", [
            'reason' => 'Charged against the wrong lease',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get("/admin/ledger/{$this->tenant->id}")
        ->assertInertia(function ($page) use ($entry) {
            $entries = collect($page->toArray()['props']['entries']);

            expect($entries)->toHaveCount(3);

            $original = $entries->firstWhere('id', $entry->id);
            $reversal = $entries->firstWhere('reverses_entry_id', $entry->id);

            // Both present. The original is labelled as reversed rather than
            // removed, and no longer offers a Reverse control.
            expect($original)->not->toBeNull()
                ->and($original['is_reversed'])->toBeTrue()
                ->and($original['can_reverse'])->toBeFalse()
                ->and($reversal['amount'])->toBe('-500.00');
        });
});

it('AC-LED-01 exposes no way to edit or delete an entry over HTTP', function () {
    postBothCharges();
    $entry = LedgerEntry::first();

    foreach ([
        ['patch', "/admin/ledger/{$this->tenant->id}/entries/{$entry->id}"],
        ['put', "/admin/ledger/{$this->tenant->id}/entries/{$entry->id}"],
        ['delete', "/admin/ledger/{$this->tenant->id}/entries/{$entry->id}"],
    ] as [$method, $url]) {
        $this->actingAs($this->admin)->{$method}($url)->assertNotFound();
    }

    expect(LedgerEntry::count())->toBe(2);
});

it('404s when an entry is addressed through the wrong tenant', function () {
    postBothCharges();
    $entry = LedgerEntry::first();
    $other = Tenant::factory()->create();

    $this->actingAs($this->admin)
        ->post("/admin/ledger/{$other->id}/entries/{$entry->id}/reverse", ['reason' => 'Nope'])
        ->assertNotFound();
});

it('refuses an adjustment for a tenant with no active lease', function () {
    $stranger = Tenant::factory()->create();

    $this->actingAs($this->admin)
        ->post("/admin/ledger/{$stranger->id}/adjustments", [
            'payer' => 'tenant', 'amount' => '10.00',
            'description' => 'X', 'reason' => 'A good reason',
        ])
        ->assertSessionHasErrors('amount');
});

it('keeps non-admins out of the ledger', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get('/admin/ledger')->assertForbidden();
    $this->actingAs($user)->get("/admin/ledger/{$this->tenant->id}")->assertForbidden();
    $this->actingAs($user)
        ->post("/admin/ledger/{$this->tenant->id}/adjustments", [
            'payer' => 'tenant', 'amount' => '10.00', 'description' => 'X', 'reason' => 'Y',
        ])
        ->assertForbidden();
})->with(['tenant', 'owner']);
