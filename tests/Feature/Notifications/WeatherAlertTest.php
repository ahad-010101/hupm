<?php

use App\Domain\Notifications\NotificationTemplate;
use App\Domain\Notifications\WeatherAlertService;
use App\Jobs\PollWeatherAlerts;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\WeatherAlert;
use App\Support\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Weather and emergency alerts  [WP-21, FR-NTF-03, AC-NTF-07/08]
|--------------------------------------------------------------------------
|
| Two things have to hold, and they pull in opposite directions: a resident is
| told once about a storm, and a resident is told at all when the weather API
| is having a bad day. The first is a database constraint; the second is a
| job that refuses to fail loudly.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Cache::flush();

    $this->service = app(WeatherAlertService::class);
    $this->admin = User::factory()->create(['role' => 'admin']);

    // Registered once, reading a mutable property at request time.
    // `Http::fake()` MERGES rather than replaces, so calling it a second time
    // in one test leaves the first stub in front — which silently replayed the
    // first alert and made a genuine second event look like a duplicate.
    $this->nwsFeatures = [];
    $this->nwsStatus = 200;

    Http::fake(function () {
        return test()->nwsStatus === 200
            ? Http::response(['features' => test()->nwsFeatures])
            : Http::response('', test()->nwsStatus);
    });

    $this->property = Property::factory()->create([
        'name' => 'Peachtree House',
        'state' => 'GA',
        'county' => 'Fulton',
        'country_code' => 'US',
    ]);

    $this->tenant = residentAt($this->property, 'Uriel', 'Pouros');
    $this->neighbour = residentAt($this->property, 'Jordan', 'Miller');
});

function residentAt(Property $property, string $first, string $last): Tenant
{
    $tenant = Tenant::factory()->create(['first_name' => $first, 'last_name' => $last]);

    $lease = new Lease;
    $lease->forceFill([
        'unit_id' => Unit::factory()->create(['property_id' => $property->id])->id,
        'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => '500.00', 'tenant_portion' => '500.00', 'ha_portion' => '0.00',
        'rent_due_day' => 1, 'grace_period_days' => 5, 'status' => 'active',
    ])->save();

    return $tenant;
}

/** One NWS GeoJSON feature. */
function nwsAlert(array $overrides = []): array
{
    return ['properties' => array_merge([
        'id' => 'urn:oid:2.49.0.1.840.0.abc123',
        'event' => 'Tornado Warning',
        'severity' => 'Extreme',
        'headline' => 'Tornado Warning issued for Fulton County until 6:00PM EDT',
        'effective' => '2026-08-19T15:00:00-04:00',
        'expires' => '2026-08-19T18:00:00-04:00',
        'areaDesc' => 'Fulton; DeKalb; Cobb',
    ], $overrides)];
}

function fakeNws(array $features): void
{
    test()->nwsFeatures = $features;
    test()->nwsStatus = 200;
}

function nwsDown(int $status = 503): void
{
    test()->nwsStatus = $status;
}

/*
 |--------------------------------------------------------------------------
 | Once per storm
 |--------------------------------------------------------------------------
 */

it('AC-NTF-07 notifies once, however many times the same alert comes back', function () {
    fakeNws([nwsAlert()]);

    $first = $this->service->poll();
    Cache::flush();
    $second = $this->service->poll();
    Cache::flush();
    $third = $this->service->poll();

    expect($first['alerts'])->toBe(1)
        ->and($first['notified'])->toBe(2)
        // Seen again every hour until it expires, which is the normal case.
        ->and($second['alerts'])->toBe(0)
        ->and($third['alerts'])->toBe(0)
        ->and(WeatherAlert::count())->toBe(1)
        ->and(DB::table('notification_logs')
            ->where('template', NotificationTemplate::WeatherAlert->value)->count())->toBe(2);
});

