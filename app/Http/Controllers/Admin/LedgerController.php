<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Domain\Payments\AllocationOrderRegistry;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        private readonly AllocationOrderRegistry $orders,
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
            // Which waterfall is running (Q-1). Shown rather than assumed —
            // "why did my $200 pay the fee and not the rent" has one answer and
            // it is a setting.
            'allocationOrder' => $this->orders->current()->label(),
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

        [$byCharge, $byPayment] = $this->allocationsFor($tenant);
        $running = ['tenant' => Money::zero(), 'housing_authority' => Money::zero()];

        return $entries->map(function (LedgerEntry $entry) use (&$running, $byCharge, $byPayment) {
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
                // FR-LED-03. What paid this charge, or what this payment paid.
                ...$this->allocationFieldsFor($entry, $byCharge, $byPayment),
            ];
        })->all();
    }

    /**
     * Every allocation touching this tenant, indexed both ways.
     *
     * One query rather than one per row: the ledger view is the screen most
     * likely to be left open on a tenant with three years of history.
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function allocationsFor(Tenant $tenant): array
    {
        $rows = DB::table('payment_allocations as a')
            ->join('ledger_entries as charge', 'charge.id', '=', 'a.charge_entry_id')
            ->where('charge.tenant_id', $tenant->id)
            ->orderBy('a.id')
            ->get([
                'a.payment_id', 'a.charge_entry_id', 'a.amount', 'a.reversed_at',
                'charge.description as charge_description',
            ]);

        return [$rows->groupBy('charge_entry_id'), $rows->groupBy('payment_id')];
    }

    /**
     * @param  Collection  $byCharge
     * @param  Collection  $byPayment
     * @return array<string, mixed>
     */
    private function allocationFieldsFor(LedgerEntry $entry, $byCharge, $byPayment): array
    {
        if ($entry->type === 'payment') {
            $applied = $byPayment->get($entry->payment_id, collect())
                ->reject(fn ($a) => $a->reversed_at !== null);

            // The unapplied part is a credit sitting on the account. It is
            // already in the balance; it just has not met a charge yet.
            $total = Money::sum(
                $applied->map(fn ($a) => Money::fromString((string) $a->amount))->all()
            );

            return [
                'applied_to' => $applied
                    ->map(fn ($a) => [
                        'charge_entry_id' => $a->charge_entry_id,
                        'description' => $a->charge_description,
                        'amount' => (string) Money::fromString((string) $a->amount),
                    ])
                    ->values()
                    ->all(),
                'unapplied' => (string) $entry->amount->absolute()->minus($total),
            ];
        }

        if (! in_array($entry->type, ['charge', 'adjustment'], true) || ! $entry->amount->isPositive()) {
            return [];
        }

        $live = $byCharge->get($entry->id, collect())->reject(fn ($a) => $a->reversed_at !== null);

        $allocated = Money::sum($live->map(fn ($a) => Money::fromString((string) $a->amount))->all());

        return [
            'allocated' => (string) $allocated,
            'outstanding' => (string) $entry->amount->minus($allocated),
            'paid_by' => $live->pluck('payment_id')->unique()->values()->all(),
        ];
    }
}
