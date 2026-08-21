<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Delinquency\DelinquencyService;
use App\Domain\Ledger\BalanceCalculator;
use App\Http\Controllers\Controller;
use App\Jobs\EvaluateDelinquency;
use App\Models\Lease;
use App\Models\RecurringPayment;
use App\Support\AuditLogger;
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
        private readonly AuditLogger $audit,
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

            // How many would enter if the rule ran right now. Without this the
            // button is a mystery box: you press it and either something
            // happens or nothing does, with no way to tell which was correct.
            'waiting' => Lease::query()
                ->where('status', Lease::STATUS_ACTIVE)
                ->where('delinquency_state', DelinquencyService::STATE_CURRENT)
                ->get()
                ->filter(fn (Lease $lease) => $this->delinquency->shouldEnterReview($lease))
                ->count(),

            // When the rule last ran at all. An empty answer here is how you
            // find out the scheduler is not running — the same failure that
            // hides a stopped reconciliation, and just as quiet.
            'lastEvaluated' => DB::table('job_runs')
                ->where('job_name', EvaluateDelinquency::class)
                ->where('status', 'success')
                ->orderByDesc('finished_at')
                ->value('finished_at'),

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

    /**
     * Evaluate now, rather than waiting for 02:30.
     *
     * The nightly job is the normal path; this is for the morning after a cron
     * that did not fire, and for anyone who needs to see the rule work rather
     * than take it on trust. It runs **the job**, not the service, so the run
     * lands in `job_runs` and the screen's "last evaluated" line tells the
     * truth afterwards.
     *
     * Called through the container rather than dispatch_sync(), which would
     * run a copy and report zero however many accounts it moved.
     *
     * Only ever puts accounts in. Releasing stays a manual, per-household act
     * with a reason (BR-14) — there is no code path here or anywhere that takes
     * an account out.
     */
    public function evaluate(Request $request): RedirectResponse
    {
        $job = new EvaluateDelinquency;

        $entered = (int) app()->call([$job, 'handle']);

        // A person chose to run this, which is a different fact from the rule
        // firing overnight, and worth being able to tell apart later.
        $this->audit->record('delinquency.evaluate.manual', null, [
            'entered' => $entered,
            'trigger_day' => $this->delinquency->triggerDay(),
        ]);

        return back()->with('status', $entered === 0
            ? 'Evaluated. No account met the rule today, so nothing changed.'
            : sprintf(
                '%d account%s entered Management Review. Online payment and autopay are off for %s until released.',
                $entered,
                $entered === 1 ? '' : 's',
                $entered === 1 ? 'that account' : 'those accounts',
            ));
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
