<?php

namespace App\Domain\Payments;

use App\Domain\Ledger\BalanceCalculator;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies a settled payment across outstanding charges.  [FR-LED-03, WP-11]
 *
 * **The only class permitted to write `payment_allocations`**, for the same
 * reason LedgerService is the only writer of `ledger_entries` (I-2): an
 * allocation decides how much of a charge is still owed, so a second writer is
 * a second answer to "what does this tenant owe". An architecture test enforces
 * it.
 *
 * The rules held here:
 *
 *   I-6      Only a **settled** payment allocates. Submitting is not paying;
 *            ACH takes 2–5 business days and can still be returned.
 *   I-4/BR-01 A tenant payment pays tenant charges and a Housing Authority
 *            payment pays Housing Authority charges. They are two separate
 *            obligations and money never crosses between them (AC-PAY-16).
 *   D-02     Un-allocating is a timestamp, never a delete.
 *   Q-1      The order is configuration, not code.
 *   Q-8      What happens to an overpayment is configuration too.
 *
 * Nothing here writes a ledger row. An allocation records where money that is
 * already in the ledger went; it never moves a balance, which is why a payment
 * that allocates to nothing still shows up as a credit.
 */
class AllocationService
{
    public function __construct(
        private readonly BalanceCalculator $balances,
        private readonly AllocationOrderRegistry $orders,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Allocate whatever of this payment is not yet applied.  [AC-LED-05, AC-LED-06]
     *
     * Re-runnable by construction: the amount available is the payment less
     * what it has already been allocated to, so a second call over an unchanged
     * ledger allocates nothing. That matters because settlement reconciliation
     * (WP-14) re-reads a ten-day window and will see the same payment on
     * several consecutive runs.
     *
     * @return Collection<int, PaymentAllocation> in the order they were applied
     */
    public function allocate(Payment $payment): Collection
    {
        // A pending payment must never reduce a charge (I-6). This is a
        // programming error rather than a condition to tolerate quietly — a
        // caller that allocates on submission has misunderstood the model, and
        // returning an empty collection would let it keep doing so.
        if (! $payment->isSettled()) {
            throw new InvalidArgumentException(
                "Payment {$payment->id} is {$payment->status}; only a settled payment can be allocated (I-6)."
            );
        }

        $remaining = $this->unallocated($payment);

        if (! $remaining->isPositive()) {
            return collect();
        }

        return DB::transaction(function () use ($payment, $remaining) {
            $applied = collect();

            // Charges are the TENANT'S, not the lease's. A tenant's balance is
            // computed per tenant (BR-03), so allocating per lease would let
            // the allocations disagree with the balance the moment a tenant
            // moved units mid-tenancy.
            $charges = $this->orders->current()->sort(
                $this->balances->outstandingCharges($payment->tenant_id, $payment->payer)
            );

            // A charge this payment has already touched is skipped even if its
            // allocation was reversed. The unique index forbids a second row
            // for the same pair, and re-applying a payment to a charge it was
            // deliberately un-applied from would undo the correction.
            $touched = $payment->allocations()->pluck('charge_entry_id')->all();

            foreach ($charges as $charge) {
                if ($remaining->isZero() || in_array($charge->id, $touched, true)) {
                    continue;
                }

                $take = Money::min($remaining, $charge->outstanding);

                if (! $take->isPositive()) {
                    continue;
                }

                $allocation = new PaymentAllocation;
                $allocation->forceFill([
                    'payment_id' => $payment->id,
                    'charge_entry_id' => $charge->id,
                    'amount' => $take->toDecimalString(),
                ])->save();

                $applied->push($allocation);
                $remaining = $remaining->minus($take);
            }

            // A run that applied nothing to a payment that has been through
            // allocation before is not an event — it is the same standing
            // credit failing to meet a charge it was already refused. Auditing
            // it would put a row on the trail every night for as long as the
            // credit sits there.
            if ($applied->isNotEmpty() || $touched === []) {
                $this->audit->record('payment.allocated', $payment, [
                    'order' => $this->orders->current()->key(),
                    'payer' => $payment->payer,
                    'applied' => $applied
                        ->map(fn (PaymentAllocation $a) => [
                            'charge_entry_id' => $a->charge_entry_id,
                            'amount' => $a->amount->toDecimalString(),
                        ])
                        ->all(),
                    'unapplied' => $remaining->toDecimalString(),
                ]);

                if ($remaining->isPositive()) {
                    $this->recordOverpayment($payment, $remaining);
                }
            }

            return $applied;
        });
    }

    /**
     * Apply credits already sitting on the account to newly posted charges.  [Q-8]
     *
     * This is what `credit_forward` actually means. Without it an overpayment
     * is a number on a balance that never meets the charge it was meant for,
     * and the charge stays outstanding — which would produce a late fee
     * (WP-23) on rent the tenant has already covered.
     *
     * Under `refund` it deliberately does nothing: money owed back to a tenant
     * must not be quietly consumed by next month's rent.
     *
     * @return int allocations written
     */
    public function applyCreditsFor(int $tenantId, string $payer = 'tenant'): int
    {
        if ($this->overpaymentBehaviour() !== 'credit_forward') {
            return 0;
        }

        // No charges, nothing to apply a credit to. Checked before touching any
        // payment so a tenant sitting on a credit does not get re-processed
        // every night for months.
        if ($this->balances->outstandingCharges($tenantId, $payer)->isEmpty()) {
            return 0;
        }

        $written = 0;

        $payments = Payment::query()
            ->settled()
            ->where('tenant_id', $tenantId)
            ->where('payer', $payer)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        foreach ($payments as $payment) {
            if ($this->unallocated($payment)->isPositive()) {
                $written += $this->allocate($payment)->count();
            }
        }

        return $written;
    }

    /**
     * Un-apply everything this payment paid.  [D-02, FR-PAY-04 step 4]
     *
     * The returned-payment path. Stamping `reversed_at` restores every charge
     * it had covered to outstanding, and the rows stay queryable — the history
     * of a bounced payment is exactly the history someone will need to explain.
     *
     * @return int allocations reversed
     */
    public function reverseFor(Payment $payment, string $reason): int
    {
        return DB::transaction(function () use ($payment, $reason) {
            $live = $payment->allocations()->live()->get();

            foreach ($live as $allocation) {
                $this->reverse($allocation, $reason);
            }

            return $live->count();
        });
    }

    /** Un-apply one allocation, leaving the rest of the payment where it is. */
    public function reverse(PaymentAllocation $allocation, string $reason): PaymentAllocation
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reversing an allocation requires a reason.');
        }

