<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Payments\AllocationService;
use App\Domain\Payments\PaymentRecordingService;
use App\Domain\Payments\ReconciliationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordPaymentRequest;
use App\Http\Requests\Admin\RecordRemittanceRequest;
use App\Models\HousingAuthority;
use App\Models\Lease;
use App\Models\Payment;
use App\Support\BusinessCalendar;
use App\Support\Money;
use App\Support\ReconciliationHealth;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payments.  [UI §3.9, API-ADM-14/15, FR-PAY-05, WP-12]
 *
 * Two ways money arrives by hand: one cheque for one tenant, and one cheque
 * from a Housing Authority covering many (**GATE Q-2**, risk R-9). They share
 * a service and differ only in how the amount is divided.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentRecordingService $payments,
        private readonly AllocationService $allocations,
        private readonly BusinessCalendar $calendar,
    ) {}

    /** API-ADM-14. */
    public function index(Request $request): Response
    {
        $payments = Payment::query()
            ->with(['tenant:id,first_name,last_name'])
            ->when($request->string('status')->value(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('batch')->value(), fn ($q, $b) => $q->where('batch_id', $b))
            // Pending, known to the gateway, and older than the reconciliation
            // window. Never auto-voided (FR-PAY-04 step 6) — surfaced instead,
            // because it needs a person.
            ->when($request->boolean('unmatched'), fn ($q) => $q
                ->where('status', Payment::STATUS_PENDING)
                ->whereNotNull('gateway_transaction_id')
                ->where('submitted_at', '<', now()->subDays(ReconciliationService::WINDOW_DAYS)))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Payment $payment) => [
                'id' => $payment->id,
                'tenant' => $payment->tenant?->fullName(),
                'tenant_id' => $payment->tenant_id,
                'payer' => $payment->payer,
                'amount' => (string) $payment->amount,
                'unapplied' => (string) $this->allocations->unallocated($payment),
                'method' => $payment->method,
                'gateway' => $payment->gateway,
                'reference' => $payment->reference,
                'batch_id' => $payment->batch_id,
                'status' => $payment->status,
                'received_on' => $payment->submitted_at?->toDateString(),
                'return_code' => $payment->return_code,
                'return_description' => $payment->return_description,
                'flagged' => $payment->webhook_flagged_at !== null,
            ]);

        return Inertia::render('Admin/Payments/Index', [
            'payments' => $payments,
            'filters' => $request->only(['status', 'batch', 'unmatched']),
            // UI §3.9: visible at all times, not only when it is bad news.
            'reconciliation' => app(ReconciliationHealth::class)->status(),
            'unmatchedCount' => Payment::query()
                ->where('status', Payment::STATUS_PENDING)
                ->whereNotNull('gateway_transaction_id')
                ->where('submitted_at', '<', now()->subDays(ReconciliationService::WINDOW_DAYS))
                ->count(),
        ]);
    }

    /** The single-payment form. */
    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Payments/Record', [
            'leases' => $this->activeLeases(),
            'preselectedLeaseId' => $request->integer('lease_id') ?: null,
            'today' => $this->calendar->today()->toDateString(),
            ...$this->freshKey(),
        ]);
    }

    /** API-ADM-15. Works during Management Review — deliberately (BR-12, I-12). */
    public function store(RecordPaymentRequest $request): RedirectResponse
    {
        $lease = Lease::findOrFail($request->integer('lease_id'));

        $payment = $this->payments->record(
            lease: $lease,
            payer: $request->string('payer')->value(),
            amount: Money::fromString($request->string('amount')->value()),
            receivedOn: CarbonImmutable::parse($request->string('received_on')->value(), $this->calendar->timezone()),
            method: $request->string('method')->value(),
            idempotencyKey: $request->string('idempotency_key')->value(),
            reference: $request->input('reference'),
            note: $request->input('note'),
        );

        $unapplied = $this->allocations->unallocated($payment);

        return redirect()
            ->route('admin.ledger.show', $lease->tenant_id)
            ->with('status', $unapplied->isPositive()
                ? "Payment recorded. {$unapplied->format()} is unapplied and shows as a credit."
                : 'Payment recorded and applied.');
    }

    /**
     * API-ADM-16. Reconcile now, by hand.
     *
     * The same service the nightly job calls — not a second implementation.
     * An admin chasing a payment that should have cleared needs the answer the
     * job would have given, not a different one.
     */
    public function reconcile(ReconciliationService $reconciliation): RedirectResponse
    {
        $result = $reconciliation->run();

        $message = sprintf(
            'Reconciliation finished — %d settled, %d returned, %d needing review.',
            $result['settled'],
            $result['returned'],
            $result['unmatched'],
        );

        return $result['errors'] === []
            ? back()->with('status', $message)
            : back()->with('error', $message.' '.count($result['errors']).' could not be read; see the audit log.');
    }

    /** The lump-sum split screen. [GATE Q-2, R-9] */
    public function remittance(Request $request): Response
    {
        $authority = $request->integer('housing_authority_id')
            ? HousingAuthority::find($request->integer('housing_authority_id'))
            : null;

        return Inertia::render('Admin/Payments/Remittance', [
            'authorities' => HousingAuthority::orderBy('name')
                ->get(['id', 'name', 'remittance_type'])
                ->map(fn (HousingAuthority $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'remittance_type' => $a->remittance_type,
                    'is_lump_sum' => $a->remittance_type === HousingAuthority::REMITTANCE_LUMP_SUM,
                ]),
            'authority' => $authority?->only(['id', 'name', 'remittance_type']),
            // Suggested, never applied: what each tenant owes on the authority
            // portion today. Which tenants a cheque actually covers is on the
            // authority's remittance advice, and the system cannot guess it.
            'lines' => $authority ? $this->payments->outstandingFor($authority) : [],
            'today' => $this->calendar->today()->toDateString(),
            ...$this->freshKey(),
        ]);
    }

    public function storeRemittance(RecordRemittanceRequest $request): RedirectResponse
    {
        $authority = HousingAuthority::findOrFail($request->integer('housing_authority_id'));

        try {
            $payments = $this->payments->recordRemittance(
                authority: $authority,
                total: Money::fromString($request->string('total')->value()),
                receivedOn: CarbonImmutable::parse($request->string('received_on')->value(), $this->calendar->timezone()),
                lines: $request->input('lines', []),
                batchKey: $request->string('idempotency_key')->value(),
                reference: $request->input('reference'),
                note: $request->input('note'),
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $batch = $payments->first()?->batch_id;

        return redirect()
            ->route('admin.payments.index', ['batch' => $batch])
            ->with('status', sprintf(
                'Remittance recorded — %s across %d %s.',
                Money::fromString($request->string('total')->value())->format(),
                $payments->count(),
                $payments->count() === 1 ? 'tenant' : 'tenants',
            ));
    }

    /**
     * A key generated per page render, carried in the form.
     *
     * The browser's retry button and a double-click both land on the same
     * payment rather than taking the money twice (risk R-13).
     *
     * @return array<string, string>
     */
    private function freshKey(): array
    {
        return ['idempotencyKey' => (string) Str::uuid()];
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function activeLeases()
    {
        return Lease::query()
            ->where('status', Lease::STATUS_ACTIVE)
            ->with(['tenant:id,first_name,last_name', 'unit.property:id,name'])
            ->get()
            ->map(fn (Lease $lease) => [
                'id' => $lease->id,
                'tenant_id' => $lease->tenant_id,
                'label' => sprintf(
                    '%s — %s unit %s',
                    $lease->tenant?->fullName(),
                    $lease->unit?->property?->name,
                    $lease->unit?->unit_number,
                ),
                'tenant_portion' => (string) $lease->tenant_portion,
                'ha_portion' => (string) $lease->ha_portion,
                'has_authority' => $lease->housing_authority_id !== null,
                // Surfaced rather than blocking: an admin recording a cheque for
                // an account in Management Review is the normal case, not an
                // exception (BR-12, AC-PAY-15).
                'in_management_review' => $lease->delinquency_state === 'management_review',
            ])
            ->sortBy('label')
            ->values();
    }
}
