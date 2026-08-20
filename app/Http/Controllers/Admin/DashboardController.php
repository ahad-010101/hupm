<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\DashboardFilters;
use App\Domain\Reporting\DashboardQuery;
use App\Domain\Reporting\ExceptionFeed;
use App\Http\Controllers\Controller;
use App\Models\HousingAuthority;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin dashboard.  [API-ADM-01, FR-ADM-01, UI §3.7]
 *
 * Exceptions first, then the numbers, then the panels — the order is fixed by
 * UI §3.7 and it is the whole design. A dashboard that opens with a KPI row is
 * a dashboard where the returned payment is below the fold.
 *
 * Filters live in the query string (AC-ADM-01), so a screen is a URL somebody
 * can send to a colleague. Nothing here holds filter state server-side.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardQuery $dashboard,
        private readonly ExceptionFeed $exceptions,
    ) {}

    public function index(Request $request): Response
    {
        $filters = DashboardFilters::fromRequest($request);
        $setup = $this->dashboard->setupChecklist();

        return Inertia::render('Admin/Dashboard', [
            'exceptions' => $this->exceptions->items(),

            // An empty portfolio gets a checklist instead of zeroed widgets
            // (UI §7), so the expensive panel queries are not run to produce a
            // grid of dashes on day one.
            'setup' => $setup,
            'kpis' => $setup['empty'] ? null : $this->dashboard->kpis($filters),
            'panels' => $setup['empty'] ? null : $this->dashboard->panels($filters),

            'filters' => $filters->toArray(),
            'filterOptions' => $setup['empty'] ? null : $this->filterOptions(),
        ]);
    }

    /**
     * What the filter controls offer.
     *
     * Read from the data rather than hard-coded, so a filter can never list a
     * property that no longer exists — which is the other half of accepting a
     * stale bookmark without complaint (DashboardFilters).
     *
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'properties' => Property::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Property $p) => ['value' => $p->id, 'label' => $p->name])
                ->all(),

            'tenants' => Tenant::query()
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn (Tenant $t) => ['value' => $t->id, 'label' => $t->fullName()])
                ->all(),

            'housingAuthorities' => HousingAuthority::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (HousingAuthority $a) => ['value' => $a->id, 'label' => $a->name])
                ->all(),

            'paymentStatuses' => DashboardFilters::PAYMENT_STATUSES,
            'maintenanceStatuses' => DashboardFilters::MAINTENANCE_STATUSES,
            'expiryWindows' => DashboardFilters::EXPIRY_WINDOWS,
        ];
    }
}
