<?php

use App\Domain\Payments\AllocationOrderRegistry;
use App\Domain\Settings\SettingsCatalogue;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| System settings  [WP-27+, API-ADM-40, decision register §6]
|--------------------------------------------------------------------------
|
| Every gated client question ships as a row here rather than a value in the
| code, so a late answer is a configuration change. Two things this screen must
| get right:
|
|   1. It cannot accept a value the application will refuse later. An unknown
|      allocation order throws at the next payment, not on save — so validation
|      has to happen here or it happens to a resident.
|   2. Changing a value and confirming a decision are different acts. Confirming
|      the shipped default is an answer, and go-live blocks on the confirmations.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // The rows themselves are the subject here. Everywhere else in the
    // suite `Settings::string($key, $default)` falls back, so a missing row
    // goes unnoticed — this screen reads the table directly.
    $this->seed(SettingsSeeder::class);

    $this->settings = app(Settings::class);
    $this->catalogue = app(SettingsCatalogue::class);
    $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Office Manager']);
});

/*
 |--------------------------------------------------------------------------
 | Reading
 |--------------------------------------------------------------------------
 */

it('lists the settings with what is still waiting on the client', function () {
    $this->actingAs($this->admin)->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Index')
            ->has('settings')
            ->has('groups')
            ->has('unconfirmed'));
});

it('carries the decision reference through, so a row can be traced to its question', function () {
    $this->actingAs($this->admin)->get('/admin/settings')
        ->assertInertia(function ($page) {
            $allocation = collect($page->toArray()['props']['settings'])
                ->firstWhere('key', 'payment.allocation_order');

            // The register calls this Q-1. Losing that on the way to the screen
            // makes the row unmatchable to the question it answers.
            expect($allocation['reference'])->toContain('Q-1')
                ->and($allocation['gated'])->toBeTrue();
        });
});

/*
 |--------------------------------------------------------------------------
 | Changing a value
 |--------------------------------------------------------------------------
 */

it('changes a setting and records what it was', function () {
    $this->actingAs($this->admin)
        ->patch('/admin/settings', ['key' => 'delinquency.trigger_day', 'value' => '7'])
        ->assertRedirect();

    expect($this->settings->int('delinquency.trigger_day'))->toBe(7);

    $audit = DB::table('audit_logs')->where('action', 'setting.changed')->first();

    // What it was matters as much as what it is. These values decide when an
    // account is chased and what a resident is charged.
    expect($audit)->not->toBeNull();

    $changes = json_decode($audit->changes, true);

    expect($changes['key'])->toBe('delinquency.trigger_day')
        ->and($changes['from'])->toBe('5')
        ->and($changes['to'])->toBe('7');
});

it('refuses a value the application would throw on later', function () {
    // AllocationOrderRegistry throws on an unknown key rather than falling
    // back, deliberately. Without this check the failure surfaces at the next
    // payment, to a resident, rather than here.
    $this->actingAs($this->admin)
        ->patch('/admin/settings', ['key' => 'payment.allocation_order', 'value' => 'banana'])
        ->assertSessionHasErrors('value');

    expect($this->settings->string('payment.allocation_order'))->toBe('oldest_charge_first');
});

it('accepts every allocation order the registry can actually build', function () {
    $registry = app(AllocationOrderRegistry::class);

    foreach (['oldest_charge_first', 'rent_before_fees', 'newest_charge_first'] as $key) {
        $this->actingAs($this->admin)
            ->patch('/admin/settings', ['key' => 'payment.allocation_order', 'value' => $key])
            ->assertSessionHasNoErrors();

        // The screen and the registry agree on the set — that is the whole
        // point of validating against the catalogue.
        expect($registry->make($key)->key())->toBe($key);
    }
});

it('holds a number inside its stated range', function () {
    $this->actingAs($this->admin)
        ->patch('/admin/settings', ['key' => 'delinquency.trigger_day', 'value' => '400'])
        ->assertSessionHasErrors('value');

    $this->actingAs($this->admin)
        ->patch('/admin/settings', ['key' => 'reconciliation.stale_hours', 'value' => '0'])
        ->assertSessionHasErrors('value');
});

