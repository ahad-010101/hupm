<?php

namespace App\Domain\Payments;

use App\Domain\Ledger\LedgerService;
use App\Exceptions\GatewayUnavailableException;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Support\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starting a tenant-initiated eCheck payment.  [FR-PAY-01, API-POR-05]
 *
 * The order of operations is the whole design:
 *
 *   1. Refuse outright if the account is in Management Review (BR-11).
 *   2. Check the amount against the lease's own rules.
 *   3. Write a `pending` payment and a `pending` ledger entry — **neither of
 *      which moves the balance** (BR-05, I-6). Submitting is not paying; ACH
 *      takes 2–5 business days and can still be returned.
 *   4. *Then* ask the gateway for a form. If that fails, the two rows written
 *      in step 3 are marked `failed` and `void`, and the balance never knew.
 *
 * Steps 3 and 4 are deliberately not one transaction. Rolling back would leave
 * no evidence that a tenant tried to pay and the provider was down — and F1
 * requires exactly that evidence.
 */
class PaymentIntentService
{
    /** An Accept Hosted token is good for 15 minutes; we hold ours for less. */
    private const TOKEN_TTL_SECONDS = 840;

    public function __construct(
        private readonly AuthorizeNetGateway $gateway,
        private readonly LedgerService $ledger,
        private readonly PartialPaymentPolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create (or recover) a payment intent and the form token that goes with it.
     *
     * @return array{payment: Payment, token: string, url: string}
     */
    public function create(
        Lease $lease,
        Money $amount,
        string $idempotencyKey,
        string $returnUrl,
        string $cancelUrl,
    ): array {
        $this->guardDelinquency($lease);

        // A double-click is the same intent twice, not two intents (AC-PAY-02).
        // The token is cached with the payment so the second click reaches the
        // *same* form — a second token would be a second chance to submit.
        $existing = Payment::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return [
                'payment' => $existing,
                'token' => Cache::get($this->tokenKey($existing)) ?? '',
                'url' => $this->gateway->hostedPageUrl(),
            ];
        }

        $verdict = $this->policy->check($lease, $amount);

        if (! $verdict['allowed']) {
            throw ValidationException::withMessages(['amount' => $verdict['reason']]);
        }

        [$payment, $entry] = $this->recordIntent($lease, $amount, $idempotencyKey);

        try {
            $token = $this->gateway->hostedPaymentToken(
                $payment,
                $lease->tenant,
                $returnUrl,
                $cancelUrl,
                $this->gateway->customerProfileId($lease->tenant),
            );
        } catch (GatewayUnavailableException $e) {
            // F1 / AC-PAY-04: nothing charged, nothing owed differently, and a
            // record that it was attempted.
            $this->abandon($payment, $entry, 'gateway unavailable');

            throw $e;
        }

        Cache::put($this->tokenKey($payment), $token, self::TOKEN_TTL_SECONDS);

        return [
            'payment' => $payment,
            'token' => $token,
            'url' => $this->gateway->hostedPageUrl(),
        ];
    }

    /**
     * The tenant came back from the gateway.  [FR-PAY-01 step 7, API-POR-06]
     *
     * Storing the transaction id here only makes reconciliation faster. It is
     * not what makes the payment real — the settlement file is (R-6) — so a
     * return with no id is an ordinary outcome, not an error.
     */
    public function recordReturn(Payment $payment, ?string $transactionId): Payment
    {
        if ($transactionId === null || $transactionId === '' || $payment->gateway_transaction_id) {
            return $payment;
        }

        $payment->forceFill(['gateway_transaction_id' => $transactionId])->save();

        $this->audit->record('payment.gateway.returned', $payment, [
            'gateway_transaction_id' => $transactionId,
        ]);

        // The form has been used. Leaving it live would let a browser-back
        // land on a page that can still be submitted (UI §7).
        Cache::forget($this->tokenKey($payment));

        return $payment;
    }

    /**
     * Void a payment that never reached the gateway.
     *
     * Used by the return path when a tenant cancels, and by
     * CleanupAbandonedPayments for the ones who simply closed the tab.
     */
    public function abandon(Payment $payment, ?LedgerEntry $entry, string $reason): void
    {
        $entry ??= LedgerEntry::where('payment_id', $payment->id)->first();

        DB::transaction(function () use ($payment, $entry, $reason) {
            $payment->forceFill([
                'status' => $reason === 'gateway unavailable'
                    ? Payment::STATUS_FAILED
                    : Payment::STATUS_VOID,
                'return_description' => $reason,
            ])->save();

            if ($entry && $entry->status === 'pending') {
                // `void`, not deleted. I-3: the ledger records what happened,
                // including the things that did not finish happening.
                $this->ledger->transitionStatus($entry, 'void');
            }

            $this->audit->record('payment.abandoned', $payment, [
                'reason' => $reason,
                'amount' => $payment->amount->toDecimalString(),
            ]);
        });

        Cache::forget($this->tokenKey($payment));
    }

    /**
     * @return array{0: Payment, 1: LedgerEntry}
     */
    private function recordIntent(Lease $lease, Money $amount, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($lease, $amount, $idempotencyKey) {
            $payment = new Payment;
            $payment->forceFill([
                'lease_id' => $lease->id,
                'tenant_id' => $lease->tenant_id,
                'payer' => 'tenant',
                'amount' => $amount->toDecimalString(),
                'method' => 'echeck',
                'gateway' => 'authorize_net',
                'idempotency_key' => $idempotencyKey,
                'status' => Payment::STATUS_PENDING,
                'submitted_at' => now(),
            ])->save();

            // `pending`: visible to the tenant as "processing", counted in no
            // balance anywhere (BR-05, I-6).
            $entry = $this->ledger->postPayment(
                $lease,
                'tenant',
                $amount,
                'Payment submitted online',
                $payment->id,
                'pending',
            );

            $this->audit->record('payment.submitted', $payment, [
                'amount' => $amount->toDecimalString(),
                'gateway' => 'authorize_net',
                'sec_code' => AuthorizeNetGateway::SEC_CODE_WEB,
            ]);

            return [$payment, $entry];
        });
    }

    /**
     * BR-11 / AC-PAY-03 / AC-DEL-04.
     *
     * Checked in the service rather than only on the screen, because AC-DEL-04
     * is specifically about a tenant constructing the request by hand once the
     * button has disappeared.
     */
    private function guardDelinquency(Lease $lease): void
    {
        if ($lease->delinquency_state === 'management_review') {
            abort(403, 'Please contact management to arrange payment.');
        }
    }

    private function tokenKey(Payment $payment): string
    {
        return "payment:{$payment->id}:hosted-token";
    }
}