it('deduplicates on the constraint rather than on a check', function () {
    fakeNws([nwsAlert()]);
    $this->service->poll();

    // The guarantee is the unique index. A check-then-insert would race the
    // hourly job against itself, and losing that race means emailing a tenant
    // twice about one storm.
    $duplicate = new WeatherAlert;
    $duplicate->forceFill([
        'property_id' => $this->property->id,
        'nws_alert_id' => 'urn:oid:2.49.0.1.840.0.abc123',
        'event_type' => 'Tornado Warning',
        'created_at' => now(),
    ]);

    expect(fn () => $duplicate->save())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('alerts the same property again for a genuinely different event', function () {
    fakeNws([nwsAlert()]);
    $this->service->poll();

    Cache::flush();
    fakeNws([nwsAlert(['id' => 'urn:oid:2.49.0.1.840.0.def456', 'event' => 'Flood Warning'])]);
    $result = $this->service->poll();

    expect($result['alerts'])->toBe(1)
        ->and(WeatherAlert::count())->toBe(2);
});

/*
 |--------------------------------------------------------------------------
 | Which properties an alert covers
 |--------------------------------------------------------------------------
 */

it('matches an alert to a property by county', function () {
    $elsewhere = Property::factory()->create(['state' => 'GA', 'county' => 'Chatham', 'country_code' => 'US']);
    residentAt($elsewhere, 'Alyce', 'Kerluke');

    fakeNws([nwsAlert()]);
    $this->service->poll();

    // "Fulton; DeKalb; Cobb" does not include Chatham.
    expect(WeatherAlert::count())->toBe(1)
        ->and(WeatherAlert::sole()->property_id)->toBe($this->property->id);
});

it('tolerates "Fulton County" in our records against "Fulton" in theirs', function () {
    $this->property->update(['county' => 'Fulton County']);

    fakeNws([nwsAlert()]);

    expect($this->service->poll()['alerts'])->toBe(1);
});

it('alerts a property with no county rather than leaving it out', function () {
    $unknown = Property::factory()->create(['state' => 'GA', 'county' => null, 'country_code' => 'US']);
    residentAt($unknown, 'Mertie', 'Huel');

    fakeNws([nwsAlert(['areaDesc' => 'Chatham'])]);
    $this->service->poll();

    // Over-alerting teaches people to ignore the banner, which is a real cost.
    // A tornado warning that did not arrive is worse, and the fix is a county
    // in the property record.
    expect(WeatherAlert::pluck('property_id')->all())->toBe([$unknown->id]);
});

it('D-19 skips a property outside the US', function () {
    $abroad = Property::factory()->create(['state' => 'ON', 'county' => null, 'country_code' => 'CA']);
    residentAt($abroad, 'Leila', 'Stanton');

    fakeNws([nwsAlert()]);
    $this->service->poll();

    // NWS is US-only. Not a failure — out of scope.
    expect(WeatherAlert::where('property_id', $abroad->id)->exists())->toBeFalse();
});

it('polls each state once, not each property', function () {
    foreach (range(1, 4) as $i) {
        residentAt(Property::factory()->create([
            'state' => 'GA', 'county' => 'Fulton', 'country_code' => 'US',
        ]), "Tenant{$i}", 'Test');
    }

    fakeNws([nwsAlert()]);
    $this->service->poll();

    // Twenty-five properties in one state is one outbound request, cached for
    // the hour (TDD §7). On shared hosting that matters.
    Http::assertSentCount(1);
});

/*
 |--------------------------------------------------------------------------
 | When the weather service is having a bad day
 |--------------------------------------------------------------------------
 */

it('AC-NTF-08 logs a failure, notifies nobody, and does not throw', function () {
    nwsDown();

    $result = $this->service->poll();

    expect($result['alerts'])->toBe(0)
        ->and($result['errors'])->toHaveCount(1)
        ->and(WeatherAlert::count())->toBe(0)
        // The point of AC-NTF-08: nothing else in the application notices.
        ->and(DB::table('notification_logs')->count())->toBe(0);
});

it('records a failed poll as a completed run, not a crashed one', function () {
    nwsDown();

    app(PollWeatherAlerts::class)->handle(
        app(WeatherAlertService::class),
        app(AuditLogger::class),
    );

    // A weather API being down is a normal Tuesday. Failing the job would put
    // a red row on the dashboard for something nobody needs to act on — and
    // teach whoever reads it to ignore the next one.
    expect(DB::table('job_runs')->where('job_name', PollWeatherAlerts::class)->value('status'))
        ->toBe('success')
        ->and(DB::table('audit_logs')->where('action', 'weather.poll.partial_failure')->exists())
        ->toBeTrue();
});

it('survives an alert with no id rather than emailing hourly forever', function () {
    fakeNws([nwsAlert(['id' => null]), nwsAlert(['id' => 'urn:oid:good'])]);

    // Without an id there is no deduplication, so the alternative to skipping
    // is a tenant emailed every hour until the storm passes.
    expect($this->service->poll()['alerts'])->toBe(1);
});

it('survives a date it cannot parse', function () {
    fakeNws([nwsAlert(['expires' => 'sometime on Tuesday'])]);

    expect($this->service->poll()['alerts'])->toBe(1)
        ->and(WeatherAlert::sole()->expires_at)->toBeNull();
});

/*
 |--------------------------------------------------------------------------
 | Alerts an admin issues
 |--------------------------------------------------------------------------
 */

it('issues a manual alert and notifies the building', function () {
    $this->actingAs($this->admin)->post('/admin/alerts', [
        'property_id' => $this->property->id,
        'event_type' => 'Water shut off',
        'headline' => 'The water is off between 9am and 1pm on Tuesday while a main is repaired.',
    ])->assertSessionHasNoErrors();

    $alert = WeatherAlert::sole();

    expect($alert->event_type)->toBe('Water shut off')
        // Namespaced so it can never collide with a real NWS id.
        ->and($alert->nws_alert_id)->toStartWith('manual:')
        ->and($alert->notified_at)->not->toBeNull()
        ->and(DB::table('notification_logs')
            ->where('template', NotificationTemplate::WeatherAlert->value)->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', 'weather.alert.issued')->exists())->toBeTrue();
});

it('lets an admin issue two of the same kind without a collision', function () {
    foreach (range(1, 2) as $ignored) {
        $this->actingAs($this->admin)->post('/admin/alerts', [
            'property_id' => $this->property->id,
            'event_type' => 'Power outage',
            'headline' => 'The power is out.',
        ])->assertSessionHasNoErrors();
    }

    // A fresh uuid each time: two outages in a month are two events.
    expect(WeatherAlert::count())->toBe(2);
});

it('refuses a manual alert that has already expired', function () {
    $this->actingAs($this->admin)->post('/admin/alerts', [
        'property_id' => $this->property->id,
        'event_type' => 'Lift out of service',
        'headline' => 'Back tomorrow.',
        'expires_at' => now()->subHour()->toDateTimeString(),
    ])->assertSessionHasErrors('expires_at');

    expect(WeatherAlert::count())->toBe(0);
});

it('keeps a tenant out of the alerts screen', function () {
    $user = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $this->actingAs($user)->get('/admin/alerts')->assertForbidden();
    $this->actingAs($user)->post('/admin/alerts', [
        'property_id' => $this->property->id,
        'event_type' => 'Fake', 'headline' => 'Fake',
    ])->assertForbidden();
});

/*
 |--------------------------------------------------------------------------
 | What a resident sees
 |--------------------------------------------------------------------------
 */

it('puts a live alert on the tenant dashboard and leaves an expired one off', function () {
    $this->service->issueManual(
        $this->property, 'Water shut off', 'Off 9am to 1pm Tuesday.', null, $this->admin,
    );

    $expired = new WeatherAlert;
    $expired->forceFill([
        'property_id' => $this->property->id,
        'nws_alert_id' => 'manual:'.Str::uuid(),
        'event_type' => 'Old storm',
        'headline' => 'Last week.',
        'expires_at' => now()->subDay(),
        'created_at' => now()->subDays(2),
    ])->save();

    $user = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $props = [];
    $this->actingAs($user)->get('/portal')->assertOk()
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    // A storm warning that ended yesterday is worse than no warning: it teaches
    // people to ignore the panel.
    expect($props['weatherAlerts'])->toHaveCount(1)
        ->and($props['weatherAlerts'][0]['event'])->toBe('Water shut off');
});
