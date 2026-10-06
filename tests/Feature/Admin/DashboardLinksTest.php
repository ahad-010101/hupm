<?php

use App\Domain\Reporting\DashboardFilters;
use App\Domain\Reporting\DashboardQuery;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| Every link the dashboard offers must go somewhere  [WP-53]
|--------------------------------------------------------------------------
|
| Two were broken and neither was caught by a test, because a test that
| renders the dashboard only proves the links were PRINTED.
|
| `/admin/leases/{id}` returned **405**, not 404: the resource route is
| declared `->except(['show'])`, so the path exists for PUT and DELETE and for
| no GET — which made a wrong link read as a server fault. `/admin/import`
| returned 404; the importer was descoped on 27 Aug 2026 and the setup
| checklist still pointed at it.
|
| Resolving each href through the router catches the whole class rather than
| these two instances.
|
*/

uses(RefreshDatabase::class);

/** Does a GET of this path reach a route? */
function dashboardLinkResolves(string $href): bool
{
    try {
        Route::getRoutes()->match(Request::create($href, 'GET'));

        return true;
    } catch (NotFoundHttpException|MethodNotAllowedHttpException|UrlGenerationException) {
        return false;
    }
}

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->tenant = Tenant::factory()->create();

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01',
        // Inside the default 90-day expiry window, so the panel has a row.
        'end_date' => now()->addDays(20)->toDateString(),
        'total_contract_rent' => '500.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '0.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ])->save();
});

it('AC-ADM-30 offers no dashboard panel link that 404s or 405s', function () {
    $panels = app(DashboardQuery::class)->panels(DashboardFilters::none());

    $links = collect($panels)
        ->flatten(1)
        ->pluck('href')
        ->filter()
        ->unique()
        ->values();

    expect($links)->not->toBeEmpty();

    foreach ($links as $href) {
        expect(dashboardLinkResolves($href))->toBeTrue("Dashboard link {$href} does not resolve to a GET route");
    }
});

it('AC-ADM-30 offers no setup-checklist link that 404s', function () {
    $steps = app(DashboardQuery::class)->setupChecklist()['steps'];

    foreach ($steps as $step) {
        expect(dashboardLinkResolves($step['href']))
            ->toBeTrue("Setup step '{$step['label']}' links to {$step['href']}, which does not resolve");
    }
});

it('AC-ADM-30 sends an expiring lease to a page that actually opens', function () {
    $panels = app(DashboardQuery::class)->panels(DashboardFilters::none());
    $href = $panels['expiring_leases'][0]['href'];

    // The reported bug, end to end: the row was clicked and the server said
    // "405 Method Not Allowed".
    expect($href)->toBe("/admin/leases/{$this->lease->id}/edit");

    $this->actingAs($this->admin)->get($href)->assertOk();
});
