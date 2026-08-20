<?php

namespace App\Domain\Reporting;

use App\Domain\Delinquency\DelinquencyService;
use App\Domain\Payments\ReconciliationService;
use App\Models\ExceptionAcknowledgement;
use App\Models\Lease;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\Payment;
use App\Models\PaymentProfile;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What needs a person today.  [FR-ADM-01, UI §3.7, AC-ADM-02]
 *
 * Six kinds, one list, most urgent first — and **above everything else on the
 * dashboard**, which is the one layout instruction UI §3.7 gives twice. The
 * ordering is not decoration: an untriaged emergency and a lease expiring in
 * eighty days are not the same kind of fact, and a screen that sorts them by
 * recency buries the first under the second.
 *
 * Every item carries an `href` to **the screen where it gets resolved**, not to
 * a filtered list of things like it. An exception that requires the reader to
 * work out where to go is a to-do item, not an alert.
 *
 * Two kinds are acknowledgeable and four are not, deliberately:
 *
 * - A **returned payment** and a **failed autopay** are terminal. Nothing about
 *   the row will ever change again, so without acknowledgement they would sit
 *   on the panel until the end of time (D-25).
 * - A **NOC flag**, an **unmatched payment**, an **untriaged emergency** and a
 *   **new Management Review account** all clear themselves when the underlying
 *   thing is dealt with. Offering to acknowledge one would let somebody dismiss
 *   the work instead of doing it.
 */
class ExceptionFeed
{
    public const KIND_EMERGENCY_TICKET = 'emergency_ticket';

    public const KIND_RETURNED_PAYMENT = 'returned_payment';

    public const KIND_FAILED_AUTOPAY = 'failed_autopay';

    public const KIND_NOC_FLAG = 'noc_flag';

    public const KIND_UNMATCHED_PAYMENT = 'unmatched_payment';

    public const KIND_MANAGEMENT_REVIEW = 'management_review';

    /**
     * The only two kinds a person may wave away.
     *
     * @var list<string>
     */
    public const ACKNOWLEDGEABLE = [self::KIND_RETURNED_PAYMENT, self::KIND_FAILED_AUTOPAY];

    /**
     * How long a Management Review account counts as "newly" in review.
     *
     * UI §3.7 says "accounts **newly** in Management Review", and the word is
     * doing work: every account in review already has its own screen and its
     * own queue. What belongs on an exceptions panel is the transition — the
     * thing that happened at 02:30 while nobody was watching.
     */
    private const NEWLY_IN_REVIEW_DAYS = 7;

    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Everything that needs attention, most urgent kind first.
     *
     * @return list<array{kind:string,subject_id:int,title:string,detail:string,href:string,since:?string,acknowledgeable:bool}>
     */
    public function items(): array
    {
        return [
            ...$this->emergencyTickets(),
            ...$this->returnedPayments(),
            ...$this->failedAutopay(),
            ...$this->noticesOfChange(),
            ...$this->unmatchedPayments(),
            ...$this->newlyInManagementReview(),
        ];
    }

    /**
     * Counts per kind plus a total, for the badge and the panel heading.
     *
     * Built from items() rather than from six COUNT queries, so the number in
     * the badge and the list underneath it can never disagree — the failure
     * mode where a badge says 3 and the panel shows 2 is worse than no badge.
     *
     * @return array{total:int, by_kind:array<string,int>}
     */
    public function summary(): array
    {
        $byKind = [];

        foreach ($this->items() as $item) {
            $byKind[$item['kind']] = ($byKind[$item['kind']] ?? 0) + 1;
        }

        return ['total' => array_sum($byKind), 'by_kind' => $byKind];
    }

    /**
     * Take one item off the panel.  [AC-ADM-02]
     *
     * Idempotent: acknowledging twice is the same fact stated twice, so the
     * second call updates nothing and raises nothing. The unique index is what
     * makes that true rather than a check-then-insert that two tabs could race.
     */
    public function acknowledge(string $kind, int $subjectId, User $actor, ?string $note = null): void
    {
        if (! in_array($kind, self::ACKNOWLEDGEABLE, true)) {
            // Not a validation quibble: the other four clear when the work is
            // done, and letting one be dismissed would hide it while it is
            // still outstanding.
            throw new InvalidArgumentException(
                "A {$kind} is cleared by resolving it, not by acknowledging it."
            );
        }

        $already = ExceptionAcknowledgement::query()
            ->where('kind', $kind)
            ->where('subject_id', $subjectId)
            ->exists();

        if ($already) {
            return;
        }

        $acknowledgement = new ExceptionAcknowledgement;
        $acknowledgement->forceFill([
            'kind' => $kind,
            'subject_id' => $subjectId,
            'acknowledged_by_user_id' => $actor->id,
            'acknowledged_at' => now(),
            'note' => $note,
        ])->save();

        $this->audit->record('exception.acknowledged', $acknowledgement, [
            'kind' => $kind,
            'subject_id' => $subjectId,
            'note' => $note,
        ]);
    }

