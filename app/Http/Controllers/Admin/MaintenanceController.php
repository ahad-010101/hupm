<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Maintenance\MaintenanceService;
use App\Domain\Maintenance\TicketBillingService;
use App\Domain\Maintenance\TicketStateMachine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateMaintenanceRequest;
use App\Models\Lease;
use App\Models\MaintenanceAttachment;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\Vendor;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ticket queue.  [UI §3.5, API-ADM-21…25]
 *
 * Built to be worked from a phone. The manager triages between viewings, in a
 * car park, on a small screen — so the queue is a list of cards with the one
 * fact that decides what happens next, not a spreadsheet.
 *
 * Emergencies sort to the top and stay there (AC-MNT-04).
 */
class MaintenanceController extends Controller
{
    public function __construct(
        private readonly MaintenanceService $maintenance,
        private readonly TicketStateMachine $states,
        private readonly TicketBillingService $billing,
    ) {}

    /**
     * The form for raising a repair on somebody's behalf.  [WP-46]
     *
     * Active leases only — a ticket against an ended tenancy has no unit to
     * attend and no resident to tell.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Maintenance/Create', [
            'categories' => Ticket::CATEGORIES,

            'leases' => Lease::query()
                ->where('status', Lease::STATUS_ACTIVE)
                ->with(['tenant:id,first_name,last_name,phone', 'unit:id,unit_number,property_id', 'unit.property:id,name'])
                ->get()
                ->map(fn (Lease $lease) => [
                    'id' => $lease->id,
                    'tenant' => $lease->tenant?->fullName(),
                    // Pre-filled on the form so the office is not looking the
                    // number up on another screen while somebody waits.
                    'phone' => $lease->tenant?->phone,
                    'property' => $lease->unit?->property?->name,
                    'unit' => $lease->unit?->unit_number,
                ])
                ->sortBy(['property', 'unit'])
                ->values(),

            'vendors' => Vendor::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'trade'])
                ->map(fn (Vendor $v) => [
                    'id' => $v->id,
                    'label' => $v->trade ? "{$v->name} ({$v->trade})" : $v->name,
                ]),
        ]);
    }

    /**
     * Raise it.  [WP-46, FR-MNT-01]
     *
     * Three existing services in sequence, none of them changed: `submit()`
     * numbers and files the ticket, `assignVendor()` moves it to assigned and
     * puts that on the timeline, `billResident()` posts the charge. One
     * transaction, so a ticket is never left half-raised.
     */
    public function store(CreateMaintenanceRequest $request): RedirectResponse
    {
        $lease = $request->lease();

        $ticket = DB::transaction(function () use ($request, $lease) {
            // `internal` travels IN, so submit() writes it before it sends the
            // acknowledgement. Setting it afterwards would be one email too
            // late — the exact leak this flag exists to prevent.
            $ticket = $this->maintenance->submit(
                $lease,
                [...$request->ticketAttributes(), 'internal' => $request->boolean('internal')],
                [],
                $request->user(),
            );

            if ($request->filled('vendor_id')) {
                // Triage first, because the state machine requires it:
                // submitted → triaged → assigned, and `assignVendor()` skips
                // the status move silently when the jump is illegal.
                //
                // Not a workaround. An admin who has read the problem and
                // chosen who should attend HAS triaged it — that is what triage
                // is. Recording it as a real transition keeps the timeline
                // honest rather than teaching the state machine a shortcut that
                // would then be available everywhere.
                $this->maintenance->transition(
                    $ticket,
                    Ticket::STATUS_TRIAGED,
                    $request->user(),
                    'Triaged when raised by the office.',
                );

                $this->maintenance->assignVendor(
                    $ticket,
                    Vendor::findOrFail($request->integer('vendor_id')),
                    $request->user(),
                );
            }

            if ($request->boolean('bill_resident')) {
                $this->billing->billResident(
                    $ticket,
                    $request->billedAmount(),
                    $request->string('billed_reason')->value(),
                    $request->user(),
                );
            }

            return $ticket;
        });

        return redirect()
            ->route('admin.maintenance.show', $ticket->id)
            ->with('status', "Ticket {$ticket->ticket_number} raised.");
    }

