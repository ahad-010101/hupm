<?php

namespace App\Domain\Payments;

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Models\HousingAuthority;
use App\Models\Lease;
use App\Models\Payment;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Payments an admin records by hand.  [FR-PAY-05, API-ADM-15, WP-12]
 *
 * Cheques, money orders, cash and Housing Authority remittances — money that
 * arrived outside the gateway. Unlike an ACH debit these have already happened
 * by the time anyone types them in, so the ledger entry is written `cleared`
 * and the balance moves immediately.
 *
 * **Available at all times, including during Management Review** (BR-12, I-12,
 * AC-PAY-15). There is deliberately no delinquency check anywhere in this
 * class: a tenant in Management Review is exactly the tenant most likely to be
 * paying by cheque at the office, and refusing to record it would leave the
 * ledger wrong and the arrears overstated. If a guard ever appears here, it is
 * a bug.
 */
class PaymentRecordingService
{
    /** Methods that mean "this money arrived without the gateway". */
    public const MANUAL_METHODS = ['cheque', 'money_order', 'cash', 'bank_transfer', 'ha_remittance'];

    private const METHOD_LABELS = [
        'cheque' => 'Cheque',
        'money_order' => 'Money order',
        'cash' => 'Cash',
        'bank_transfer' => 'Bank transfer',
        'ha_remittance' => 'Housing authority remittance',
    ];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AllocationService $allocations,
        private readonly BalanceCalculator $balances,
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record one offline payment against one lease.
     *
     * `idempotencyKey` is the caller's protection against a double submit
     * (risk R-13): a second call with the same key returns the payment that
     * already exists rather than taking the money twice. The admin form carries
     * one generated when the page rendered, so the browser's retry button is
     * safe.
     */
    public function record(
        Lease $lease,
        string $payer,
        Money $amount,
        CarbonImmutable $receivedOn,
        string $method,
        string $idempotencyKey,
        ?string $reference = null,
        ?string $note = null,
        ?string $batchId = null,
    ): Payment {
        $this->guard($amount, $receivedOn, $method);

        $existing = Payment::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use (
                $lease, $payer, $amount, $receivedOn, $method, $idempotencyKey, $reference, $note, $batchId
            ) {
                $payment = new Payment;
                $payment->forceFill([
                    'lease_id' => $lease->id,
                    'tenant_id' => $lease->tenant_id,
                    'payer' => $payer,
                    'amount' => $amount->toDecimalString(),
                    'method' => $method,
                    'gateway' => 'manual',
                    'idempotency_key' => $idempotencyKey,
                    // Settled on arrival. There is no clearing period for money
                    // already in hand, and I-6 is about the gateway, not about
                    // a cheque an admin is holding.
                    'status' => Payment::STATUS_SETTLED,
                    'reference' => $reference,
                    'batch_id' => $batchId,
                    'submitted_at' => $receivedOn,
                    'settled_at' => $receivedOn,
                    'recorded_by_user_id' => auth()->id(),
                ])->save();

                $this->ledger->postPayment(
                    $lease,
                    $payer,
                    $amount,
                    $this->describe($method, $reference),
                    $payment->id,
                    'cleared',
                    $receivedOn,
                    $note,
                );

                // [D-20] Both payers allocate. API-ADM-15 says "allocation run
                // if payer=tenant", but a Housing Authority payment that never
                // meets its charges leaves every HA charge reading fully
                // outstanding — which makes "which months has the authority
                // paid?" unanswerable and leaves the lump-sum screen with
                // nothing to suggest. Payer isolation is enforced inside
                // AllocationService, so this cannot cross the two obligations.
                $this->allocations->allocate($payment);

                $this->audit->record('payment.recorded', $payment, [
                    'lease_id' => $lease->id,
                    'payer' => $payer,
                    'amount' => $amount->toDecimalString(),
                    'method' => $method,
                    'received_on' => $receivedOn->toDateString(),
                    'reference' => $reference,
                    'batch_id' => $batchId,
                ]);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            // Two submissions raced. The key did its job; hand back the winner.
            return Payment::where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }

    /**
     * Split one Housing Authority cheque across several tenants.  [GATE Q-2, R-9]
     *
     * A lump-sum remittance is one payment in the post and many obligations in
     * the ledger, and **the system cannot guess which tenants it covers** — the
     * authority's own remittance advice is the only authority on that. So this
     * takes an explicit per-lease split and refuses anything that does not add
     * up to the cheque.
     *
     * Each line becomes its own `payments` row so the ledger stays per tenant,
     * and they share a `batch_id` so the cheque can be reassembled later.
     *
     * @param  list<array{lease_id:int, amount:string}>  $lines
     * @return Collection<int, Payment>
     */
    public function recordRemittance(
        HousingAuthority $authority,
        Money $total,
        CarbonImmutable $receivedOn,
        array $lines,
        string $batchKey,
        ?string $reference = null,
        ?string $note = null,
    ): Collection {
        $lines = array_values(array_filter(
            $lines,
            fn (array $line) => Money::fromString($line['amount'])->isPositive(),
        ));

        if ($lines === []) {
            throw new InvalidArgumentException('A remittance needs at least one tenant to apply it to.');
        }

        $split = Money::sum(array_map(fn (array $line) => Money::fromString($line['amount']), $lines));

        if (! $split->equals($total)) {
            throw new InvalidArgumentException(sprintf(
                'The split comes to %s but the remittance is %s. A cheque that does not add up is a cheque nobody can reconcile.',
                $split->format(),
                $total->format(),
            ));
        }

        $batchId = $this->batchIdFor($authority, $receivedOn, $batchKey);

        return DB::transaction(function () use ($authority, $lines, $receivedOn, $batchId, $batchKey, $reference, $note, $total) {
            $payments = collect();

            foreach ($lines as $line) {
                $lease = Lease::findOrFail($line['lease_id']);

                // A lease belonging to a different authority is not a
                // validation nicety — it would post one agency's money against
                // another's obligation.
                if ((int) $lease->housing_authority_id !== $authority->id) {
                    throw new InvalidArgumentException(
                        "Lease {$lease->id} is not under {$authority->name}."
                    );
                }

                $payments->push($this->record(
                    lease: $lease,
                    payer: 'housing_authority',
                    amount: Money::fromString($line['amount']),
                    receivedOn: $receivedOn,
                    method: 'ha_remittance',
                    idempotencyKey: substr(sha1("{$batchKey}:{$lease->id}"), 0, 36),
                    reference: $reference,
                    note: $note,
                    batchId: $batchId,
                ));
            }

            $this->audit->record('payment.remittance.recorded', $authority, [
                'batch_id' => $batchId,
                'total' => $total->toDecimalString(),
                'tenants' => $payments->count(),
                'received_on' => $receivedOn->toDateString(),
                'reference' => $reference,
            ]);

            return $payments;
        });
    }

    /**
     * What each lease under this authority still owes on the HA portion.
     *
     * The remittance screen's starting point. Offered as a suggestion, never
     * applied automatically — see recordRemittance.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function outstandingFor(HousingAuthority $authority): Collection
    {
        return Lease::query()
            ->where('housing_authority_id', $authority->id)
            ->where('status', Lease::STATUS_ACTIVE)
            ->with(['tenant:id,first_name,last_name', 'unit.property:id,name'])
            ->get()
            ->map(fn (Lease $lease) => [
                'lease_id' => $lease->id,
                'tenant' => $lease->tenant?->fullName(),
                'unit' => $lease->unit?->property?->name.' — unit '.$lease->unit?->unit_number,
                'monthly' => (string) $lease->ha_portion,
                'outstanding' => (string) $this->balances->haBalance($lease->tenant_id),
            ])
            ->sortBy('tenant')
            ->values();
    }

    private function guard(Money $amount, CarbonImmutable $receivedOn, string $method): void
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A recorded payment must be a positive amount.');
        }

        if (! in_array($method, self::MANUAL_METHODS, true)) {
            throw new InvalidArgumentException("'{$method}' is not a method an admin records by hand.");
        }

        // Resolved in the company timezone (D-07). In UTC an admin recording a
        // cheque at 8pm in Georgia would be told tomorrow's date is in the
        // future — which it is, five hours from now, somewhere else.
        if ($receivedOn->toDateString() > $this->calendar->today()->toDateString()) {
            throw new InvalidArgumentException('A payment cannot be received in the future.');
        }
    }

    private function describe(string $method, ?string $reference): string
    {
        $label = self::METHOD_LABELS[$method] ?? 'Payment';

        return trim($reference ?? '') === '' ? $label : "{$label} {$reference}";
    }

    private function batchIdFor(HousingAuthority $authority, CarbonImmutable $receivedOn, string $batchKey): string
    {
        // `HA-` prefixed so a manual remittance batch can never be mistaken for
        // an Authorize.Net settlement batch in the same column (WP-14).
        return sprintf(
            'HA-%d-%s-%s',
            $authority->id,
            $receivedOn->format('Ymd'),
            substr(sha1($batchKey), 0, 8),
        );
    }
}
