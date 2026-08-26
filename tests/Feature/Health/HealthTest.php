<?php

use App\Jobs\ReconcilePayments;
use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| /health  [API-PUB-07, DEVIATION D-06, WP-31]
|--------------------------------------------------------------------------
|
| Two audiences, one URL. A monitor gets a verdict; a caller holding the token
| gets the reasons. The spec hands the reasons to everybody, and D-06 does not,
| because last-scheduler-run and queue-depth together describe when the system
| is least attended.
|
| The status code carries the same verdict as the body on purpose: a monitor
| watching only HTTP status and one parsing JSON must not be able to disagree.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // A fresh install has never beaten, which is legitimately degraded — the
    // cron entry is the thing most likely to be missing on this host (R-5). So
    // healthy has to be arranged, not assumed.
    Cache::forever(SystemHealth::HEARTBEAT_KEY, now()->toIso8601String());
});

/** A successful reconciliation `hours` ago. */
function reconciledHoursAgo(int $hours): void
{
    DB::table('job_runs')->insert([
        'job_name' => ReconcilePayments::class,
        'started_at' => now()->subHours($hours),
        'finished_at' => now()->subHours($hours),
        'status' => 'success',
        'records_processed' => 0,
        'created_at' => now()->subHours($hours),
    ]);
}

it('API-PUB-07 answers a monitor with a verdict and nothing else', function () {
    $response = $this->getJson('/health')->assertOk();

    expect($response->json())->toBe(['status' => 'ok']);
});

it('D-06 keeps job timing away from an anonymous caller', function () {
    reconciledHoursAgo(2);

    $body = $this->getJson('/health')->assertOk()->json();

    // The whole point of the deviation: the verdict is public, the schedule is
    // not. Knowing when the nightly jobs run and how far behind they are is a
    // map of when nobody is watching.
    expect($body)->not->toHaveKey('checks');

    expect(json_encode($body))
        ->not->toContain('reconciliation')
        ->and(json_encode($body))->not->toContain('queue')
        ->and(json_encode($body))->not->toContain('last_run');
});

it('gives the detail to a caller holding the token', function () {
    config(['hupm.health.token' => 'a-long-random-string']);
    reconciledHoursAgo(2);

    $body = $this->withHeader('X-Health-Token', 'a-long-random-string')
        ->getJson('/health')->assertOk()->json();

    expect($body['status'])->toBe('ok')
        ->and($body['checks'])->toHaveKeys(['database', 'scheduler', 'reconciliation', 'queue'])
        ->and($body['checks']['database']['ok'])->toBeTrue()
        ->and($body['checks']['reconciliation']['hours_ago'])->toBe(2)
        ->and($body['checks']['queue']['depth'])->toBe(0);
});

it('accepts the token in the query string, for a monitor that cannot send headers', function () {
    config(['hupm.health.token' => 'a-long-random-string']);

    $this->getJson('/health?token=a-long-random-string')
        ->assertOk()
        ->assertJsonStructure(['status', 'checks']);
});

it('treats a wrong token as no token at all', function () {
    config(['hupm.health.token' => 'a-long-random-string']);

    $body = $this->getJson('/health?token=nearly-the-right-string')->assertOk()->json();

    expect($body)->toBe(['status' => 'ok']);
});

it('fails closed when no token is configured', function () {
    // An unset token must not mean "open to anyone who guesses an empty
    // string", which is what a naive comparison would do.
    config(['hupm.health.token' => null]);

    expect($this->getJson('/health?token=')->json())->toBe(['status' => 'ok']);
    expect($this->withHeader('X-Health-Token', '')->getJson('/health')->json())
        ->toBe(['status' => 'ok']);
});

it('reports degraded with a 503 when reconciliation has gone stale', function () {
    reconciledHoursAgo(40);

    // 503 matters as much as the body: a monitor configured only on HTTP status
    // still pages somebody. R-6 — this is the failure that costs money quietly.
    $this->getJson('/health')->assertStatus(503)->assertExactJson(['status' => 'degraded']);
});

it('reports degraded when the scheduler has stopped beating', function () {
    reconciledHoursAgo(2);
    Cache::forever(SystemHealth::HEARTBEAT_KEY, now()->subHour()->toIso8601String());

    config(['hupm.health.token' => 'a-long-random-string']);

    $body = $this->withHeader('X-Health-Token', 'a-long-random-string')
        ->getJson('/health')->assertStatus(503)->json();

    expect($body['status'])->toBe('degraded')
        ->and($body['checks']['scheduler']['ok'])->toBeFalse()
        ->and($body['checks']['scheduler']['minutes_ago'])->toBe(60);
});

it('does not call a system that has never gone live "broken" for never having reconciled', function () {
    config(['hupm.health.token' => 'a-long-random-string']);

    // No job_runs rows at all. Never-run is not stale, and a health endpoint
    // that shouts from the moment it is deployed is one nobody reads.
    $body = $this->withHeader('X-Health-Token', 'a-long-random-string')
        ->getJson('/health')->assertOk()->json();

    expect($body['status'])->toBe('ok')
        ->and($body['checks']['reconciliation']['never_run'])->toBeTrue()
        ->and($body['checks']['reconciliation']['ok'])->toBeTrue();
});

it('D-05 carries no Inertia props, being outside that middleware group', function () {
    $response = $this->get('/health');

    $response->assertHeader('content-type', 'application/json');

    // AC-PUB-01 in structural form: the public route file has no Inertia
    // middleware, so a shared prop cannot ride out on this response.
    expect($response->headers->get('X-Inertia'))->toBeNull();
});

it('is throttled, being unauthenticated and touching the database', function () {
    $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->uri() === 'health');

    expect($route->gatherMiddleware())->toContain('throttle:60,1');
});