it('will not invent a setting that does not exist', function () {
    // Settings are a small known set. Anything else arriving from a form is a
    // bug or an attack, not a new preference.
    $this->actingAs($this->admin)
        ->patch('/admin/settings', ['key' => 'app.debug', 'value' => 'true'])
        ->assertSessionHasErrors('key');

    expect(DB::table('settings')->where('key', 'app.debug')->exists())->toBeFalse();
});

it('says so rather than writing an audit row when nothing changed', function () {
    $this->actingAs($this->admin)
        ->patch('/admin/settings', ['key' => 'delinquency.trigger_day', 'value' => '5'])
        ->assertSessionHas('status', fn (string $s) => str_contains($s, 'already'));

    expect(DB::table('audit_logs')->where('action', 'setting.changed')->count())->toBe(0);
});

/*
 |--------------------------------------------------------------------------
 | Confirming a decision
 |--------------------------------------------------------------------------
 */

it('confirms a gated decision without changing its value', function () {
    expect($this->settings->unconfirmedGatedKeys())->toContain('payment.allocation_order');

    $this->actingAs($this->admin)
        ->post('/admin/settings/confirm', ['key' => 'payment.allocation_order'])
        ->assertRedirect();

    $row = DB::table('settings')->where('key', 'payment.allocation_order')->first();

    // Confirming the shipped default is an answer — "yes, oldest charge first
    // is what we want" — and has to be distinguishable from nobody asking.
    expect($row->value)->toBe('oldest_charge_first')
        ->and($row->confirmed_at)->not->toBeNull()
        ->and($row->confirmed_by_user_id)->toBe($this->admin->id)
        ->and($this->settings->unconfirmedGatedKeys())->not->toContain('payment.allocation_order');
});

it('refuses to confirm something that was never a gated question', function () {
    $this->actingAs($this->admin)
        ->post('/admin/settings/confirm', ['key' => 'company.name'])
        ->assertSessionHasErrors('key');
});

it('audits a confirmation with the value that was agreed', function () {
    $this->actingAs($this->admin)->post('/admin/settings/confirm', ['key' => 'fees.automation_enabled']);

    $audit = DB::table('audit_logs')->where('action', 'setting.confirmed')->first();

    expect($audit)->not->toBeNull();

    $changes = json_decode($audit->changes, true);

    // The agreed value, not just the key: "they confirmed it" is useless later
    // without "confirmed it as what".
    expect($changes['key'])->toBe('fees.automation_enabled')
        ->and($changes['value'])->toBe('false');
});

it('empties the blocking list once everything gated is answered', function () {
    foreach ($this->settings->unconfirmedGatedKeys() as $key) {
        $this->actingAs($this->admin)->post('/admin/settings/confirm', ['key' => $key]);
    }

    expect($this->settings->unconfirmedGatedKeys())->toBeEmpty();
});

/*
 |--------------------------------------------------------------------------
 | The catalogue itself
 |--------------------------------------------------------------------------
 */

it('describes every seeded setting, so none is unreachable from the screen', function () {
    $seeded = DB::table('settings')->pluck('key');
    $missing = $seeded->reject(fn (string $key) => $this->catalogue->has($key))->values();

    // A seeded row nobody described can only be changed through tinker, which
    // is how this screen came to be needed in the first place.
    expect($missing->all())->toBe([]);
});

it('describes nothing that is not seeded', function () {
    $seeded = DB::table('settings')->pluck('key')->all();
    $described = array_keys($this->catalogue->all());

    expect(array_diff($described, $seeded))->toBe([]);
});

/*
 |--------------------------------------------------------------------------
 | Access
 |--------------------------------------------------------------------------
 */

it('keeps residents out of the settings entirely', function () {
    $tenant = Tenant::factory()->create();
    $resident = User::factory()->create(['role' => 'tenant', 'tenant_id' => $tenant->id]);

    $this->actingAs($resident)->get('/admin/settings')->assertForbidden();
    $this->actingAs($resident)
        ->patch('/admin/settings', ['key' => 'fees.automation_enabled', 'value' => 'true'])
        ->assertForbidden();
    $this->actingAs($resident)
        ->post('/admin/settings/confirm', ['key' => 'fees.automation_enabled'])
        ->assertForbidden();
});
