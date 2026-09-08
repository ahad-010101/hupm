<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\InvitationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HousingAuthorityRequest;
use App\Models\HousingAuthority;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Housing authorities.  [FR-REG-01, GATE Q-2]
 */
class HousingAuthorityController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/HousingAuthorities/Index', [
            'authorities' => HousingAuthority::query()
                ->withCount('leases')
                ->with('users:id,housing_authority_id,status')
                ->orderBy('name')
                ->get()
                ->map(fn ($a) => [
                    ...$a->only(['id', 'name', 'contact_name', 'contact_email', 'contact_phone', 'remittance_type']),
                    'leases_count' => $a->leases_count,
                    'is_deletable' => $a->leases_count === 0,
                    // [WP-43] Whether they can reach the portal, and how far
                    // along. Null means no account has been created at all.
                    'account_status' => $a->users->first()?->status,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/HousingAuthorities/Form', ['authority' => null]);
    }

    public function store(HousingAuthorityRequest $request, AuditLogger $audit): RedirectResponse
    {
        $authority = HousingAuthority::create($request->attributesForModel());

        $audit->record('housing_authority.created', $authority, $request->attributesForModel());

        return redirect()
            ->route('admin.housing-authorities.index')
            ->with('status', "{$authority->name} was added.");
    }

    public function edit(HousingAuthority $housingAuthority): Response
    {
        return Inertia::render('Admin/HousingAuthorities/Form', ['authority' => $housingAuthority]);
    }

    public function update(
        HousingAuthorityRequest $request,
        HousingAuthority $housingAuthority,
        AuditLogger $audit,
    ): RedirectResponse {
        $housingAuthority->fill($request->attributesForModel());

        // Changing remittance_type on an authority with live leases changes how
        // its money is matched (Q-2, R-9), so the transition is audited
        // explicitly rather than lost among the other field changes.
        $audit->recordChange('housing_authority.updated', $housingAuthority);
        $housingAuthority->save();

        return redirect()
            ->route('admin.housing-authorities.index')
            ->with('status', 'Changes saved.');
    }

    public function destroy(HousingAuthority $housingAuthority, AuditLogger $audit): RedirectResponse
    {
        if (! $housingAuthority->isDeletable()) {
            return back()->withErrors([
                'authority' => 'This authority is attached to one or more leases and cannot be removed.',
            ]);
        }

        $audit->record('housing_authority.deleted', $housingAuthority, $housingAuthority->only(['name']));
        $housingAuthority->delete();

        return redirect()
            ->route('admin.housing-authorities.index')
            ->with('status', 'Housing authority removed.');
    }

    /**
     * Give an agency a login.  [WP-43]
     *
     * Same flow as a resident: a link, a password they choose, nothing set by
     * us. An agency with no contact email has no account and no way to get one
     * — a legitimate state, not a fault.
     */
    public function invite(HousingAuthority $housingAuthority, InvitationService $invitations): RedirectResponse
    {
        if (! $housingAuthority->contact_email) {
            return back()->withErrors([
                'invite' => "{$housingAuthority->name} has no contact email address, so an account "
                    .'cannot be created. Add one first.',
            ]);
        }

        $existing = User::where('housing_authority_id', $housingAuthority->id)->first();

        if ($existing?->status === User::STATUS_ACTIVE) {
            return back()->withErrors([
                'invite' => "{$housingAuthority->name} already has an account. "
                    .'Use password reset if they cannot sign in.',
            ]);
        }

        if ($existing) {
            // Resend rather than create a second account.
            $invitations->sendSetPasswordLink($existing);
        } else {
            $invitations->inviteHousingAuthority(
                $housingAuthority->id,
                $housingAuthority->contact_name ?: $housingAuthority->name,
                $housingAuthority->contact_email,
            );
        }

        return back()->with('status', "Sent {$housingAuthority->name} a link to set their password.");
    }
}
