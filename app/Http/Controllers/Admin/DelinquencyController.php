<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Delinquency\DelinquencyService;
use App\Domain\Ledger\BalanceCalculator;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\RecurringPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Management Review queue.  [API-ADM-17/18, FR-DEL-03]
 *
 * Release is the only write here, it is manual, and its reason is mandatory
 * (BR-14, AC-DEL-05). There is no bulk release and no "release all": letting
 * somebody out is a decision about one household.
 */
class DelinquencyController extends Controller
{
    public function __construct(
        private readonly DelinquencyService $delinquency,
        private readonly BalanceCalculator $balances,
    ) {}

    /** API-ADM-17. */
    public function index(): Response
    {
        $leases = Lease::query()
            ->where('delinquency_state', DelinquencyService::STATE_REVIEW)
            ->with(['tenant:id,first_name,last_name,email,phone', 'unit.property:id,name'])
            ->orderBy('delinquency_since')
            ->get();

        return Inertia::render('Admin/Delinquency/Index', [
            'accounts' => $leases->map(fn (Lease $lease) => [
                'lease_id' => $lease->id,
                'tenant_id' => $lease->tenant_id,
                'tenant' => $lease->tenant?->fullName(),
                'phone' => $lease->tenant?->phone,
                'property' => $lease->unit?->property?->name,
                'unit' => $lease->unit?->unit_number,
                'balance' => (string) $this->balances->tenantBalance($lease->tenant_id),
                'pending' => (string) $this->balances->pendingPayments($lease->tenant_id),
                'since' => $lease->delinquency_since?->format('j M Y'),
                'days' => $lease->delinquency_since
                    ? (int) $lease->delinquency_since->diffInDays(now())
                    : null,
                'autopay_suspended' => RecurringPayment::where('lease_id', $lease->id)
                    ->where('status', RecurringPayment::STATUS_SUSPENDED)->exists(),
                'history' => DB::table('delinquency_events')
                    ->where('lease_id', $lease->id)
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get(['from_state', 'to_state', 'reason', 'balance_at_event', 'actor_user_id', 'created_at'])
                    ->map(fn ($e) => [
                        'from' => $e->from_state,
                        'to' => $e->to_state,
                        'reason' => $e->reason,
                        'balance' => $e->balance_at_event,
                        // NULL actor means the nightly job, which is worth
                        // showing: "nobody chose this, a rule did".
                        'by' => $e->actor_user_id ? 'an administrator' : 'the system',
                        'at' => $e->created_at,
                    ])->all(),
            ])->all(),
            'triggerDay' => $this->delinquency->triggerDay(),
            // Leases whose own grace outlasts the trigger. They lose online
            // payment before their lease says they are late.
            'insideGrace' => $this->delinquency->leasesTriggeringInsideGrace()
                ->map(fn (Lease $lease) => [
                    'lease_id' => $lease->id,
                    'tenant' => $lease->tenant?->fullName(),
                    'grace_days' => (int) $lease->grace_period_days,
                ])->all(),
        ]);
    }

    /** API-ADM-18. */
    public function release(Request $request, Lease $lease): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'A release needs a reason. It becomes part of the account history.',
        ]);

        try {
            $this->delinquency->release($lease, $validated['reason'], $request->user());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            'Released. Autopay stays switched off until it is re-enabled explicitly.',
        );
    }
}
