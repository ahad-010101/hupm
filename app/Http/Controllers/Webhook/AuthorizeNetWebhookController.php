<?php

namespace App\Http\Controllers\Webhook;

use App\Domain\Payments\AuthorizeNetGateway;
use App\Models\Payment;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Gateway events.  [API-HOOK-01, TDD §9.1, WP-13]
 *
 * **Supplementary, never authoritative.** Authorize.Net reports ACH returns on
 * settlement statements; webhooks are an early hint that may arrive late, twice
 * or not at all. WP-14's daily reconciliation is the source of truth (R-6).
 *
 * So this endpoint does exactly two things: record that the gateway spoke, and
 * mark the payment for priority reconciliation. **It never moves a balance.**
 * A webhook that could settle a payment would be a way to settle a payment by
 * forging a signature, and the return codes that matter arrive on the
 * statement anyway.
 *
 * Always 200 on a valid signature, whatever we made of the contents — a 500
 * here earns a retry storm from the gateway, and there is nothing a retry
 * would fix.
 */
class AuthorizeNetWebhookController
{
    public function __invoke(Request $request, AuthorizeNetGateway $gateway, AuditLogger $audit): Response
    {
        $raw = $request->getContent();

        if (! $gateway->webhookSignatureIsValid($raw, $request->header('X-ANET-Signature'))) {
            // The signature is the only authentication this endpoint has, so a
            // failure is a security event, not a validation error.
            $audit->record('payment.webhook.rejected', null, [
                'reason' => 'invalid signature',
                'ip' => $request->ip(),
                'event_type' => substr((string) $request->input('eventType'), 0, 60),
            ]);

            Log::warning('Rejected an unsigned Authorize.Net webhook.', [
                'ip' => $request->ip(),
            ]);

            return response('Invalid signature', 401);
        }

        $eventType = (string) $request->input('eventType');
        $transactionId = (string) $request->input('payload.id');
        $invoice = $request->input('payload.invoiceNumber');

        $payment = $this->findPayment($transactionId, $invoice);

        if (! $payment) {
            // Not an error. A transaction we never recorded — a refund issued
            // in their dashboard, a replay after a restore — is the gateway's
            // business, and 200 stops it retrying forever.
            $audit->record('payment.webhook.unmatched', null, [
                'event_type' => $eventType,
                'gateway_transaction_id' => $transactionId,
            ]);

            return response('', 200);
        }

        $payment->forceFill([
            // A timestamp rather than a flag: "when did the gateway tell us"
            // is the question a disputed return turns on (D-21).
            'webhook_flagged_at' => now(),
            'webhook_last_event' => substr($eventType, 0, 60),
            // Filed if we did not already have it, so reconciliation can match
            // on it tomorrow. This is a reference, not a state change.
            'gateway_transaction_id' => $payment->gateway_transaction_id ?: ($transactionId ?: null),
        ])->save();

        $audit->record('payment.webhook.received', $payment, [
            'event_type' => $eventType,
            'gateway_transaction_id' => $transactionId,
            // Stated so it is on the record that nothing moved here.
            'balance_effect' => 'none — reconciliation is authoritative (R-6)',
        ]);

        return response('', 200);
    }

    private function findPayment(string $transactionId, mixed $invoice): ?Payment
    {
        if ($transactionId !== '') {
            $payment = Payment::where('gateway_transaction_id', $transactionId)->first();

            if ($payment) {
                return $payment;
            }
        }

        // The invoice number is the payment id we sent with the hosted request,
        // which is how a first-ever event for a transaction finds its home.
        return is_numeric($invoice)
            ? Payment::where('id', (int) $invoice)->where('gateway', 'authorize_net')->first()
            : null;
    }
}
