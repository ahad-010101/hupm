<?php

namespace App\Jobs;

use App\Concerns\RecordsJobRun;
use App\Domain\Payments\PaymentIntentService;
use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Void payments that never reached the gateway.  [FR-PAY-01 A1, WP-13]
 *
 * A tenant who opens the payment form and closes the tab leaves a `pending`
 * payment and a `pending` ledger entry behind. Neither affects a balance
 * (BR-05), so nothing is *wrong* — but left alone they accumulate, and the
 * portal would show "processing" against money nobody ever sent.
 *
 * Only ever touches rows with **no transaction id**. A payment the gateway has
 * heard of belongs to reconciliation (WP-14), whatever its age: ACH takes 2–5
 * business days and a bank holiday weekend can easily outlast a naive cutoff.
 */
class CleanupAbandonedPayments implements ShouldQueue
{
    use Queueable;
    use RecordsJobRun;

    /** Comfortably longer than anyone spends filling in a bank form. */
    private const ABANDONED_AFTER_HOURS = 24;

    public function handle(PaymentIntentService $intents): int
    {
        return $this->trackJobRun(function () use ($intents) {
            $voided = 0;

            Payment::query()
                ->where('status', Payment::STATUS_PENDING)
                ->where('gateway', 'authorize_net')
                ->whereNull('gateway_transaction_id')
                ->whereNull('webhook_flagged_at')
                ->where('submitted_at', '<', now()->subHours(self::ABANDONED_AFTER_HOURS))
                ->orderBy('id')
                ->chunkById(100, function ($payments) use ($intents, &$voided) {
                    foreach ($payments as $payment) {
                        $intents->abandon($payment, null, 'abandoned at the gateway');
                        $voided++;
                    }
                });

            return $voided;
        });
    }
}
