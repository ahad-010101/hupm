<?php

namespace App\Domain\Ledger;

use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only class permitted to write `ledger_entries`.  [INVARIANT I-2]
 *
 * Enforced by an architecture test, not by convention. Every other way of
 * creating a financial row — a controller, a job, a seeder, a console command —
 * is a defect, because each one would be a second place where the sign
 * convention, the status rules and the audit trail could drift apart.
 *
 * The rules this class exists to hold in one place:
 *
 *   BR-03 / I-1   No balance is ever stored. BalanceCalculator sums at read
 *                 time; nothing here writes a total anywhere.
 *   BR-04 / I-3   Entries are immutable except `status`. A correction is a
 *                 reversing entry, never an edit and never a delete.
 *   BR-05         Only `posted` and `cleared` affect a balance. A `pending`
 *                 payment has not happened yet — ACH takes 2–5 business days
 *                 and can still be returned (I-6).
 *   I-10          Money is App\Support\Money throughout. No float reaches here.
 *
 * Signs are the caller's protection rather than the caller's problem:
 * `postCharge` takes a positive amount and stores it positive, `postPayment`
 * takes the amount paid and stores it negative. A method that accepted a signed
 * amount for both would make a wrong sign a plausible typo instead of an
 * impossible one.
 */
class LedgerService
{
    /** Only these two count towards a balance (BR-05). */
    public const BALANCE_AFFECTING = ['posted', 'cleared'];

    /**
     * Legal status transitions.
     *
     * A payment enters `pending`, clears, and may later be returned. Charges
     * post directly. `returned` and `void` are terminal: something that has
     * already been undone cannot be undone again, and the way to correct it is
     * a reversing entry.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'pending' => ['cleared', 'returned', 'void'],
        'posted' => ['cleared', 'returned', 'void'],
        'cleared' => ['returned', 'void'],
        'returned' => [],
        'void' => [],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessCalendar $calendar,
    ) {}

    /**
     * Post a charge. Amount must be positive.
     *
     * `chargeKey` is the idempotency key (D-01) — re-running the charge job for
     * a period that already has its rent must not double-charge. A duplicate is
     * not an error the caller has to handle: the existing row is returned, so a
     * catch-up run after a missed day is safe by construction (AC-CHG-04).
     */
    public function postCharge(
        Lease $lease,
        string $category,
        string $payer,
        Money $amount,
        string $description,
        string $chargeKey,
        ?CarbonImmutable $postedOn = null,
        ?string $period = null,
    ): LedgerEntry {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A charge must be a positive amount; use postAdjustment for a credit.');
        }

