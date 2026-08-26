<?php

use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Settings and the gated-decision register  [WP-02, plan §6]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->settings = app(Settings::class);
    $this->settings->flush();
});

it('reads typed values', function () {
    DB::table('settings')->insert([
        ['key' => 's', 'value' => 'hello', 'type' => 'string'],
        ['key' => 'i', 'value' => '5', 'type' => 'int'],
        ['key' => 'b', 'value' => 'true', 'type' => 'bool'],
        ['key' => 'j', 'value' => '{"a":1}', 'type' => 'json'],
        ['key' => 'm', 'value' => '25.00', 'type' => 'string'],
    ]);
    $this->settings->flush();

    expect($this->settings->string('s'))->toBe('hello')
        ->and($this->settings->int('i'))->toBe(5)
        ->and($this->settings->bool('b'))->toBeTrue()
        ->and($this->settings->json('j'))->toBe(['a' => 1])
        ->and($this->settings->money('m'))->toEqual(Money::fromString('25.00'));
});

it('falls back to the given default for an unknown key', function () {
    expect($this->settings->string('nope', 'fallback'))->toBe('fallback')
        ->and($this->settings->int('nope', 7))->toBe(7)
        ->and($this->settings->bool('nope', true))->toBeTrue()
        ->and($this->settings->money('nope')->isZero())->toBeTrue();
});

it('lists every gated decision still awaiting a client answer', function () {
    $this->seed(SettingsSeeder::class);
    $this->settings->flush();

    $unconfirmed = $this->settings->unconfirmedGatedKeys();

    // Seeding a sensible default is not the same as having an answer. WP-35
    // blocks while this list is non-empty.
    expect($unconfirmed)->toContain('payment.allocation_order')
        ->and($unconfirmed)->toContain('delinquency.trigger_day')
        // Not gated: derived from D-07, not a client decision.
        ->and($unconfirmed)->not->toContain('company.timezone');
});

it('records who confirmed a gated decision and when', function () {
    $this->seed(SettingsSeeder::class);
    $user = User::factory()->create(['role' => 'admin']);

    $this->settings->confirm('payment.allocation_order', $user->id);

    $row = DB::table('settings')->where('key', 'payment.allocation_order')->first();

    expect($row->confirmed_at)->not->toBeNull()
        ->and($row->confirmed_by_user_id)->toBe($user->id)
        ->and($this->settings->unconfirmedGatedKeys())->not->toContain('payment.allocation_order');
});

it('refuses to confirm a setting that does not exist', function () {
    expect(fn () => $this->settings->confirm('made.up.key', 1))->toThrow(RuntimeException::class);
});

it('sees a written value immediately, without a stale cache', function () {
    $this->settings->set('company.phone', '555-0100');

    expect($this->settings->string('company.phone'))->toBe('555-0100');
});