    /**
     * Emergency tickets nobody has looked at yet.
     *
     * `submitted` only, not every open emergency. Once a ticket is triaged it
     * has an owner and a place in the queue on the same screen; what belongs in
     * the exceptions panel is the one that arrived and has not been seen. The
     * manager triaging from the car (UI §6) needs the difference.
     *
     * @return list<array<string,mixed>>
     */
    private function emergencyTickets(): array
    {
        return Ticket::query()
            ->where('is_emergency', true)
            ->where('status', Ticket::STATUS_SUBMITTED)
            ->with(['tenant:id,first_name,last_name', 'unit:id,unit_number,property_id', 'unit.property:id,name'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Ticket $ticket) => [
                'kind' => self::KIND_EMERGENCY_TICKET,
                'subject_id' => $ticket->id,
                'title' => 'Emergency maintenance awaiting triage',
                'detail' => trim(sprintf(
                    '%s — %s, %s',
                    $ticket->ticket_number,
                    $ticket->tenant?->fullName() ?? 'unknown resident',
                    $this->unitLabel($ticket->unit),
                )),
                'href' => "/admin/maintenance/{$ticket->id}",
                'since' => $ticket->created_at?->toIso8601String(),
                'acknowledgeable' => false,
            ])
            ->all();
    }

    /**
     * ACH returns.  [AC-ADM-02]
     *
     * Links to the resident's ledger rather than to the payments list: the
     * balance has gone back up and somebody has to talk to them about it, which
     * is a thing you do from an account, not from a table of returns.
     *
     * @return list<array<string,mixed>>
     */
    private function returnedPayments(): array
    {
        return Payment::query()
            ->where('status', Payment::STATUS_RETURNED)
            ->whereNotIn('id', $this->acknowledgedIds(self::KIND_RETURNED_PAYMENT))
            ->with('tenant:id,first_name,last_name')
            ->orderByDesc('returned_at')
            ->get()
            ->map(fn (Payment $payment) => [
                'kind' => self::KIND_RETURNED_PAYMENT,
                'subject_id' => $payment->id,
                'title' => 'Payment returned by the bank',
                'detail' => trim(sprintf(
                    '%s — %s%s',
                    $payment->tenant?->fullName() ?? 'unknown resident',
                    $payment->amount->format(),
                    $payment->return_code ? " ({$payment->return_code} {$payment->return_description})" : '',
                )),
                'href' => $payment->tenant_id ? "/admin/ledger/{$payment->tenant_id}" : '/admin/payments?status=returned',
                'since' => $payment->returned_at?->toIso8601String(),
                'acknowledgeable' => true,
            ])
            ->all();
    }

