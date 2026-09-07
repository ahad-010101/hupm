<?php

namespace App\Http\Controllers\Vendor;

use App\Domain\Maintenance\MaintenanceService;
use App\Domain\Maintenance\TicketStateMachine;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceRequest as Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A contractor's own jobs.  [WP-44, FR-MNT-02, reverses NG-6]
 *
 * WP-37 concluded that a contractor is a record and not an account, and a test
 * asserted no vendor route mentioned login. The client asked for the portal on
 * 5 Sep 2026 and that decision is reversed here rather than quietly worked
 * around — see WP-44 in the plan.
 *
 * **Everything is scoped from the session.** `vendor_id` comes off the signed-in
 * user and never from the request; there is no route parameter naming a vendor,
 * so there is none to get wrong (I-9, BR-20).
 *
 * A contractor is a third party holding a resident's telephone number, their
 * access arrangements and the fact that they are home after four. That is the
 * sharpest disclosure in the system and it is justified only by the job — so it
 * is confined to tickets assigned to them, and there is nothing financial on
 * this path at all.
 */
class TicketController extends Controller
{
    public function __construct(
        private readonly MaintenanceService $maintenance,
        private readonly TicketStateMachine $states,
    ) {}

    public function index(Request $request): Response
    {
        $tickets = $this->assignedTo($request)
            ->with(['unit.property:id,name,street_address,city,state,postal_code'])
            ->queueOrder()
            ->get()
            ->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'address' => $ticket->unit?->property?->fullAddress(),
                'unit' => $ticket->unit?->unit_number,
                'category' => $ticket->categoryLabel(),
                'status' => $ticket->status,
                'status_label' => TicketStateMachine::label($ticket->status),
                'is_emergency' => $ticket->is_emergency,
                'scheduled_at' => $ticket->scheduled_at?->format('j M Y, H:i'),
                'assigned_on' => $ticket->updated_at?->toDateString(),
            ]);

        return Inertia::render('Vendor/Tickets', [
            'vendor' => $request->user()->vendor?->only(['id', 'name', 'trade']),
            // Open work first; a closed job is history, not a list of things to do.
            'open' => $tickets->reject(fn ($t) => in_array($t['status'], ['closed', 'cancelled'], true))->values(),
            'done' => $tickets->filter(fn ($t) => in_array($t['status'], ['closed', 'cancelled'], true))->values(),
        ]);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $this->assertMine($request, $ticket);

        $ticket->load(['unit.property', 'tenant:id,first_name,last_name,phone', 'events']);

        return Inertia::render('Vendor/TicketShow', [
            'ticket' => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'address' => $ticket->unit?->property?->fullAddress(),
                'unit' => $ticket->unit?->unit_number,
                'category' => $ticket->categoryLabel(),
                'description' => $ticket->description,
                'is_emergency' => $ticket->is_emergency,
                'status' => $ticket->status,
                'status_label' => TicketStateMachine::label($ticket->status),
                'scheduled_at' => $ticket->scheduled_at?->format('j M Y, H:i'),

                // What the job actually needs. A contractor who cannot get in
                // is a second visit and a resident taking another afternoon off.
                'resident' => $ticket->tenant?->fullName(),
                'contact_phone' => $ticket->contact_phone ?: $ticket->tenant?->phone,
                'permission_to_enter' => $ticket->permission_to_enter,
                'best_access_time' => $ticket->best_access_time,
                'pets_present' => $ticket->pets_present,
            ],

            // The same rules an admin gets, from the same state machine — a
            // contractor cannot reach a state the office could not.
            'nextStates' => collect($this->states->nextStatesFrom($ticket->status))
                ->mapWithKeys(fn (string $s) => [$s => TicketStateMachine::label($s)])
                ->all(),

            // Only what a contractor should read back. Internal triage notes
            // are the office's, and `visible_to_tenant` is not the right filter
            // either — this is a third audience.
            'history' => $ticket->events
                ->map(fn ($event) => [
                    'id' => $event->id,
                    'to' => TicketStateMachine::label($event->to_status),
                    'at' => $event->created_at?->format('j M Y H:i'),
                ])
                ->values()
                ->all(),
        ]);
    }

    /** Move a job along, and say what happened. */
    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->assertMine($request, $ticket);

        $validated = $request->validate([
            'to' => ['required', Rule::in(array_keys(TicketStateMachine::LABELS))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->maintenance->transition(
                $ticket,
                $validated['to'],
                $request->user(),
                $validated['note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['to' => $e->getMessage()]);
        }

        return back()->with('status', 'Thank you — the office has been updated.');
    }

    /** @return Builder<Ticket> */
    private function assignedTo(Request $request)
    {
        return Ticket::query()->where('vendor_id', $request->user()->vendor_id);
    }

    /**
     * Somebody else's job is a 404, never a 403.
     *
     * A 403 confirms the ticket exists, which tells a contractor something
     * about a property they have no business knowing (I-9).
     */
    private function assertMine(Request $request, Ticket $ticket): void
    {
        abort_unless($ticket->vendor_id === $request->user()->vendor_id, 404);
    }
}
