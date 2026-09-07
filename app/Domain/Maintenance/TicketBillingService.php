<?php

namespace App\Domain\Maintenance;

use App\Domain\Ledger\LedgerService;
use App\Models\LedgerEntry;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turning a repair into money.  [WP-45, FR-MNT-03, FR-LED-01]
 *
 * Two amounts that are never the same number and never meet:
 *
 *   `cost_amount`   what the contractor charged the landlord. Spend reporting.
 *                   **Never reaches a resident**, and nothing in this class
 *                   puts it on a ledger.
 *   `billed_amount` what the resident is charged, when the damage was theirs.
 *                   Usually nothing.
 *
 * Recording the cost is not billing anybody, which is why they are separate
 * calls. Most repairs are the landlord's; the office should be able to write
 * down what a boiler cost without that being a step towards charging somebody
 * for it.
 */
class TicketBillingService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What the work cost the landlord. Money out, not money owed.
     *
     * Deliberately touches no ledger. The tenant ledger records what a resident
     * owes; what the landlord spent on a boiler is a different book, and I-1
     * would be meaningless if arbitrary costs could land in this one.
     */
    public function recordCost(Ticket $ticket, ?Money $cost, ?User $actor = null): Ticket
    {
        $ticket->forceFill(['cost_amount' => $cost?->toDecimalString()])->save();

        $this->audit->record('maintenance.cost.recorded', $ticket, [
            'ticket_number' => $ticket->ticket_number,
            'cost' => $cost?->toDecimalString(),
            'by' => $actor?->id,
            'billed_to_resident' => false,
        ]);

        return $ticket;
    }

    /**
     * Charge the resident for this repair.  [AC-MNT-11]
     *
     * Idempotent on the ticket. The charge key is `{lease}:ticket{id}`, so
     * closing a ticket twice, a retried request, or two admins pressing the
     * button at once all produce one charge — `postCharge` returns the existing
     * row on a key collision (D-01) rather than writing a second.
     *
     * **Tenant payer, always.** A repair is never billed to the housing
     * authority: the HAP contract funds rent, and a resident's damage is not
     * the agency's to pay for.
     */
    public function billResident(
        Ticket $ticket,
        Money $amount,
        string $reason,
        ?User $actor = null,
        ?string $category = null,
    ): LedgerEntry {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A charge must be more than zero.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Billing a resident for a repair requires a reason.');
        }

        if ($ticket->billed_ledger_entry_id !== null) {
            throw new InvalidArgumentException(
                'This ticket has already been billed. Reverse the existing charge on the ledger instead.'
            );
        }

        $lease = $ticket->lease;

        if (! $lease) {
            throw new InvalidArgumentException('This ticket has no lease to charge against.');
        }

        return DB::transaction(function () use ($ticket, $lease, $amount, $reason, $actor, $category) {
            $entry = $this->ledger->postCharge(
                $lease,
                // WP-41's types where one is chosen; `other` is the honest
                // default — a repair recharge is not rent, a fee or a utility.
                $category ?? 'other',
                'tenant',
                $amount,
                // The ticket number is in the wording on purpose. A resident
                // asking "what is this $180" a year from now is answered by the
                // line itself, not by somebody going to look.
                "Repair {$ticket->ticket_number} — {$reason}",
                "{$lease->id}:ticket{$ticket->id}",
                $this->calendar->today(),
            );

            $ticket->forceFill([
                'billed_amount' => $amount->toDecimalString(),
                'billed_reason' => $reason,
                'billed_ledger_entry_id' => $entry->id,
            ])->save();

            $this->audit->record('maintenance.resident.billed', $ticket, [
                'ticket_number' => $ticket->ticket_number,
                'amount' => $amount->toDecimalString(),
                'reason' => $reason,
                'ledger_entry_id' => $entry->id,
                'by' => $actor?->id,
            ]);

            return $entry;
        });
    }
}
