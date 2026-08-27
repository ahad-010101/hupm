<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VendorRequest;
use App\Models\MaintenanceRequest;
use App\Models\Vendor;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contractors.  [API-ADM-38, FR-MNT-03, NG-6]
 *
 * The list the maintenance screen assigns from. Until this existed that
 * dropdown had nothing in it and said "No contractors on file yet" for ever —
 * the assign flow was built, tested and unreachable.
 *
 * A vendor is a data record, not an account. There is no vendor portal in v1
 * (NG-6): nothing here authenticates, and the email address is somewhere to
 * send a job rather than something to sign in with.
 */
class VendorController extends Controller
{
    /** API-ADM-38. */
    public function index(Request $request): Response
    {
        $showInactive = $request->boolean('inactive');

        return Inertia::render('Admin/Vendors/Index', [
            'vendors' => Vendor::query()
                ->when(! $showInactive, fn ($q) => $q->where('active', true))
                // Open work per contractor, so "can I stop calling them?" is
                // answerable on the screen where somebody asks it.
                ->withCount(['requests as open_requests_count' => fn ($q) => $q->whereNotIn('status', [
                    MaintenanceRequest::STATUS_CLOSED,
                    MaintenanceRequest::STATUS_CANCELLED,
                ])])
                ->withCount('requests')
                ->orderBy('name')
                ->get()
                ->map(fn (Vendor $vendor) => [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'trade' => $vendor->trade,
                    'phone' => $vendor->phone,
                    'email' => $vendor->email,
                    'notes' => $vendor->notes,
                    'active' => $vendor->active,
                    'open_requests' => $vendor->open_requests_count,
                    'total_requests' => $vendor->requests_count,
                ])
                ->all(),
            'filters' => ['inactive' => $showInactive],
            'inactiveCount' => Vendor::where('active', false)->count(),
        ]);
    }

    public function store(VendorRequest $request, AuditLogger $audit): RedirectResponse
    {
        $vendor = Vendor::create($request->attributesForModel());

        $audit->record('vendor.created', $vendor, $request->attributesForModel());

        return back()->with('status', "{$vendor->name} was added.");
    }

    public function update(VendorRequest $request, Vendor $vendor, AuditLogger $audit): RedirectResponse
    {
        $vendor->fill($request->attributesForModel());

        // Before save(): recordChange reads getDirty().
        $audit->recordChange('vendor.updated', $vendor);

        $vendor->save();

        return back()->with('status', 'Changes saved.');
    }

    /**
     * Remove a contractor.
     *
     * Soft-deleted, always — a vendor named on a ticket from last year has to
     * stay resolvable long after they stop being someone we call, or the
     * maintenance history starts showing blanks where a name should be.
     *
     * Refused while they have open work. Deleting somebody mid-job does not
     * cancel the job; it just removes the only record of who is doing it.
     */
    public function destroy(Vendor $vendor, AuditLogger $audit): RedirectResponse
    {
        $open = $vendor->requests()
            ->whereNotIn('status', [
                MaintenanceRequest::STATUS_CLOSED,
                MaintenanceRequest::STATUS_CANCELLED,
            ])
            ->count();

        if ($open > 0) {
            return back()->withErrors([
                'vendor' => "{$vendor->name} has {$open} open ".($open === 1 ? 'request' : 'requests')
                    .'. Reassign or close those first, or mark the contractor inactive instead.',
            ]);
        }

        $audit->record('vendor.removed', $vendor, ['name' => $vendor->name]);

        $vendor->delete();

        return back()->with('status', "{$vendor->name} was removed.");
    }
}
