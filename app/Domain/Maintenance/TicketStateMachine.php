<?php

namespace App\Domain\Maintenance;

use App\Models\MaintenanceRequest as Ticket;
use InvalidArgumentException;

/**
 * Which way a ticket may move.  [FR-MNT-02, AC-MNT-08]
 *
 * Written as data rather than as a chain of ifs, so the whole lifecycle is
 * readable in one place and a test can walk every edge.
 *
 * The rule that matters is **`submitted → closed` is rejected** (AC-MNT-08).
 * Not out of pedantry: BR-24 says a ticket closes when the tenant confirms the
 * work or an admin force-closes with a recorded reason. A path straight from
 * "reported" to "done" would let a repair be marked complete with no evidence
 * anyone looked at it, which is exactly the dispute this trail exists to
 * settle.
 */
class TicketStateMachine
{
    /**
     * Legal moves.
     *
     * `cancelled` is reachable from anything still open — a tenant fixes it
     * themselves, or reports the same fault twice — but nothing leaves
     * `closed` or `cancelled`. A finished ticket stays finished; the way to
     * reopen a fault is a new ticket, so the history of each attempt survives.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        Ticket::STATUS_SUBMITTED => [Ticket::STATUS_TRIAGED, Ticket::STATUS_CANCELLED],
        Ticket::STATUS_TRIAGED => [
            Ticket::STATUS_ASSIGNED,
            // Small jobs the manager does themselves never touch a vendor.
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_CANCELLED,
        ],
        Ticket::STATUS_ASSIGNED => [
            Ticket::STATUS_SCHEDULED,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_CANCELLED,
        ],
        Ticket::STATUS_SCHEDULED => [
            Ticket::STATUS_IN_PROGRESS,
            // The contractor did not turn up; back to finding one.
            Ticket::STATUS_ASSIGNED,
            Ticket::STATUS_CANCELLED,
        ],
        Ticket::STATUS_IN_PROGRESS => [
            Ticket::STATUS_AWAITING_CONFIRMATION,
            Ticket::STATUS_CANCELLED,
        ],
        Ticket::STATUS_AWAITING_CONFIRMATION => [
            Ticket::STATUS_CLOSED,
            // The tenant says it is not fixed. Straight back to work rather
            // than closing and reopening, so one fault stays one ticket.
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_CANCELLED,
        ],
        Ticket::STATUS_CLOSED => [],
        Ticket::STATUS_CANCELLED => [],
    ];

    /**
     * Transitions the tenant sees, and what they are told.
     *
     * Anything not named here is internal. A tenant does not need an email
     * because their ticket moved from `triaged` to `assigned` in a queue they
     * cannot see — but they very much need one when someone is coming.
     *
     * @var array<string, string>
     */
    private const TENANT_VISIBLE = [
        Ticket::STATUS_TRIAGED => 'We have looked at your request and it is in the queue.',
        Ticket::STATUS_SCHEDULED => 'A visit has been scheduled.',
        Ticket::STATUS_IN_PROGRESS => 'Work has started on your request.',
        Ticket::STATUS_AWAITING_CONFIRMATION => 'The work is reported as done. Please let us know if it is fixed.',
        Ticket::STATUS_CLOSED => 'Your request has been closed.',
        Ticket::STATUS_CANCELLED => 'Your request has been cancelled.',
    ];

    /** Human wording for every state, used on both sides of the app. */
    public const LABELS = [
        Ticket::STATUS_SUBMITTED => 'Submitted',
        Ticket::STATUS_TRIAGED => 'Reviewed',
        Ticket::STATUS_ASSIGNED => 'Contractor assigned',
        Ticket::STATUS_SCHEDULED => 'Visit scheduled',
        Ticket::STATUS_IN_PROGRESS => 'In progress',
        Ticket::STATUS_AWAITING_CONFIRMATION => 'Awaiting your confirmation',
        Ticket::STATUS_CLOSED => 'Closed',
        Ticket::STATUS_CANCELLED => 'Cancelled',
    ];

    public function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> */
    public function nextStatesFrom(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public function assertCanMove(string $from, string $to): void
    {
        if ($from === $to) {
            throw new InvalidArgumentException('That ticket is already '.self::label($to).'.');
        }

        if (! $this->canMove($from, $to)) {
            throw new InvalidArgumentException(sprintf(
                'A ticket cannot go from %s to %s.',
                self::label($from),
                self::label($to),
            ));
        }
    }

    public function isVisibleToTenant(string $to): bool
    {
        return array_key_exists($to, self::TENANT_VISIBLE);
    }

    public function tenantMessageFor(string $to): ?string
    {
        return self::TENANT_VISIBLE[$to] ?? null;
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