        if ($allocation->isReversed()) {
            throw new InvalidArgumentException('That allocation has already been reversed.');
        }

        $allocation->reversed_at = now();
        $allocation->save();

        $this->audit->record('payment.allocation.reversed', $allocation, [
            'payment_id' => $allocation->payment_id,
            'charge_entry_id' => $allocation->charge_entry_id,
            'amount' => $allocation->amount->toDecimalString(),
            'reason' => $reason,
        ]);

        return $allocation;
    }

    /**
     * How much of this payment has not landed on a charge.
     *
     * The unapplied remainder is the credit on the account — it is already in
     * the balance (the ledger entry is negative), it just has not been matched
     * to anything yet.
     */
    public function unallocated(Payment $payment): Money
    {
        $applied = Money::fromString(
            (string) ($payment->allocations()->live()->sum('amount') ?: '0')
        );

        return $payment->amount->minus($applied);
    }

    /** What the payment has been applied to, newest first. */
    public function allocationsFor(Payment $payment): Collection
    {
        return $payment->allocations()->with('chargeEntry')->orderBy('id')->get();
    }

    private function recordOverpayment(Payment $payment, Money $remainder): void
    {
        $refunding = $this->overpaymentBehaviour() === 'refund';

        // Two genuinely different outcomes, not one setting with a comment.
        // credit_forward leaves the money on the account for the next charge;
        // refund means someone has to send it back, and that needs to be
        // visible rather than inferred from a negative balance.
        $this->audit->record(
            $refunding ? 'payment.refund_due' : 'payment.credit_forward',
            $payment,
            [
                'amount' => $remainder->toDecimalString(),
                'behaviour' => $this->overpaymentBehaviour(),
            ],
        );
    }

    private function overpaymentBehaviour(): string
    {
        return $this->settings->string('payments.overpayment_behaviour', 'credit_forward');
    }
}