    /** API-ADM-21. */
    public function index(Request $request): Response
    {
        $scope = $request->string('scope')->value() ?: 'open';

        $tickets = Ticket::query()
            ->with(['tenant:id,first_name,last_name', 'unit.property:id,name', 'vendor:id,name'])
            ->when($scope === 'open', fn ($q) => $q->open())
            ->when($scope === 'closed', fn ($q) => $q->whereIn('status', [
                Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELLED,
            ]))
            ->when($request->string('status')->value(), fn ($q, $s) => $q->where('status', $s))
            ->queueOrder()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'tenant' => $ticket->tenant?->fullName(),
                'unit' => $ticket->unit?->property?->name
                    ? "{$ticket->unit->property->name} — {$ticket->unit->unit_number}"
                    : null,
                'category' => $ticket->categoryLabel(),
                'status' => $ticket->status,
                'status_label' => TicketStateMachine::label($ticket->status),
                'is_emergency' => $ticket->is_emergency,
                'vendor' => $ticket->vendor?->name,
                'submitted_on' => $ticket->created_at?->toDateString(),
                'age_days' => $ticket->created_at ? (int) $ticket->created_at->diffInDays(now()) : 0,
            ]);

        return Inertia::render('Admin/Maintenance/Index', [
            'tickets' => $tickets,
            'filters' => ['scope' => $scope, 'status' => $request->string('status')->value()],
            'emergencyCount' => Ticket::query()->open()->where('is_emergency', true)->count(),
            'statuses' => TicketStateMachine::LABELS,
        ]);
    }

    /** API-ADM-22. */
    public function show(Ticket $maintenance): Response
    {
        $maintenance->load(['tenant', 'unit.property', 'vendor', 'events.actor:id,name']);

        return Inertia::render('Admin/Maintenance/Show', [
            'ticket' => [
                'id' => $maintenance->id,
                'ticket_number' => $maintenance->ticket_number,
                'tenant' => $maintenance->tenant?->fullName(),
                'tenant_id' => $maintenance->tenant_id,
                'tenant_phone' => $maintenance->contact_phone ?: $maintenance->tenant?->phone,
                'unit' => $maintenance->unit?->property?->name
                    ? "{$maintenance->unit->property->name} — unit {$maintenance->unit->unit_number}"
                    : null,
                'category' => $maintenance->categoryLabel(),
                'description' => $maintenance->description,
                'date_began' => $maintenance->date_began?->toDateString(),
                'permission_to_enter' => $maintenance->permission_to_enter,
                'preferred_contact' => $maintenance->preferred_contact,
                'pets_present' => $maintenance->pets_present,
                'best_access_time' => $maintenance->best_access_time,
                'is_emergency' => $maintenance->is_emergency,
                'status' => $maintenance->status,
                'status_label' => TicketStateMachine::label($maintenance->status),
                'scheduled_at' => $maintenance->scheduled_at?->format('Y-m-d\TH:i'),
                'close_reason' => $maintenance->close_reason,
                'vendor_id' => $maintenance->vendor_id,
                'vendor' => $maintenance->vendor?->name,
                'submitted_on' => $maintenance->created_at?->toDateString(),
            ],
            // Only the moves that are legal from here. An action that will be
            // refused should not be on screen (UI §9).
            'nextStates' => collect($this->states->nextStatesFrom($maintenance->status))
                ->mapWithKeys(fn (string $s) => [$s => TicketStateMachine::label($s)])
                ->all(),
            'vendors' => Vendor::active()->orderBy('name')->get(['id', 'name', 'trade']),
            // Admin sees everything, including the internal notes.
            'events' => $maintenance->events->map(fn ($event) => [
                'id' => $event->id,
                'from' => $event->from_status ? TicketStateMachine::label($event->from_status) : null,
                'to' => TicketStateMachine::label($event->to_status),
                'note' => $event->note,
                'actor' => $event->actor?->name ?? 'System',
                'visible_to_tenant' => $event->visible_to_tenant,
                'at' => $event->created_at?->format('j M Y H:i'),
            ])->all(),
            'attachments' => $maintenance->attachments()->get(['id', 'original_filename', 'kind', 'size_bytes']),
        ]);
    }

    /** API-ADM-23. */
    public function transition(Request $request, Ticket $maintenance): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', Rule::in(array_keys(TicketStateMachine::LABELS))],
            'note' => ['nullable', 'string', 'max:2000'],
            // BR-24 / AC-MNT-07. Required only when closing, and the service
            // refuses without it regardless of what arrives here.
            'close_reason' => ['nullable', 'string', 'max:500', 'required_if:to,closed'],
            'scheduled_at' => ['nullable', 'date', 'required_if:to,scheduled'],

            // [WP-45] What the work cost the landlord. Optional, never shown to
            // a resident, and recording it bills nobody.
            'cost_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],

            // Billing the resident is a separate, deliberate act. Unticked by
            // default on the form: most repairs are the landlord's, and a
            // pre-ticked box is how somebody gets billed for a boiler.
            'bill_resident' => ['sometimes', 'boolean'],
            'billed_amount' => ['nullable', 'required_if:bill_resident,1', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'billed_reason' => ['nullable', 'required_if:bill_resident,1', 'string', 'min:3', 'max:500'],
        ], [
            'close_reason.required_if' => 'Closing a request the resident has not confirmed needs a reason.',
            'scheduled_at.required_if' => 'When is the visit?',
            'billed_amount.required_if' => 'How much is the resident being charged?',
            'billed_amount.gt' => 'Enter an amount greater than zero.',
            'billed_reason.required_if' => 'Say why the resident is being charged. It goes on their ledger.',
        ]);

        try {
            if ($validated['to'] === Ticket::STATUS_SCHEDULED) {
                $this->maintenance->schedule(
                    $maintenance,
                    CarbonImmutable::parse($validated['scheduled_at']),
                    $request->user(),
                );
            } elseif ($validated['to'] === Ticket::STATUS_CLOSED) {
                $this->maintenance->forceClose($maintenance, $validated['close_reason'], $request->user());
            } else {
                $this->maintenance->transition(
                    $maintenance,
                    $validated['to'],
                    $request->user(),
                    $validated['note'] ?? null,
                );
            }
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['to' => $e->getMessage()]);
        }

        // [WP-45] The money, after the status change succeeded. Recording a
        // cost and billing a resident are separate acts and only the second
        // touches a ledger.
        if (array_key_exists('cost_amount', $validated) && $validated['cost_amount'] !== null) {
            $this->billing->recordCost(
                $maintenance,
                Money::fromString((string) $validated['cost_amount']),
                $request->user(),
            );
        }

        if ($request->boolean('bill_resident')) {
            try {
                $this->billing->billResident(
                    $maintenance,
                    Money::fromString((string) $validated['billed_amount']),
                    $validated['billed_reason'],
                    $request->user(),
                );
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['billed_amount' => $e->getMessage()]);
            }

            return back()->with(
                'status',
                'Ticket updated, and the resident has been charged. It is on their ledger now.',
            );
        }

        return back()->with('status', 'Ticket updated.');
    }

    /** API-ADM-24. */
    public function assign(Request $request, Ticket $maintenance): RedirectResponse
    {
        $validated = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->maintenance->assignVendor(
            $maintenance,
            Vendor::findOrFail($validated['vendor_id']),
            $request->user(),
            $validated['note'] ?? null,
        );

        return back()->with('status', 'Contractor assigned. The resident has been told someone is coming.');
    }

    /** API-ADM-25. Vendor photos, and invoices the tenant never sees. */
    public function attach(Request $request, Ticket $maintenance): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([
                MaintenanceAttachment::KIND_VENDOR_MEDIA,
                MaintenanceAttachment::KIND_INVOICE,
            ])],
            'file' => ['required', 'file', 'max:'.(MaintenanceService::MAX_BYTES / 1024)],
        ]);

        try {
            $this->maintenance->attach(
                $maintenance,
                $request->file('file'),
                $validated['kind'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return back()->with('status', $validated['kind'] === MaintenanceAttachment::KIND_INVOICE
            ? 'Invoice filed. It is not visible to the resident.'
            : 'File added.');
    }
}
