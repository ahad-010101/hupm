<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Maintenance\MaintenanceService;
use App\Domain\Maintenance\TicketStateMachine;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceAttachment;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    ) {}

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
        ], [
            'close_reason.required_if' => 'Closing a request the resident has not confirmed needs a reason.',
            'scheduled_at.required_if' => 'When is the visit?',
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
