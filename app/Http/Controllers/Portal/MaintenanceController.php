<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Maintenance\MaintenanceService;
use App\Domain\Maintenance\TicketStateMachine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\SubmitMaintenanceRequest;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\Tenant;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A resident's own repair requests.  [UI §3.5, API-POR-10…14]
 *
 * **Ownership is a 404, never a 403** (I-9): telling someone that ticket 1044
 * exists but is not theirs is telling them something.
 *
 * **Vendor cost never crosses this boundary** (AC-MNT-09). The tenant is told
 * a contractor is assigned and who they are, because that person is coming to
 * their home. What the landlord pays them is not on this path at all.
 */
class MaintenanceController extends Controller
{
    public function __construct(
        private readonly MaintenanceService $maintenance,
        private readonly Settings $settings,
    ) {}

    /** API-POR-10. */
    public function index(Request $request): Response
    {
        $tenant = $this->tenantFor($request);

        $tickets = $tenant
            ? Ticket::query()
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Ticket $ticket) => $this->summary($ticket))
                ->all()
            : [];

        return Inertia::render('Portal/Maintenance/Index', ['tickets' => $tickets]);
    }

    /** API-POR-11. */
    public function create(Request $request): Response
    {
        $tenant = $this->tenantFor($request);
        $lease = $tenant?->activeLease();

        return Inertia::render('Portal/Maintenance/New', [
            'categories' => Ticket::CATEGORIES,
            'hasLease' => $lease !== null,
            'unit' => $lease?->unit?->property?->name
                ? "{$lease->unit->property->name} — unit {$lease->unit->unit_number}"
                : null,
            'tenantName' => $tenant?->fullName(),
            'tenantPhone' => $tenant?->phone,
            // BR-23. The number has to be on the warning itself, not a click
            // away, because the person reading it may be in a hurry.
            'emergencyPhone' => $this->settings->string('company.emergency_phone')
                ?: $this->settings->string('company.phone'),
            'maxFiles' => MaintenanceService::MAX_FILES,
            'maxMegabytes' => (int) (MaintenanceService::MAX_BYTES / 1048576),
        ]);
    }

    /** API-POR-12. */
    public function store(SubmitMaintenanceRequest $request): RedirectResponse
    {
        $tenant = $this->tenantFor($request);
        $lease = $tenant?->activeLease();

        if (! $lease) {
            throw ValidationException::withMessages([
                'category' => 'We could not find an active tenancy for you. Please contact the office.',
            ]);
        }

        try {
            $ticket = $this->maintenance->submit(
                $lease,
                $request->validated(),
                $request->file('photos') ?? [],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            // A file the service refuses. Surfaced as a field error so Inertia
            // keeps everything else the tenant typed (AC-MNT-02).
            throw ValidationException::withMessages(['photos' => $e->getMessage()]);
        }

        return redirect()
            ->route('portal.maintenance.show', $ticket->id)
            ->with('status', "Request {$ticket->ticket_number} received. We will be in touch.");
    }

    /** API-POR-13. 404 if it is not theirs. */
    public function show(Request $request, Ticket $maintenance): Response
    {
        $tenant = $this->tenantFor($request);

        abort_unless($tenant && $maintenance->tenant_id === $tenant->id, 404);

        $maintenance->load(['vendor', 'unit.property']);

        return Inertia::render('Portal/Maintenance/Show', [
            'ticket' => [
                ...$this->summary($maintenance),
                'description' => $maintenance->description,
                'date_began' => $maintenance->date_began?->format('j F Y'),
                'permission_to_enter' => $maintenance->permission_to_enter,
                'pets_present' => $maintenance->pets_present,
                'best_access_time' => $maintenance->best_access_time,
                'scheduled_for' => $maintenance->scheduled_at?->format('j F Y \a\t g:ia'),
                // Who is coming, and nothing about what they charge (AC-MNT-09).
                'contractor' => $maintenance->vendor?->name,
                'contractor_trade' => $maintenance->vendor?->trade,
                'close_reason' => $maintenance->close_reason,
                'can_confirm' => $maintenance->status === Ticket::STATUS_AWAITING_CONFIRMATION,
            ],
            'events' => $maintenance->events()
                ->visibleToTenant()
                ->get()
                ->map(fn ($event) => [
                    'id' => $event->id,
                    'status' => TicketStateMachine::label($event->to_status),
                    'note' => $event->note,
                    'at' => $event->created_at?->format('j F Y \a\t g:ia'),
                ])
                ->all(),
            'photos' => $maintenance->attachments()
                ->visibleToTenant()
                ->get(['id', 'original_filename', 'kind'])
                ->all(),
        ]);
    }

    /** API-POR-14. AC-MNT-06. */
    public function confirm(Request $request, Ticket $maintenance): RedirectResponse
    {
        $tenant = $this->tenantFor($request);

        abort_unless($tenant && $maintenance->tenant_id === $tenant->id, 404);

        try {
            $this->maintenance->confirmComplete($maintenance, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Thank you — your request is now closed.');
    }

    /** @return array<string, mixed> */
    private function summary(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'category' => $ticket->categoryLabel(),
            'status' => $ticket->status,
            'status_label' => TicketStateMachine::label($ticket->status),
            'is_emergency' => $ticket->is_emergency,
            'is_open' => $ticket->isOpen(),
            'submitted_on' => $ticket->created_at?->format('j F Y'),
        ];
    }

    private function tenantFor(Request $request): ?Tenant
    {
        $tenantId = $request->user()?->tenant_id;

        return $tenantId ? Tenant::find($tenantId) : null;
    }
}
