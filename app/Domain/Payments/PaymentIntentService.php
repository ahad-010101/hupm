<?php

namespace App\Domain\Payments;

use App\Domain\Ledger\LedgerService;
use App\Exceptions\GatewayUnavailableException;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\Settings;
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
        private readonly Settings $settings,
    ) {}

    /**
     * The fee for paying this way.  [WP-39, WP-47, Q-7a]
     *
     * **Two shapes, because the two rails are billed differently.** A card
     * costs a percentage of the amount, so the fee is a percentage. ACH costs a
     * flat sum whatever the amount, so the fee is flat — a percentage on a
     * $1,000 bank transfer would be a markup on a transaction that cost 25¢,
     * not a recovery, and would be hard to defend to a resident who asked.
     *
     * Public because the portal has to show the figure *before* they choose,
     * and it must be the same figure the intent charges — two readings of one
     * setting is how a disclosed fee and a charged fee drift apart.
     *
     * **Never a float** (I-10). The percentage goes through `prorate`, integer
     * half-up: 2.9% of $345.98 is 34598 × 290 ÷ 10000 = $10.03, exactly, every
     * time, where a float gives 10.033420000000001.
     */
    public function convenienceFee(
        string $method,
        Money $amount,
        string $payer = 'tenant',
    ): Money {
        // [WP-47] A fee is a RESIDENT-facing charge.
        //
        // A housing authority remits by bank transfer, so keying the ACH fee on
        // method alone would start charging an agency a fee on public money —
        // the thing WP-43 refused a card for, arriving by the back door the
        // moment ACH gained a fee of its own.
        if ($payer !== 'tenant' || ! $amount->isPositive()) {
            return Money::zero();
        }

        if ($method === Payment::METHOD_CARD) {
            $basisPoints = $this->feeBasisPoints();

            return $basisPoints === 0
                ? Money::zero()
                : $amount->prorate($basisPoints, 10_000);
        }

        return $this->echeckFee();
    }

    /**
     * The flat fee on a bank transfer, if one is set.  [WP-47]
     *
     * Public for the same reason as the percentage: the page shows it before
     * the resident commits, and must show what will actually be charged.
     */
    public function echeckFee(): Money
    {
        return $this->settings->money('payments.echeck_fee_flat', Money::zero());
    }

    /**
     * The configured percentage, in basis points.
     *
     * Capped at 400 (4%) here as well as in the settings form. Card-brand
     * rules cap a surcharge at 4%, and a setting edited straight into the
     * database should not be able to exceed what the form refuses.
     */
    public function feeBasisPoints(): int
    {
        $percent = $this->settings->string('payments.card_convenience_fee_percent', '0');

        // Through Money rather than a float: the setting is a decimal string
        // like "2.90", and Money is the one place decimal strings are parsed.
        // "2.90" parses to 290 minor units, which IS the basis-point figure —
        // two decimal places of a percent is exactly one hundredth of a
        // percent. The coincidence is convenient and worth naming so nobody
        // "fixes" it later.
        $points = Money::fromString($percent === '' ? '0' : $percent)->minor;

        return max(0, min(400, $points));
    }

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
        string $method = Payment::METHOD_ECHECK,
        string $appliesTo = Payment::APPLIES_TO_BALANCE,
        string $payer = 'tenant',
    ): array {
        // [WP-43] Delinquency and the partial-payment policy are rules about a
        // RESIDENT. Management Review suspends a resident's online payment; an
        // agency is not in review and never can be, and a lease's partial-payment
        // policy is an agreement with the tenant. Neither applies to a HAP
        // remittance, so an agency payment skips both.
        $isAgency = $payer === 'housing_authority';

        if (! $isAgency) {
            $this->guardDelinquency($lease);
        }

        // Before anything is written or sent: a return URL the gateway will
        // refuse is our configuration, not an outage, and it should not leave a
        // failed payment behind to explain.
        $this->gateway->assertUsableReturnUrl($returnUrl);

        // Checked here and not only in the FormRequest, for the same reason
        // AC-DEL-04 checks delinquency here: a tenant can construct the request
        // by hand once the radio has disappeared from the page.
        $this->assertMethodAvailable($method);

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

        // [WP-40] The lease's partial-payment policy governs RENT. A security
        // deposit is a different obligation, and a `full_only` lease must not
        // refuse a deposit instalment on a rule written about the rent — that
        // would be the policy answering a question it was never asked.
        //
        // Evaluated on the RENT amount, never on the total (D-28). A
        // convenience fee must never turn a payment the lease would have
        // accepted into one it rejects.
        if (! $isAgency && $appliesTo !== Payment::APPLIES_TO_DEPOSIT) {
            $verdict = $this->policy->check($lease, $amount);

            if (! $verdict['allowed']) {
                throw ValidationException::withMessages(['amount' => $verdict['reason']]);
            }
        }

        [$payment, $entry] = $this->recordIntent(
            $lease,
            $amount,
            // The card fee is a percentage of the RENT being paid, not of the
            // total — charging a percentage of a figure that already includes
            // the fee would compound it.
            $this->convenienceFee($method, $amount, $payer),
            $idempotencyKey,
            $method,
            $appliesTo,
            $payer,
        );

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
    private function recordIntent(
        Lease $lease,
        Money $amount,
        Money $fee,
        string $idempotencyKey,
        string $method,
        string $appliesTo,
        string $payer,
    ): array {
        return DB::transaction(function () use ($lease, $amount, $fee, $idempotencyKey, $method, $appliesTo, $payer) {
            // [D-28] What the gateway will actually take. When no fee is
            // configured on the chosen rail this is the rent and nothing else,
            // which is every payment taken before WP-39.
            $total = $amount->plus($fee);

            $payment = new Payment;
            $payment->forceFill([
                'lease_id' => $lease->id,
                'tenant_id' => $lease->tenant_id,
                'payer' => $payer,
                'applies_to' => $appliesTo,
                'amount' => $total->toDecimalString(),
                // NULL rather than 0.00 when there is no fee, so "this payment
                // had no fee" and "this payment predates fees" read alike — the
                // distinction has no consumer and inventing one invites a bug.
                'convenience_fee' => $fee->isZero() ? null : $fee->toDecimalString(),
                'method' => $method,
                'gateway' => 'authorize_net',
                'idempotency_key' => $idempotencyKey,
                'status' => Payment::STATUS_PENDING,
                'submitted_at' => now(),
            ])->save();

            // `pending`: visible to the tenant as "processing", counted in no
            // balance anywhere (BR-05, I-6). The matching convenience-fee
            // charge does not exist yet and deliberately so — it posts on
            // settlement, so an abandoned payment never bills a fee.
            $entry = $this->ledger->postPayment(
                $lease,
                $payer,
                $total,
                $appliesTo === Payment::APPLIES_TO_DEPOSIT
                    ? 'Security deposit submitted online'
                    : 'Payment submitted online',
                $payment->id,
                'pending',
                postedOn: null,
                reason: null,
                // Categorised to match what it settles, so that excluding
                // deposits from the arrears figure removes both sides of the
                // pair rather than only the charge.
                category: $appliesTo === Payment::APPLIES_TO_DEPOSIT ? 'deposit' : 'other',
            );

            $this->audit->record('payment.submitted', $payment, [
                'amount' => $total->toDecimalString(),
                'convenience_fee' => $fee->toDecimalString(),
                'method' => $method,
                'gateway' => 'authorize_net',
                // NACHA's WEB code describes an internet-initiated debit from a
                // bank account. It says nothing about a card, and recording it
                // against one would be a false entry in an audit log.
                'sec_code' => $method === Payment::METHOD_ECHECK
                    ? AuthorizeNetGateway::SEC_CODE_WEB
                    : null,
            ]);

            return [$payment, $entry];
        });
    }

    /**
     * A method the tenant is not entitled to use is a validation failure, not a
     * 403 — they have done nothing wrong, the option is simply switched off.
     */
    private function assertMethodAvailable(string $method): void
    {
        if ($method !== Payment::METHOD_CARD) {
            return;
        }

        if ($this->settings->bool('payments.cards_enabled', false)) {
            return;
        }

        throw ValidationException::withMessages([
            'method' => 'Card payments are not available at the moment. '
                .'Please pay by bank transfer, or contact the office.',
        ]);
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
