<?php

namespace App\Domain\Maintenance;

use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplate;
use App\Models\Lease;
use App\Models\MaintenanceAttachment;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\User;
use App\Models\Vendor;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Repair requests, from reported to closed.  [FR-MNT-01…03]
 *
 * The ticket number, the status and the event trail move together or not at
 * all. Every state change here is one transaction that writes the new status
 * **and** the immutable event that explains it (AC-MNT-05) — a status that
 * changed with no row saying who changed it is the gap this class exists to
 * close.
 */
class MaintenanceService
{
    /** FR-MNT-01 validation, enforced here as well as in the FormRequest. */
    public const MAX_FILES = 5;

    public const MAX_BYTES = 20 * 1024 * 1024;

    public const ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/heic', 'image/heif', 'video/mp4',
    ];

    /**
     * The extension each accepted type is STORED under.  [WP-34, TDD §6.2]
     *
     * The stored name is derived from the sniffed type, never from the name the
     * browser sent. `getClientOriginalExtension()` is a substring of a string
     * the client chose: it survived the MIME check because the check reads the
     * file's own bytes, so the two can disagree — and when they do, the bytes
     * are right. Trusting the client half meant `evil.php` whose content
     * sniffed as an image was rejected, but a file that passed while *named*
     * `.php` would have been written to disk as `<uuid>.php`.
     *
     * That file lands on the `local` disk, rooted at `storage/app/private`,
     * which is outside the document root — so it was never reachable and never
     * executable. This closes the second hole rather than the first: it means
     * the guarantee rests on what the file IS, not on where it happens to sit.
     */
    private const STORED_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'video/mp4' => 'mp4',
    ];

    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly TicketStateMachine $states,
        private readonly NotificationService $notifications,
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A tenant reports something.  [FR-MNT-01, AC-MNT-03/04]
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, UploadedFile>  $files
     */
    public function submit(Lease $lease, array $attributes, array $files = [], ?User $actor = null): Ticket
    {
        $ticket = DB::transaction(function () use ($lease, $attributes, $files, $actor) {
            $ticket = new Ticket;
            $ticket->forceFill([
                'ticket_number' => $this->numbers->next(),
                'lease_id' => $lease->id,
                'tenant_id' => $lease->tenant_id,
                'unit_id' => $lease->unit_id,
                'category' => $attributes['category'],
                'description' => $attributes['description'],
                'date_began' => $attributes['date_began'] ?? null,
                'permission_to_enter' => (bool) $attributes['permission_to_enter'],
                'preferred_contact' => $attributes['preferred_contact'],
                'contact_phone' => $attributes['contact_phone'] ?? null,
                'pets_present' => (bool) ($attributes['pets_present'] ?? false),
                'best_access_time' => $attributes['best_access_time'] ?? null,
                'is_emergency' => (bool) ($attributes['is_emergency'] ?? false),
                // AC-MNT-04: an emergency is urgent from the moment it is
                // reported, not once somebody triages it.
                'urgency' => ($attributes['is_emergency'] ?? false) ? 'emergency' : 'normal',
                'status' => Ticket::STATUS_SUBMITTED,
            ])->save();

            foreach ($files as $file) {
                $this->attach($ticket, $file, MaintenanceAttachment::KIND_TENANT_MEDIA, $actor);
            }

            $this->recordEvent(
                $ticket,
                from: null,
                to: Ticket::STATUS_SUBMITTED,
                note: 'Request submitted by the resident.',
                actor: $actor,
            );

            return $ticket;
        });

        $this->notifyTenant($ticket, 'We have received your request.');
        $this->alertAdmins($ticket);

        $this->audit->record('maintenance.submitted', $ticket, [
            'ticket_number' => $ticket->ticket_number,
            'category' => $ticket->category,
            'emergency' => $ticket->is_emergency,
        ]);

        return $ticket;
    }

    /**
     * Move a ticket.  [FR-MNT-02, AC-MNT-05/08]
     *
     * Closing is refused here unless it comes from the tenant confirming, or
     * from forceClose with a reason (BR-24). `$closeReason` is how forceClose
     * identifies itself.
     */
    public function transition(
        Ticket $ticket,
        string $to,
        ?User $actor = null,
        ?string $note = null,
        ?string $closeReason = null,
    ): Ticket {
        $this->states->assertCanMove($ticket->status, $to);

        if ($to === Ticket::STATUS_CLOSED && $closeReason === null && $actor !== null) {
            // An admin closing a ticket is force-closing it, whatever they call
            // it. BR-24 wants the reason recorded, and the only reliable way to
            // get one is to refuse without it.
            throw new InvalidArgumentException(
                'Closing a ticket on the resident\'s behalf needs a reason. It is recorded on the ticket.'
            );
        }

        return DB::transaction(function () use ($ticket, $to, $actor, $note, $closeReason) {
            $from = $ticket->status;

            $ticket->forceFill(array_filter([
                'status' => $to,
                'closed_at' => $to === Ticket::STATUS_CLOSED ? now() : null,
                'close_reason' => $closeReason,
            ], fn ($value) => $value !== null))->save();

            $this->recordEvent($ticket, $from, $to, $note ?? $closeReason, $actor);

            if ($this->states->isVisibleToTenant($to)) {
                $this->notifyTenant($ticket, $this->states->tenantMessageFor($to));
            }

            $this->audit->record('maintenance.status.changed', $ticket, [
                'ticket_number' => $ticket->ticket_number,
                'from' => $from,
                'to' => $to,
                'reason' => $closeReason,
            ]);

            return $ticket;
        });
    }

    /**
     * Assign a contractor.  [FR-MNT-03, AC-MNT-09]
     *
     * The tenant is told a contractor is coming and who they are, because
     * someone will knock on their door. They are never told what it costs.
     */
    public function assignVendor(Ticket $ticket, Vendor $vendor, ?User $actor = null, ?string $note = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $vendor, $actor, $note) {
            $from = $ticket->status;

            $ticket->forceFill(['vendor_id' => $vendor->id])->save();

            if ($this->states->canMove($from, Ticket::STATUS_ASSIGNED)) {
                $ticket->forceFill(['status' => Ticket::STATUS_ASSIGNED])->save();
            }

            // Two events, not one concatenated string. The tenant is emailed
            // about the assignment, so it belongs on their timeline — but an
            // admin's own note must not ride along into it. Merging them was
            // one careless sentence away from showing a resident what we say
            // about their case internally.
            $this->recordEvent(
                $ticket,
                $from,
                $ticket->status,
                "Assigned to {$vendor->name}".($vendor->trade ? " ({$vendor->trade})" : ''),
                $actor,
                visibleToTenant: true,
            );

            if ($note !== null && trim($note) !== '') {
                $this->recordEvent(
                    $ticket,
                    $ticket->status,
                    $ticket->status,
                    trim($note),
                    $actor,
                    visibleToTenant: false,
                );
            }

            $this->notifyTenant(
                $ticket,
                "{$vendor->name} has been assigned to your request and will be in touch."
            );

            $this->audit->record('maintenance.vendor.assigned', $ticket, [
                'ticket_number' => $ticket->ticket_number,
                'vendor_id' => $vendor->id,
            ]);

            return $ticket;
        });
    }

    /** Book a visit. Scheduling is a fact the tenant plans their day around. */
    public function schedule(Ticket $ticket, \DateTimeInterface $at, ?User $actor = null): Ticket
    {
        $ticket->forceFill(['scheduled_at' => $at])->save();

        return $this->transition(
            $ticket,
            Ticket::STATUS_SCHEDULED,
            $actor,
            'Visit scheduled for '.$this->calendar->toBusinessDate($at)->format('j F Y \a\t g:ia').'.',
        );
    }

    /**
     * The tenant says it is fixed.  [AC-MNT-06]
     *
     * No `$closeReason`, and no actor with an admin role — this is the ordinary
     * way a ticket closes, and the only one that needs no explanation.
     */
    public function confirmComplete(Ticket $ticket, ?User $actor = null): Ticket
    {
        if ($ticket->status !== Ticket::STATUS_AWAITING_CONFIRMATION) {
            throw new InvalidArgumentException('That request is not waiting for your confirmation.');
        }

        $ticket = DB::transaction(function () use ($ticket, $actor) {
            $ticket->forceFill([
                'status' => Ticket::STATUS_CLOSED,
                'closed_at' => now(),
            ])->save();

            $this->recordEvent(
                $ticket,
                Ticket::STATUS_AWAITING_CONFIRMATION,
                Ticket::STATUS_CLOSED,
                'The resident confirmed the work is complete.',
                $actor,
            );

            return $ticket;
        });

        // AC-MNT-06: both parties. The tenant gets closure, the office gets to
        // stop chasing.
        $this->notifyTenant($ticket, 'Thank you for confirming. Your request is now closed.');
        $this->alertAdmins($ticket, 'confirmed complete by the resident');

        $this->audit->record('maintenance.confirmed', $ticket, [
            'ticket_number' => $ticket->ticket_number,
        ]);

        return $ticket;
    }

    /** BR-24 / AC-MNT-07. A reason, always. */
    public function forceClose(Ticket $ticket, string $reason, User $actor): Ticket
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Closing a ticket without the resident confirming needs a reason.');
        }

        return $this->transition($ticket, Ticket::STATUS_CLOSED, $actor, null, trim($reason));
    }

    /**
     * Store one file.
     *
     * Outside the web root, under a UUID name. The tenant's original filename
     * is kept for display only — it is user input and never touches the path.
     */
    public function attach(
        Ticket $ticket,
        UploadedFile $file,
        string $kind = MaintenanceAttachment::KIND_TENANT_MEDIA,
        ?User $actor = null,
    ): MaintenanceAttachment {
        $this->assertAcceptable($file);

        // Extension from the sniffed type, not the supplied name. [WP-34]
        // assertAcceptable() has already refused anything not in the map, so
        // the fallback cannot fire; it is there because a silent 'bin' is a
        // better failure than an undefined index if the two lists ever drift.
        $extension = self::STORED_EXTENSION[$file->getMimeType()] ?? 'bin';

        $path = $file->storeAs(
            "maintenance/{$ticket->id}",
            Str::uuid().'.'.$extension,
            'local',
        );

        $attachment = new MaintenanceAttachment;
        $attachment->forceFill([
            'maintenance_request_id' => $ticket->id,
            'uploaded_by_user_id' => $actor?->id,
            'kind' => $kind,
            'original_filename' => substr($file->getClientOriginalName(), 0, 255),
            'stored_path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            // AC-MNT-09: an invoice is between the landlord and the contractor.
            'visible_to_tenant' => $kind !== MaintenanceAttachment::KIND_INVOICE,
        ])->save();

        return $attachment;
    }

    private function assertAcceptable(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_BYTES) {
            // Integer arithmetic, not number_format(). It is megabytes rather
            // than money, but the architecture guard cannot tell the two apart
            // and a rule with exceptions is a rule people learn to argue with.
            $tenths = intdiv($file->getSize() * 10, 1048576);

            throw new InvalidArgumentException(sprintf(
                '%s is %d.%d MB. Each file has to be under %d MB.',
                $file->getClientOriginalName(),
                intdiv($tenths, 10),
                $tenths % 10,
                intdiv(self::MAX_BYTES, 1048576),
            ));
        }

        if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s is not a photo or video we can accept. Use a JPG, PNG, HEIC or MP4.',
                $file->getClientOriginalName(),
            ));
        }
    }

    private function recordEvent(
        Ticket $ticket,
        ?string $from,
        string $to,
        ?string $note,
        ?User $actor,
        ?bool $visibleToTenant = null,
    ): MaintenanceEvent {
        $event = new MaintenanceEvent;
        $event->forceFill([
            'maintenance_request_id' => $ticket->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            // Default: whatever the state machine says about this transition.
            // Overridden only where we have already emailed the tenant about
            // something the machine calls internal.
            'visible_to_tenant' => $visibleToTenant
                ?? ($this->states->isVisibleToTenant($to) || $from === null),
            'actor_user_id' => $actor?->id,
            'created_at' => now(),
        ])->save();

        return $event;
    }

    private function notifyTenant(Ticket $ticket, ?string $message): void
    {
        $tenant = $ticket->tenant;

        if (! $tenant || $message === null) {
            return;
        }

        $this->notifications->send(
            NotificationTemplate::MaintenanceStatusChange,
            $tenant->email,
            [
                'name' => $tenant->fullName(),
                'ticketNumber' => $ticket->ticket_number,
                'category' => $ticket->categoryLabel(),
                'status' => TicketStateMachine::label($ticket->status),
                'note' => $message,
                'scheduledFor' => $ticket->scheduled_at
                    ? $this->calendar->toBusinessDate($ticket->scheduled_at)->format('j F Y 	 g:ia')
                    : null,
                'url' => url("/portal/maintenance/{$ticket->id}"),
            ],
            tenantId: $tenant->id,
        );
    }

    /**
     * Tell the office.  [AC-MNT-03]
     *
     * Every admin, because there is no on-call rota in the data and an
     * emergency at 2am must not depend on one person reading their inbox.
     */
    private function alertAdmins(Ticket $ticket, string $what = 'submitted'): void
    {
        $admins = User::query()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->get(['id', 'email', 'name']);

        foreach ($admins as $admin) {
            $this->notifications->send(
                NotificationTemplate::MaintenanceStatusChange,
                $admin->email,
                [
                    'name' => $admin->name,
                    'ticketNumber' => $ticket->ticket_number,
                    'category' => $ticket->categoryLabel(),
                    'status' => TicketStateMachine::label($ticket->status),
                    'note' => sprintf(
                        '%s request %s by %s at %s.',
                        $ticket->is_emergency ? 'EMERGENCY' : 'Maintenance',
                        $what,
                        $ticket->tenant?->fullName() ?? 'a resident',
                        $ticket->unit?->unit_number ?? 'their unit',
                    ),
                    'url' => url("/admin/maintenance/{$ticket->id}"),
                ],
                userId: $admin->id,
            );
        }
    }
}
