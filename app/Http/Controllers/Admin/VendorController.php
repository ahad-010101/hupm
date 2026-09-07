<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\InvitationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VendorRequest;
use App\Models\MaintenanceRequest;
use App\Models\User;
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

    /**
     * Give a contractor a login.  [WP-44, reverses NG-6]
     *
     * WP-37 built contractors as records and asserted they would never have
     * accounts. The client asked for the portal on 5 Sep 2026 and that decision
     * is reversed deliberately — see WP-44 in the plan, and the rewritten test
     * in VendorTest.
     *
     * A contractor with no email address has no account and no way to get one,
     * exactly as a resident does (Q-4). That is a state, not a fault.
     */
    public function invite(Vendor $vendor, InvitationService $invitations): RedirectResponse
    {
        if (! $vendor->email) {
            return back()->withErrors([
                'invite' => "{$vendor->name} has no email address, so an account cannot be created. "
                    .'Add one first, or keep telephoning them.',
            ]);
        }

        $existing = User::where('vendor_id', $vendor->id)->first();

        if ($existing?->status === User::STATUS_ACTIVE) {
            return back()->withErrors([
                'invite' => "{$vendor->name} already has an account. Use password reset if they cannot sign in.",
            ]);
        }

        if ($existing) {
            // Resend rather than create a second account.
            $invitations->sendSetPasswordLink($existing);
        } else {
            $invitations->inviteVendor($vendor->id, $vendor->name, $vendor->email);
        }

        return back()->with('status', "Sent {$vendor->name} a link to set their password.");
    }
}