        try {
            return $this->write($lease, [
                'type' => 'charge',
                'category' => $category,
                'payer' => $payer,
                'amount' => $amount,
                'status' => 'posted',
                'posted_on' => ($postedOn ?? $this->calendar->today())->toDateString(),
                'period' => $period,
                'description' => $description,
                'charge_key' => $chargeKey,
            ], 'ledger.charge.posted');
        } catch (UniqueConstraintViolationException) {
            // Already charged for this key. Idempotent by design (D-01).
            return LedgerEntry::where('charge_key', $chargeKey)->firstOrFail();
        }
    }

    /**
     * Post a payment. Takes the amount PAID (positive) and stores it negative.
     *
     * Defaults to `pending`: a payment reduces a balance only on settlement
     * (I-6). Submitting is not paying.
     */
    public function postPayment(
        Lease $lease,
        string $payer,
        Money $amount,
        string $description,
        ?int $paymentId = null,
        string $status = 'pending',
        ?CarbonImmutable $postedOn = null,
        ?string $reason = null,
    ): LedgerEntry {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('Pass the amount paid as a positive value; the ledger stores it negative.');
        }

        return $this->write($lease, [
            'type' => 'payment',
            'category' => 'other',
            'payer' => $payer,
            'amount' => $amount->negate(),
            'status' => $status,
            'posted_on' => ($postedOn ?? $this->calendar->today())->toDateString(),
            'description' => $description,
            // An admin's note about an offline payment — "left at the office",
            // "part of authority batch 44". Context belongs on the row someone
            // will be reading a year later, not only in the audit log.
            'reason' => $reason,
            'payment_id' => $paymentId,
        ], 'ledger.payment.posted');
    }

    /**
     * Post a manual adjustment, positive or negative.  [FR-LED-04, AC-LED-07]
     *
     * The reason is mandatory and enforced here rather than only in a
     * FormRequest, because an adjustment can also originate from an import or a
     * console command, and an unexplained movement of money is the thing this
     * rule exists to prevent.
     */
    public function postAdjustment(
        Lease $lease,
        string $payer,
        Money $amount,
        string $reason,
        string $description,
        ?string $category = null,
        ?CarbonImmutable $postedOn = null,
        ?string $chargeKey = null,
    ): LedgerEntry {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An adjustment requires a reason.');
        }

        if ($amount->isZero()) {
            throw new InvalidArgumentException('An adjustment of zero changes nothing; do not post one.');
        }

        return $this->write($lease, [
            'type' => 'adjustment',
            'category' => $category ?? 'other',
            'payer' => $payer,
            'amount' => $amount,
            'status' => 'posted',
            'posted_on' => ($postedOn ?? $this->calendar->today())->toDateString(),
            'description' => $description,
            'reason' => $reason,
            'charge_key' => $chargeKey,
        ], 'ledger.adjustment.posted');
    }

    /**
     * Reverse an entry.  [FR-LED-04, AC-LED-08, BR-04]
     *
     * Posts an opposing entry pointing at the original. **Both remain visible**
     * — the original is not hidden, flagged or struck through in the data. What
     * was posted is a matter of record even when it was wrong, especially when
     * it was wrong, and a ledger that quietly removes its mistakes cannot be
     * used to answer a tenant's question about their own account.
     */
    public function reverse(LedgerEntry $entry, string $reason, ?CarbonImmutable $postedOn = null): LedgerEntry
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reversal requires a reason.');
        }

        if ($entry->type === 'reversal') {
            throw new InvalidArgumentException('A reversal cannot itself be reversed; post a fresh entry instead.');
        }

        if (LedgerEntry::where('reverses_entry_id', $entry->id)->exists()) {
            throw new InvalidArgumentException('That entry has already been reversed.');
        }

        $lease = Lease::findOrFail($entry->lease_id);

        return $this->write($lease, [
            'type' => 'reversal',
            'category' => $entry->category,
            'payer' => $entry->payer,
            'amount' => $entry->amount->negate(),
            'status' => 'posted',
            'posted_on' => ($postedOn ?? $this->calendar->today())->toDateString(),
            'period' => $entry->period,
            'description' => 'Reversal of: '.$entry->description,
            'reason' => $reason,
            'reverses_entry_id' => $entry->id,
        ], 'ledger.entry.reversed');
    }

    /**
     * Move an entry's status.  [I-3]
     *
     * The only mutation the ledger permits. Everything else about a row is
     * frozen once written, which the Immutable model guard enforces.
     */
    public function transitionStatus(LedgerEntry $entry, string $to): LedgerEntry
    {
        $from = $entry->status;

        if ($from === $to) {
            return $entry;
        }

        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new InvalidArgumentException("A ledger entry cannot move from {$from} to {$to}.");
        }

        return DB::transaction(function () use ($entry, $from, $to) {
            $entry->status = $to;
            $entry->save();

            $this->audit->record('ledger.status.changed', $entry, [
                'from' => $from,
                'to' => $to,
                'amount' => $entry->amount->toDecimalString(),
            ]);

            return $entry;
        });
    }

    /**
     * The single insert path. Everything above funnels through here so the
     * denormalised tenant_id, the audit row and the Money conversion happen
     * once rather than five times.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function write(Lease $lease, array $attributes, string $auditAction): LedgerEntry
    {
        return DB::transaction(function () use ($lease, $attributes, $auditAction) {
            /** @var Money $amount */
            $amount = $attributes['amount'];

            $entry = new LedgerEntry;
            $entry->forceFill([
                ...$attributes,
                'lease_id' => $lease->id,
                // Denormalised from the lease so the balance query does not
                // join (DB §A2).
                'tenant_id' => $lease->tenant_id,
                'amount' => $amount->toDecimalString(),
                'created_by_user_id' => auth()->id(),
            ])->save();

            $this->audit->record($auditAction, $entry, [
                'type' => $attributes['type'],
                'category' => $attributes['category'],
                'payer' => $attributes['payer'],
                'amount' => $amount->toDecimalString(),
                'status' => $attributes['status'],
                'reason' => $attributes['reason'] ?? null,
            ]);

            return $entry->refresh();
        });
    }
}
