<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant ledger for admin.  [WP-12 pulled forward by D-16, UI §3.8]
 *
 * The admin console legitimately shows both payers side by side (§5.3) — it is
 * the tenant portal that must never see the Housing Authority portion (I-4).
 * Two separate balances rather than one combined figure, because they are two
 * separate obligations (BR-01) and adding them together would answer a question
 * nobody asks.
 */
class LedgerController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly BalanceCalculator $balances,
    ) {}

    public function index(): Response
    {
        $tenants = Tenant::query()
            ->with(['leases' => fn ($q) => $q->where('status', 'active')->with('unit.property:id,name')])
            ->orderBy('last_name')
            ->get()
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->fullName(),
                'unit' => $tenant->leases->first()?->unit?->property?->name,
                'tenant_balance' => (string) $this->balances->tenantBalance($tenant->id),
                'ha_balance' => (string) $this->balances->haBalance($tenant->id),
            ]);

        return Inertia::render('Admin/Ledger/Index', ['tenants' => $tenants]);
    }

    public function show(Tenant $tenant): Response
    {
        $lease = $tenant->activeLease();

        return Inertia::render('Admin/Ledger/Show', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->fullName(),
                'unit' => $lease?->unit?->unit_number,
                'property' => $lease?->unit?->property?->name,
            ],
            'hasActiveLease' => $lease !== null,
            'balances' => [
                'tenant' => (string) $this->balances->tenantBalance($tenant->id),
                'ha' => (string) $this->balances->haBalance($tenant->id),
                'pending' => (string) $this->balances->pendingPayments($tenant->id),
            ],
            'entries' => $this->entriesFor($tenant),
        ]);
    }

    /** FR-LED-04. Reason is mandatory (AC-LED-07) — enforced here and in the service. */
    public function adjust(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'payer' => ['required', 'in:tenant,housing_authority'],
            'amount' => ['required', 'numeric', 'not_in:0', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'description' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'An adjustment needs a reason. It becomes part of the permanent record.',
            'amount.not_in' => 'An adjustment of zero changes nothing.',
        ]);

        $lease = $tenant->activeLease();

        if (! $lease) {
            throw ValidationException::withMessages([
                'amount' => 'This tenant has no active lease, so there is nothing to adjust against.',
            ]);
        }

        $this->ledger->postAdjustment(
            $lease,
            $validated['payer'],
            Money::fromString($validated['amount']),
            $validated['reason'],
            $validated['description'],
        );

        return back()->with('status', 'Adjustment posted.');
    }

    /** FR-LED-04, AC-LED-08. Both rows stay visible afterwards. */
    public function reverse(Request $request, Tenant $tenant, LedgerEntry $entry): RedirectResponse
    {
        abort_unless($entry->tenant_id === $tenant->id, 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'A reversal needs a reason. The original entry stays visible alongside it.',
        ]);

        try {
            $this->ledger->reverse($entry, $validated['reason']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Reversing entry posted. The original remains on the ledger.');
    }

    /** @return array<int, array<string, mixed>> */
    private function entriesFor(Tenant $tenant): array
    {
        // Both payers, interleaved by date, each row labelled — colour alone is
        // never the signal (UI §9).
        $entries = LedgerEntry::query()
            ->where('tenant_id', $tenant->id)
            ->with('reversal:id,reverses_entry_id')
            ->orderBy('posted_on')
            ->orderBy('id')
            ->get();

        $running = ['tenant' => Money::zero(), 'housing_authority' => Money::zero()];

        return $entries->map(function (LedgerEntry $entry) use (&$running) {
            if ($entry->affectsBalance()) {
                $running[$entry->payer] = $running[$entry->payer]->plus($entry->amount);
            }

            return [
                'id' => $entry->id,
                'posted_on' => $entry->posted_on?->toDateString(),
                'type' => $entry->type,
                'category' => $entry->category,
                'payer' => $entry->payer,
                'description' => $entry->description,
                'reason' => $entry->reason,
                'amount' => (string) $entry->amount,
                'status' => $entry->status,
                'counts' => $entry->affectsBalance(),
                'running' => (string) $running[$entry->payer],
                'reverses_entry_id' => $entry->reverses_entry_id,
                // Drives whether the control renders at all, rather than
                // offering an action that will be refused.
                'is_reversed' => $entry->reversal !== null,
                'can_reverse' => $entry->type !== 'reversal' && $entry->reversal === null,
            ];
        })->all();
    }
}
