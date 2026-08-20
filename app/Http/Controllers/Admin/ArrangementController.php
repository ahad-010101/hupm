<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Arrangements\ArrangementService;
use App\Domain\Ledger\BalanceCalculator;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\PaymentArrangement;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment arrangements.  [API-ADM-19/20, FR-ARR-01/02]
 *
 * Also the home of the **[GATE C1/Q-1]** ledger review: "full ledger required"
 * is undefined in the source, and the shipped interpretation is that an admin
 * marks a resident's ledger reviewed for the current period before a part
 * payment is accepted. Doing that from here rather than from the lease form is
 * deliberate — it is an act about an account at a moment, not a setting.
 */
class ArrangementController extends Controller
{
    public function __construct(
        private readonly ArrangementService $arrangements,
        private readonly BalanceCalculator $balances,
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /** API-ADM-19. */
    public function index(): Response
    {
        return Inertia::render('Admin/Arrangements/Index', [
            'arrangements' => PaymentArrangement::query()
                ->with(['lease.tenant:id,first_name,last_name', 'document:id,title'])
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn (PaymentArrangement $a) => [
                    'id' => $a->id,
                    'tenant' => $a->lease?->tenant?->fullName(),
                    'tenant_id' => $a->lease?->tenant_id,
                    'lease_id' => $a->lease_id,
                    'status' => $a->status,
                    'total_owed' => (string) $a->total_owed,
                    'amount_accepted' => (string) $a->amount_accepted,
                    'remaining' => (string) $a->remaining_balance,
                    'instalments' => count($a->schedule_json ?? []),
                    'late_fees_continue' => $a->late_fees_continue,
                    'document_id' => $a->document_id,
                    'created_on' => $a->created_at?->format('j M Y'),
                ])->all(),

            'leases' => Lease::query()
                ->where('status', Lease::STATUS_ACTIVE)
                ->with(['tenant:id,first_name,last_name', 'unit.property:id,name'])
                ->get()
                ->map(fn (Lease $lease) => [
                    'id' => $lease->id,
                    'tenant' => $lease->tenant?->fullName(),
                    'label' => sprintf(
                        '%s — %s unit %s',
                        $lease->tenant?->fullName(),
                        $lease->unit?->property?->name,
                        $lease->unit?->unit_number,
                    ),
                    'balance' => (string) $this->balances->tenantBalance($lease->tenant_id),
                    'policy' => $lease->partial_payment_policy,
                    // [GATE C1/Q-1] surfaced where it is acted on.
                    'ledger_review_required' => (bool) $lease->ledger_review_required,
                    'ledger_reviewed' => $lease->ledger_reviewed_period === $this->calendar->currentPeriod(),
                ])
                ->sortBy('label')
                ->values()
                ->all(),

            'currentPeriod' => $this->calendar->currentPeriod(),
        ]);
    }

    /** API-ADM-19 (POST). Drafts; approval is a separate, deliberate act. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'lease_id' => ['required', 'exists:leases,id'],
            'amount_accepted' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'late_fees_continue' => ['boolean'],
            'default_terms' => ['required', 'string', 'min:10', 'max:2000'],
            'schedule' => ['array'],
            'schedule.*.due_on' => ['required', 'date'],
            'schedule.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ], [
            'default_terms.required' => 'The agreement has to say what happens if a payment is missed.',
        ]);

        try {
            $this->arrangements->draft(
                Lease::findOrFail($validated['lease_id']),
                Money::fromString($validated['amount_accepted']),
                $validated['schedule'] ?? [],
                $request->boolean('late_fees_continue', true),
                $validated['default_terms'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount_accepted' => $e->getMessage()]);
        }

        return back()->with('status', 'Arrangement drafted. Review it, then approve to generate the agreement.');
    }

    /** API-ADM-20. Generates the BR-19 agreement and routes it for signature. */
    public function approve(Request $request, PaymentArrangement $arrangement): RedirectResponse
    {
        try {
            $this->arrangements->approve($arrangement, $request->user());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['arrangement' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            'Agreement generated, filed in the resident documents and sent for signature.',
        );
    }

    public function markDefaulted(Request $request, PaymentArrangement $arrangement): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'Recording a default needs a reason. It goes on the account history.',
        ]);

        $this->arrangements->markDefaulted($arrangement, $validated['reason'], $request->user());

        return back()->with('status', 'Arrangement recorded as defaulted.');
    }

    /**
     * [GATE C1/Q-1] Mark a resident's ledger reviewed for this period.
     *
     * An act about an account at a moment, not a setting — which is why it is
     * here and not on the lease form, and why it records who and when.
     */
    public function reviewLedger(Request $request, Lease $lease): RedirectResponse
    {
        $lease->forceFill([
            'ledger_reviewed_period' => $this->calendar->currentPeriod(),
            'ledger_reviewed_by_user_id' => $request->user()->id,
            'ledger_reviewed_at' => now(),
        ])->save();

        $this->audit->record('arrangement.ledger.reviewed', $lease, [
            'period' => $this->calendar->currentPeriod(),
        ]);

        return back()->with('status', 'Ledger marked reviewed for this period. Part payments are now accepted.');
    }
}
