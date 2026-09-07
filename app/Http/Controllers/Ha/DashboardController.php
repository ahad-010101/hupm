<?php

namespace App\Http\Controllers\Ha;

use App\Domain\Ledger\BalanceCalculator;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What an agency owes, lease by lease.  [WP-43, D-29]
 *
 * **[D-29 — the mirror of I-4]** I-4 says a tenant never sees the housing
 * authority's portion. The inverse matters as much and was never written down:
 * whether a resident is behind on *their own* rent is not the agency's
 * business, and putting it on this screen would disclose a resident's financial
 * standing to a third party.
 *
 * So `payer = 'housing_authority'` is not a filter applied to a wider query
 * here — it is the only thing these queries can return, exactly as the resident
 * ledger can only return `payer = tenant` (`Portal/LedgerController:112`). Late
 * fees cannot appear either: I-7 confines them to the tenant.
 *
 * The agency is read from the session and never from the request. There is no
 * route parameter naming an agency, so there is none to get wrong (I-9, BR-20).
 */
class DashboardController extends Controller
{
    public function __construct(private readonly BalanceCalculator $balances) {}

    public function index(Request $request): Response
    {
        $authorityId = $request->user()->housing_authority_id;

        $balances = $this->balances->authorityBalancesByLease($authorityId);

        $leases = Lease::query()
            ->where('housing_authority_id', $authorityId)
            ->where('status', Lease::STATUS_ACTIVE)
            ->with(['tenant:id,first_name,last_name', 'unit:id,unit_number,property_id', 'unit.property:id,name'])
            ->get()
            ->map(fn (Lease $lease) => [
                'id' => $lease->id,
                // The resident's name and address, because a remittance has to
                // be matched to a tenancy at the agency's end too. Nothing
                // about what they personally owe.
                'resident' => $lease->tenant?->fullName(),
                'property' => $lease->unit?->property?->name,
                'unit' => $lease->unit?->unit_number,
                'hap_contract_number' => $lease->hap_contract_number,
                // The agency's own monthly portion, from the lease.
                'monthly_portion' => (string) $lease->ha_portion,
                // Money is compared as Money and crosses as a decimal string —
                // never cast to a float on the way past (I-10).
                'balance' => (string) ($balances[$lease->id] ?? Money::zero()),
                'is_outstanding' => ($balances[$lease->id] ?? Money::zero())->isPositive(),
            ])
            ->sortBy(['property', 'unit'])
            ->values();

        return Inertia::render('Ha/Dashboard', [
            'authority' => $request->user()->housingAuthority?->only(['id', 'name']),
            'leases' => $leases,
            'total' => (string) $this->balances->authorityBalance($authorityId),
            'outstandingCount' => $leases->where('is_outstanding', true)->count(),
        ]);
    }

    /** The agency's own statement — its portion, and nothing else. */
    public function ledger(Request $request): Response
    {
        $authorityId = $request->user()->housing_authority_id;

        $entries = LedgerEntry::query()
            ->join('leases as l', 'l.id', '=', 'ledger_entries.lease_id')
            ->where('l.housing_authority_id', $authorityId)
            // Structural, not a filter: see the class note and D-29.
            ->where('ledger_entries.payer', 'housing_authority')
            ->with(['lease.tenant:id,first_name,last_name', 'lease.unit.property:id,name'])
            ->orderByDesc('ledger_entries.posted_on')
            ->orderByDesc('ledger_entries.id')
            ->select('ledger_entries.*')
            ->limit(500)
            ->get()
            ->map(fn (LedgerEntry $entry) => [
                'id' => $entry->id,
                'posted_on' => $entry->posted_on?->format('j F Y'),
                'description' => $entry->description,
                'resident' => $entry->lease?->tenant?->fullName(),
                'property' => $entry->lease?->unit?->property?->name,
                'amount' => (string) $entry->amount,
                'type' => $entry->type,
                'status' => $entry->status,
            ]);

        return Inertia::render('Ha/Ledger', [
            'authority' => $request->user()->housingAuthority?->only(['id', 'name']),
            'entries' => $entries,
            'total' => (string) $this->balances->authorityBalance($authorityId),
        ]);
    }
}
