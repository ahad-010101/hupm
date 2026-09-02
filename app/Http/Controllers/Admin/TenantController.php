<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\InvitationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TenantRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ListSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenants.  [FR-REG-02, API-ADM-05…07]
 */
class TenantController extends Controller
{
    /**
     * Sortable columns.  [WP-38]
     *
     * `account_status` and `unit` are deliberately absent: both are resolved
     * per row through relations in the `through()` closure below, so ordering
     * by them would mean a join that changes what the page costs. They stay
     * inert headers rather than pretending to sort.
     *
     * @var array<string, string|\Closure>
     */
    private const SORTABLE = [
        'created_at' => 'created_at',
        // Surname first, then forename — one visible "Name" column, two columns
        // underneath it, which is how a name actually sorts.
        'name' => null,
        'email' => 'email',
        'status' => 'status',
    ];

    public function index(Request $request): Response
    {
        $sortable = [...self::SORTABLE, 'name' => fn ($q, string $d) => $q->orderBy('last_name', $d)->orderBy('first_name', $d)];

        // Newest first: a tenant added a moment ago should not land in the
        // middle of the alphabet where nobody looks for them.
        $sort = ListSort::resolve($request, $sortable, default: 'created_at');

        $query = Tenant::query()
            ->with(['users:id,tenant_id,status', 'leases' => fn ($q) => $q->where('status', 'active')->with('unit.property:id,name')])
            ->when($request->string('search')->trim()->value(), function ($query, string $search) {
                $query->where(fn ($q) => $q
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%"));
            })
            ->when($request->string('status')->value(), fn ($q, $s) => $q->where('status', $s));

        $tenants = ListSort::apply($query, $sort, $sortable)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->fullName(),
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'status' => $tenant->status,
                // Q-4 made visible: a tenant with no address can never be
                // emailed, and admin needs to see that in the list rather than
                // discover it when a notice silently goes nowhere.
                'contactable' => $tenant->isContactable(),
                'account_status' => $tenant->users->first()?->status,
                'unit' => $tenant->leases->first()?->unit?->property?->name,
                // The list defaults to newest-first, so the date it sorts by has
                // to be visible or the order looks arbitrary (WP-38).
                'created_at' => $tenant->created_at?->toDateString(),
            ]);

        return Inertia::render('Admin/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => $request->only(['search', 'status']),
            'sort' => $sort,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Tenants/Form', ['tenant' => null]);
    }

    public function store(TenantRequest $request, AuditLogger $audit): RedirectResponse
    {
        $tenant = Tenant::create($request->attributesForModel());

        $audit->record('tenant.created', $tenant, $request->attributesForModel());

        return redirect()
            ->route('admin.tenants.show', $tenant)
            ->with('status', "{$tenant->fullName()} was added.");
    }

    public function show(Tenant $tenant): Response
    {
        $tenant->load([
            'users:id,tenant_id,email,status,last_login_at',
            'leases.unit.property:id,name',
        ]);

        return Inertia::render('Admin/Tenants/Show', [
            'tenant' => [
                ...$tenant->only([
                    'id', 'first_name', 'last_name', 'email', 'phone',
                    'emergency_contact_name', 'emergency_contact_phone', 'notes', 'status',
                ]),
                'name' => $tenant->fullName(),
                'contactable' => $tenant->isContactable(),
                // Whether they may be archived is a data question, and the
                // answer decides what the action says as well as whether it
                // fires — the button is always present, never merely absent.
                'archivable' => $tenant->isDeletable(),
            ],
            'account' => $tenant->users->first()?->only(['id', 'email', 'status', 'last_login_at']),
            'leases' => $tenant->leases->map(fn ($lease) => [
                'id' => $lease->id,
                'status' => $lease->status,
                'start_date' => $lease->start_date?->toDateString(),
                'end_date' => $lease->end_date?->toDateString(),
                // I-4 does not apply here: this is the ADMIN console, where the
                // housing authority portion is legitimately visible (§5.3).
                'tenant_portion' => (string) $lease->tenant_portion,
                'ha_portion' => (string) $lease->ha_portion,
                'unit' => $lease->unit?->unit_number,
                'property' => $lease->unit?->property?->name,
            ]),
        ]);
    }

    public function edit(Tenant $tenant): Response
    {
        return Inertia::render('Admin/Tenants/Form', ['tenant' => $tenant]);
    }

    public function update(TenantRequest $request, Tenant $tenant, AuditLogger $audit): RedirectResponse
    {
        $tenant->fill($request->attributesForModel());
        $audit->recordChange('tenant.updated', $tenant);
        $tenant->save();

        return redirect()
            ->route('admin.tenants.show', $tenant)
            ->with('status', 'Changes saved.');
    }

    /**
     * Issue or re-issue portal access.  [API-ADM-07, FR-AUTH-02]
     *
     * A tenant with no email address has no account and no way to get one
     * (Q-4). That is a legitimate state, not an error — the action reports it
     * plainly instead of failing.
     */
    public function invite(Tenant $tenant, InvitationService $invitations): RedirectResponse
    {
        if (! $tenant->isContactable()) {
            return back()->withErrors([
                'invite' => 'This tenant has no email address, so a portal account cannot be created. Add one first, or continue to serve them by phone.',
            ]);
        }

        $existing = $tenant->users()->first();

        if ($existing && $existing->status === User::STATUS_ACTIVE) {
            return back()->withErrors([
                'invite' => 'This tenant already has an active account. Use password reset if they cannot sign in.',
            ]);
        }

        if ($existing) {
            // Already invited — resend rather than create a second account.
            $invitations->sendSetPasswordLink($existing);
        } else {
            $invitations->invite($tenant->id, $tenant->fullName(), $tenant->email);
        }

        return back()->with('status', "A set-up link has been emailed to {$tenant->email}.");
    }

    /**
     * Soft delete. Tenants and vendors are the only soft-deleted entities
     * (DB §A4) because financial history has to survive a move-out.
     */
    public function destroy(Tenant $tenant, AuditLogger $audit): RedirectResponse
    {
        if (! $tenant->isDeletable()) {
            return back()->withErrors([
                'tenant' => 'This tenant has an active lease. End the lease before archiving them.',
            ]);
        }

        DB::transaction(function () use ($tenant, $audit) {
            $audit->record('tenant.archived', $tenant, $tenant->only(['first_name', 'last_name', 'email']));

            // The tenant row soft-deletes, but `users` does not — so without
            // this the login survives its own subject. `User::tenant()` is a
            // plain belongsTo, so the portal would then read a null tenant on
            // every page. LoginRequest already refuses any non-active status,
            // which makes suspension the whole fix rather than a new auth path.
            $tenant->users()->update(['status' => User::STATUS_SUSPENDED]);

            $tenant->delete();
        });

        return redirect()
            ->route('admin.tenants.index')
            ->with('status', 'Tenant archived. Their history is retained and their portal sign-in is disabled.');
    }
}