    /**
     * Autopay debits the gateway refused.
     *
     * A stored profile was charged and the charge did not take, which is a
     * different fact from a resident who never paid: nobody has decided not to
     * pay, and the account will quietly go past due unless somebody rings.
     *
     * *The debit job itself is WP-16.* Nothing writes these rows yet; the query
     * is here so the panel is right the day it does, rather than a thing to
     * remember later.
     *
     * @return list<array<string,mixed>>
     */
    private function failedAutopay(): array
    {
        return Payment::query()
            ->where('status', Payment::STATUS_FAILED)
            ->whereNotNull('payment_profile_id')
            ->whereNotIn('id', $this->acknowledgedIds(self::KIND_FAILED_AUTOPAY))
            ->with('tenant:id,first_name,last_name')
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn (Payment $payment) => [
                'kind' => self::KIND_FAILED_AUTOPAY,
                'subject_id' => $payment->id,
                'title' => 'Automatic payment did not go through',
                'detail' => trim(sprintf(
                    '%s — %s%s',
                    $payment->tenant?->fullName() ?? 'unknown resident',
                    $payment->amount->format(),
                    $payment->return_description ? " ({$payment->return_description})" : '',
                )),
                'href' => $payment->tenant_id ? "/admin/ledger/{$payment->tenant_id}" : '/admin/payments?status=failed',
                'since' => $payment->submitted_at?->toIso8601String(),
                'acknowledgeable' => true,
            ])
            ->all();
    }

    /**
     * Notice of Change: the bank told us the account details moved.  [AC-PAY-14]
     *
     * No further debit is attempted against a stale profile, so this one is
     * urgent in a quiet way — autopay has stopped for that resident and neither
     * of you will notice until the rent does not arrive.
     *
     * @return list<array<string,mixed>>
     */
    private function noticesOfChange(): array
    {
        return PaymentProfile::query()
            ->where('status', PaymentProfile::STATUS_NEEDS_UPDATE)
            ->with('tenant:id,first_name,last_name')
            ->orderBy('updated_at')
            ->get()
            ->map(fn (PaymentProfile $profile) => [
                'kind' => self::KIND_NOC_FLAG,
                'subject_id' => $profile->id,
                'title' => 'Bank details need updating before autopay can resume',
                'detail' => trim(sprintf(
                    '%s — %s',
                    $profile->tenant?->fullName() ?? 'unknown resident',
                    $profile->descriptor ?: 'stored payment method',
                )),
                'href' => $profile->tenant_id ? "/admin/tenants/{$profile->tenant_id}" : '/admin/tenants',
                'since' => $profile->updated_at?->toIso8601String(),
                'acknowledgeable' => false,
            ])
            ->all();
    }

    /**
     * Payments the gateway knows about that never resolved.
     *
     * Same definition as the payments screen and the reconciliation job, taken
     * from the same constant so the three cannot drift: pending, known to the
     * gateway, and older than the reconciliation window. Never auto-voided —
     * the money may be real and merely mis-referenced (FR-PAY-04 step 6).
     *
     * @return list<array<string,mixed>>
     */
    private function unmatchedPayments(): array
    {
        return Payment::query()
            ->where('status', Payment::STATUS_PENDING)
            ->whereNotNull('gateway_transaction_id')
            ->where('submitted_at', '<', now()->subDays(ReconciliationService::WINDOW_DAYS))
            ->with('tenant:id,first_name,last_name')
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (Payment $payment) => [
                'kind' => self::KIND_UNMATCHED_PAYMENT,
                'subject_id' => $payment->id,
                'title' => 'Payment still unresolved after the reconciliation window',
                'detail' => trim(sprintf(
                    '%s — %s, submitted %s',
                    $payment->tenant?->fullName() ?? 'unknown resident',
                    $payment->amount->format(),
                    $payment->submitted_at?->format('j M Y') ?? 'date unknown',
                )),
                'href' => '/admin/payments?unmatched=1',
                'since' => $payment->submitted_at?->toIso8601String(),
                'acknowledgeable' => false,
            ])
            ->all();
    }

    /**
     * Accounts the nightly job put into Management Review this week.
     *
     * The transition is the exception, not the state — see NEWLY_IN_REVIEW_DAYS.
     *
     * @return list<array<string,mixed>>
     */
    private function newlyInManagementReview(): array
    {
        $cutoff = $this->calendar->today()->subDays(self::NEWLY_IN_REVIEW_DAYS);

        return Lease::query()
            ->where('delinquency_state', DelinquencyService::STATE_REVIEW)
            ->whereNotNull('delinquency_since')
            ->where('delinquency_since', '>=', $cutoff->toDateString())
            ->with(['tenant:id,first_name,last_name', 'unit:id,unit_number,property_id', 'unit.property:id,name'])
            ->orderByDesc('delinquency_since')
            ->get()
            ->map(fn (Lease $lease) => [
                'kind' => self::KIND_MANAGEMENT_REVIEW,
                'subject_id' => $lease->id,
                'title' => 'Account entered Management Review',
                'detail' => trim(sprintf(
                    '%s — %s',
                    $lease->tenant?->fullName() ?? 'unknown resident',
                    $this->unitLabel($lease->unit),
                )),
                'href' => '/admin/delinquency',
                'since' => $lease->delinquency_since?->toIso8601String(),
                'acknowledgeable' => false,
            ])
            ->all();
    }

    /** @return list<int> */
    private function acknowledgedIds(string $kind): array
    {
        return DB::table('exception_acknowledgements')
            ->where('kind', $kind)
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function unitLabel(mixed $unit): string
    {
        if (! $unit) {
            return 'unit unknown';
        }

        return trim(sprintf('%s unit %s', $unit->property?->name ?? '', $unit->unit_number));
    }
}
