<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Leases\LeaseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LeaseRequest;
use App\Models\HousingAuthority;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Support\BusinessCalendar;
use App\Support\ListSort;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leases.  [FR-REG-02, FR-REG-03, API-ADM-08…10]
 */
class LeaseController extends Controller
{
    public function __construct(private readonly LeaseService $leases) {}

    /**
     * Sortable columns.  [WP-38]
     *
     * @return array<string, string|\Closure>
     */
    private function sortable(): array
    {
        return [
            'created_at' => 'created_at',
            // A correlated subquery rather than a join: joining `tenants` here
            // would collide with the eager loads below and quietly change what
            // `through()` receives.
            'tenant' => fn ($q, string $d) => $q->orderBy(
                Tenant::select('last_name')->whereColumn('tenants.id', 'leases.tenant_id'),
                $d,
            ),
            'start_date' => 'start_date',
            'end_date' => 'end_date',
            'tenant_portion' => 'tenant_portion',
            // A curated precedence, not the column. `status` is an enum stored
            // as a string, so ordering by it sorts alphabetically — descending
            // that reads ended, draft, active, which buries every live lease
            // beneath the dead ones. Same pattern as SignatureController.
            'status' => fn ($q, string $d) => $q->orderByRaw(
                "FIELD(status, 'active', 'draft', 'ended') ".($d === 'asc' ? 'asc' : 'desc'),
            ),
        ];
    }

    public function index(Request $request): Response
    {
        $sortable = $this->sortable();

        // Newest first (WP-38). The previous default sorted by the status
        // string, which put all 27 active leases last.
        $sort = ListSort::resolve($request, $sortable, default: 'created_at');

        $query = Lease::query()
            ->with(['tenant:id,first_name,last_name', 'unit.property:id,name'])
            ->when($request->string('status')->value(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('search')->trim()->value(), function ($query, string $search) {
                $query->whereHas('tenant', fn ($q) => $q
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"));
            });

        $leases = ListSort::apply($query, $sort, $sortable)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Lease $lease) => [
                'id' => $lease->id,
                'tenant' => $lease->tenant?->fullName(),
                'property' => $lease->unit?->property?->name,
                'unit' => $lease->unit?->unit_number,
                'status' => $lease->status,
                'start_date' => $lease->start_date?->toDateString(),
                'end_date' => $lease->end_date?->toDateString(),
                'total_contract_rent' => (string) $lease->total_contract_rent,
                'tenant_portion' => (string) $lease->tenant_portion,
                'is_subsidised' => $lease->is_subsidised,
                // The list defaults to newest-first, so the date it sorts by has
                // to be visible or the order looks arbitrary (WP-38).
                'created_at' => $lease->created_at?->toDateString(),
            ]);

        return Inertia::render('Admin/Leases/Index', [
            'leases' => $leases,
            'filters' => $request->only(['search', 'status']),
            'sort' => $sort,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Leases/Form', [
            'lease' => null,
            'preselectedTenantId' => $request->integer('tenant_id') ?: null,
            ...$this->formOptions(),
        ]);
    }

    public function store(LeaseRequest $request): RedirectResponse
    {
        $lease = $this->leases->create($request->attributesForModel());

        return redirect()
            ->route('admin.leases.edit', $lease)
            ->with('status', 'Lease created.');
    }

    public function edit(Lease $lease): Response
    {
        return Inertia::render('Admin/Leases/Form', [
            'lease' => [
                ...$lease->only([
                    'id', 'unit_id', 'tenant_id', 'housing_authority_id',
                    'rent_due_day', 'grace_period_days', 'is_subsidised',
                    'hap_contract_number', 'utility_responsibility',
                    'partial_payment_policy', 'partial_requires_approval',
                    'ledger_review_required', 'status',
                ]),
                'start_date' => $lease->start_date?->toDateString(),
                'end_date' => $lease->end_date?->toDateString(),
                'partial_policy_expires_on' => $lease->partial_policy_expires_on?->toDateString(),
                // Money crosses as decimal strings, never numbers (I-10).
                'total_contract_rent' => (string) $lease->total_contract_rent,
                'tenant_portion' => (string) $lease->tenant_portion,
                'ha_portion' => (string) $lease->ha_portion,
                'late_fee_flat' => (string) $lease->late_fee_flat,
                'late_fee_daily' => (string) $lease->late_fee_daily,
                'late_fee_max' => $lease->late_fee_max ? (string) $lease->late_fee_max : '',
                'returned_payment_fee' => (string) $lease->returned_payment_fee,
                'security_deposit' => (string) $lease->security_deposit,
                'partial_minimum_amount' => $lease->partial_minimum_amount ? (string) $lease->partial_minimum_amount : '',
                // FS §18.4: the version this form was rendered against.
                'lock_version' => $lease->lockVersion(),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(LeaseRequest $request, Lease $lease): RedirectResponse
    {
        $this->leases->update($lease, $request->attributesForModel());

        return redirect()
            ->route('admin.leases.edit', $lease)
            ->with('status', 'Changes saved.');
    }

    /** FR-REG-03. */
    public function terminate(Request $request, Lease $lease, BusinessCalendar $calendar): RedirectResponse
    {
        $validated = $request->validate([
            'effective_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->leases->terminate(
            $lease,
            CarbonImmutable::parse($validated['effective_date'], $calendar->timezone())->startOfDay(),
            $validated['reason'] ?? null,
        );

        return back()->with(
            'status',
            'Lease ended. No further charges will post. Any outstanding balance remains on the account.',
        );
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'tenants' => Tenant::query()
                ->where('status', 'active')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->fullName()]),

            // Grouped by property rather than a flat list of units, and ordered
            // by NAME rather than property_id. A flat list ordered by insertion
            // hides a newly added property twice over: at the bottom once it
            // has a unit, and entirely until then, because a property with no
            // units contributes no options at all. Sending the property even
            // when it is empty lets the form say so instead of omitting it.
            'properties' => Property::query()
                ->with(['units' => fn ($q) => $q->orderBy('unit_number')])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'units' => $p->units
                        ->map(fn ($u) => $u->only(['id', 'unit_number', 'status']))
                        ->all(),
                ]),

            'authorities' => HousingAuthority::orderBy('name')->get(['id', 'name']),
        ];
    }
}
