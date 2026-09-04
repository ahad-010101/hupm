<?php

namespace App\Domain\Payments;

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplate;
use App\Exceptions\GatewayUnavailableException;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentProfile;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What actually happened to a payment.  [FR-PAY-04, TDD §8/§9.1, R-6]
 *
 * **The authoritative path, and the second-highest-risk code in the build.**
 * Authorize.Net reports eCheck outcomes on settlement statements; the webhook
 * is an early hint that may arrive late, twice, or never. Everything a balance
 * depends on is decided here, from the reporting API.
 *
 * The window is ten days, not one. A cron that did not fire is a normal event
 * on shared hosting (I-8), so the job re-reads a window wide enough to absorb
 * a long weekend of silence and heals itself (AC-PAY-13). Re-reading means
 * seeing the same settled transaction on nine consecutive days, which is why
 * every outcome below is guarded by the payment's current status rather than
 * by "have we seen this batch" — status is a fact about our own record, and
 * the only thing a second run cannot get wrong (AC-PAY-12).
 *
 * A payment we cannot match is **never** auto-voided. The money may be real
 * and simply mis-referenced, and voiding it would hide the only evidence that
 * something needs a person.
 */
class ReconciliationService
{
    /** Wide enough to absorb a missed run over a long weekend (FR-PAY-04 step 1). */
    public const WINDOW_DAYS = 10;

    /**
     * Authorize.Net refuses a settled-batch query spanning more than 31 days,
     * with "The date range can not exceed 31 days". Found by asking for 30 and
     * then 180 against the live sandbox.
     *
     * Named as a constant with a test behind it because the failure would be
     * remote and quiet: someone widens the window to be safe after an outage,
     * and the 06:00 job starts throwing into a log nobody reads while balances
     * drift. The ceiling is theirs, not ours.
     */
    public const MAX_WINDOW_DAYS = 31;

    /**
     * Authorize.Net's settlement vocabulary, reduced to the three outcomes
     * that mean anything to a ledger.
     */
    private const SETTLED_STATUSES = ['settledSuccessfully'];

    /**
     * ⚠ **[WP-39 — INCOMPLETE FOR CARDS]** These six are the ACH vocabulary,
     * observed against the live sandbox on 19 Aug 2026. The card dispute
     * statuses are **not** in this list and must be added once observed the
     * same way — they are deliberately absent rather than guessed.
     *
     * Until then a chargeback reaches `apply()` and is not recognised as a
     * failure, so the payment stays settled and the balance stays reduced for
     * money the card network took back. That failure is *silent*, which UI §3.9
     * names as the worst kind this system can have — hence this notice rather
     * than a plausible-looking string.
     *
     * Everything else about the card path is finished and tested; this one line
     * is the sandbox step.
     */
    private const FAILED_STATUSES = [
        'returnedItem', 'declined', 'voided', 'expired', 'failedReview', 'settlementError',
    ];

    /** NOCs change no payment status, so attempt()'s before/after cannot see them. */
    private int $noticesOfChange = 0;

    public function __construct(
        private readonly AuthorizeNetGateway $gateway,
        private readonly LedgerService $ledger,
        private readonly AllocationService $allocations,
        private readonly BalanceCalculator $balances,
        private readonly NotificationService $notifications,
        private readonly BusinessCalendar $calendar,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Reconcile everything in the window.
     *
     * @return array{settled:int, returned:int, noc:int, unmatched:int, errors:list<string>}
     */
    public function run(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= $this->calendar->today();
        $result = ['settled' => 0, 'returned' => 0, 'noc' => 0, 'unmatched' => 0, 'errors' => []];
        $this->noticesOfChange = 0;

        if (! $this->gateway->isConfigured()) {
            $result['errors'][] = 'Gateway not configured.';

            return $result;
        }

        // Payments the webhook already told us about go first, whatever the
        // batch list says. That is the entire purpose of the flag (D-21): a
        // return we have been warned about should not wait for its batch to
        // come round.
        foreach ($this->flaggedPayments() as $payment) {
            $this->attempt($payment, fn () => $this->reconcileByTransactionId($payment), $result);
        }

        foreach ($this->transactionsInWindow($asOf, $result) as $transaction) {
            $payment = $this->match($transaction);

            if (! $payment) {
                continue;
            }

            $this->attempt($payment, fn () => $this->apply($payment, $transaction), $result);
        }

        $result['unmatched'] = $this->flagUnmatched($asOf);
        $result['noc'] = $this->noticesOfChange;

        return $result;
    }

    /**
     * Every transaction in every batch settled in the window.
     *
     * @param  array{settled:int, returned:int, noc:int, unmatched:int, errors:list<string>}  $result
     * @return list<array<string, mixed>>
     */
    private function transactionsInWindow(CarbonImmutable $asOf, array &$result): array
    {
        try {
            $batches = $this->gateway->settledBatches(
                $asOf->subDays(self::WINDOW_DAYS)->startOfDay(),
                $asOf->endOfDay(),
            );
        } catch (GatewayUnavailableException $e) {
            // Reported, not swallowed. A reconciliation that quietly does
            // nothing is the most dangerous failure in the system (UI §3.9),
            // so this has to reach `job_runs` as a failure.
            throw $e;
        }

        $transactions = [];

        foreach ($batches as $batch) {
            $batchId = (string) ($batch['batchId'] ?? '');

            if ($batchId === '') {
                continue;
            }

            try {
                foreach ($this->gateway->batchTransactions($batchId) as $transaction) {
                    $transaction['batchId'] = $batchId;
                    $transactions[] = $transaction;
                }
            } catch (GatewayUnavailableException $e) {
                // One unreadable batch must not cost us the other nine days.
                $result['errors'][] = "Batch {$batchId}: {$e->getMessage()}";
            }
        }

        return $transactions;
    }

    /** @param array<string, mixed> $transaction */
    private function match(array $transaction): ?Payment
    {
        $transactionId = (string) ($transaction['transId'] ?? '');

        if ($transactionId !== '') {
            $payment = Payment::with(['tenant', 'lease'])
                ->where('gateway_transaction_id', $transactionId)
                ->first();

            if ($payment) {
                return $payment;
            }
        }

        // The invoice number is the payment id we sent with the hosted request.
        // It is how a transaction we have never seen an id for finds its home,
        // which is the common case when a tenant closed the tab on return.
        $invoice = $transaction['invoiceNumber'] ?? null;

        return is_numeric($invoice)
            ? Payment::with(['tenant', 'lease'])
                ->where('id', (int) $invoice)
                ->where('gateway', 'authorize_net')
                ->first()
            : null;
    }

    /**
     * Ask the gateway directly about one payment the webhook flagged.
     */
    private function reconcileByTransactionId(Payment $payment): void
    {
        if (! $payment->gateway_transaction_id) {
            return;
        }

        $detail = $this->gateway->transactionDetails($payment->gateway_transaction_id);

        if ($detail !== []) {
            $this->apply($payment, $detail);
        }
    }

    /**
     * One payment, one outcome, one transaction.  [FR-PAY-04 steps 3–5]
     *
     * @param  array<string, mixed>  $transaction
     */
    private function apply(Payment $payment, array $transaction): void
    {
        DB::transaction(function () use ($payment, $transaction) {
            // Re-read under a row lock. Two runs overlapping — a manual re-run
            // during the scheduled one — must not both settle the same payment.
            /** @var Payment $locked */
            $locked = Payment::with(['tenant', 'lease'])
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $status = (string) ($transaction['transactionStatus'] ?? '');
            $items = $this->returnedItems($transaction);
            $noc = $this->firstNoticeOfChange($items);
            $return = $this->firstReturn($items);
            $isReturn = in_array($status, self::FAILED_STATUSES, true) || $return !== null;

            if ($noc !== null) {
                // A NOC is not a failure: the debit worked, but the account
                // details have moved and the token is now stale (AC-PAY-14).
                $this->flagProfileForUpdate($locked, $noc);
            }

            // **A settled payment can still be returned, and usually is.** ACH
            // settles in a day and comes back in three to five, so the return
            // almost always arrives against a payment we have already cleared.
            // Only treating `pending` payments would silently ignore every real
            // R01 in the system — the balance would stay reduced for money the
            // bank took back.
            if ($locked->status === Payment::STATUS_SETTLED) {
                if ($isReturn) {
                    $this->markReturned($locked, $transaction, $return);
                } else {
                    $this->clearFlag($locked);
                }

                return;
            }

            if ($locked->status !== Payment::STATUS_PENDING) {
                // returned, void, failed. Terminal — and re-reading a ten-day
                // window means meeting this transaction again tomorrow, and the
                // day after (AC-PAY-12).
                $this->clearFlag($locked);

                return;
            }

            if (in_array($status, self::SETTLED_STATUSES, true) && ! $isReturn) {
                $this->settle($locked, $transaction);

                return;
            }

            if ($isReturn) {
                $this->markReturned($locked, $transaction, $return);
            }
        });
    }

    /**
     * The money arrived.  [FR-PAY-04 step 3, AC-PAY-10]
     *
     * @param  array<string, mixed>  $transaction
     */
    private function settle(Payment $payment, array $transaction): void
    {
        $entry = $this->entryFor($payment);

        $payment->forceFill([
            'status' => Payment::STATUS_SETTLED,
            'settled_at' => now(),
            'batch_id' => $payment->batch_id ?: ($transaction['batchId'] ?? null),
            'gateway_transaction_id' => $payment->gateway_transaction_id
                ?: ($transaction['transId'] ?? null),
            'webhook_flagged_at' => null,
        ])->save();

        if ($entry && $entry->status === 'pending') {
            // Here, and only here, does the balance move (I-6).
            $this->ledger->transitionStatus($entry, 'cleared');
        }

        // [WP-39] Before allocation, deliberately. The payment entry is the
        // gateway total (D-28), so if the fee charge did not exist yet the
        // allocator would meet $350.93 against a $345.98 rent charge, call the
        // difference an overpayment, credit it forward under Q-8, and then land
        // the fee against that credit — the right balance by a route nobody can
        // follow six months later.
        //
        // Posting it here rather than at submission also means an abandoned or
        // voided payment never bills a fee, so `abandon()` needs no cascade.
        $this->postConvenienceFee($payment);

        $this->allocations->allocate($payment->refresh());

        $this->audit->record('payment.settled', $payment, [
            'amount' => $payment->amount->toDecimalString(),
            'batch_id' => $payment->batch_id,
        ]);

        // Exactly once (AC-PAY-12): this line is only reachable from `pending`,
        // and the row is locked and already written by the time we get here.
        $this->notifications->send(
            NotificationTemplate::PaymentReceipt,
            $payment->tenant?->email,
            [
                'name' => $payment->tenant?->fullName(),
                'amount' => $payment->amount->format(),
                'date' => $this->calendar->today()->format('j F Y'),
                'balance' => $this->balances->tenantBalance($payment->tenant_id)->format(),
                'url' => url('/portal'),
            ],
            tenantId: $payment->tenant_id,
        );
    }

    /**
     * The bank sent it back.  [FR-PAY-04 step 4, AC-PAY-11]
     *
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>|null  $return
     */
    private function markReturned(Payment $payment, array $transaction, ?array $return): void
    {
        $entry = $this->entryFor($payment);
        $code = (string) ($return['code'] ?? $transaction['responseReasonCode'] ?? '');
        $description = (string) ($return['description'] ?? 'Returned by the bank');

        $payment->forceFill([
            'status' => Payment::STATUS_RETURNED,
            'returned_at' => now(),
            'return_code' => $code !== '' ? substr($code, 0, 10) : null,
            'return_description' => substr($description, 0, 255),
            'batch_id' => $payment->batch_id ?: ($transaction['batchId'] ?? null),
            'webhook_flagged_at' => null,
        ])->save();

        if ($entry && in_array($entry->status, ['pending', 'posted', 'cleared'], true)) {
            // [D-22] The status change IS the restoration. `returned` is not a
            // balance-affecting status, so the moment it flips, the payment
            // stops counting and the balance is back where it was. FR-PAY-04
            // also says to post a reversal; doing both would restore the
            // balance twice. See the deviation register.
            $this->ledger->transitionStatus($entry, 'returned');
        }

        // D-02: the charges this payment had covered are outstanding again.
        $this->allocations->reverseFor($payment, "Payment returned ({$code})");

        // [WP-39] The convenience fee goes back with the payment that caused
        // it. Leaving it standing would bill a tenant for the privilege of
        // making a payment that was then taken away from them.
        $this->reverseConvenienceFee($payment);

        $fee = $this->postReturnedFee($payment, $code);

        if ($payment->method === Payment::METHOD_CARD) {
            $this->alertCardDispute($payment, $code, $description);
        }

        $this->audit->record('payment.returned', $payment, [
            'amount' => $payment->amount->toDecimalString(),
            'return_code' => $code,
            'return_description' => $description,
            'fee_posted' => $fee?->toDecimalString(),
        ]);

        $this->notifications->send(
            NotificationTemplate::PaymentReturned,
            $payment->tenant?->email,
            [
                'name' => $payment->tenant?->fullName(),
                'amount' => $payment->amount->format(),
                'date' => $payment->submitted_at?->format('j F Y'),
                // The bank's own words, not a code a tenant cannot read.
                'reason' => $description,
                'fee' => $fee?->format(),
                'balance' => $this->balances->tenantBalance($payment->tenant_id)->format(),
                'url' => url('/portal/pay'),
            ],
            tenantId: $payment->tenant_id,
        );
    }

    /**
     * The lease's returned-payment fee.  [GATE Q-9, I-7]
     *
     * Tenant only — a returned payment is a tenant event and a fee never
     * touches the Housing Authority portion. Idempotent on the payment id, so
     * a second run over the same return cannot charge twice.
     */
    private function postReturnedFee(Payment $payment, string $code): ?Money
    {
        // [WP-39] A card dispute is not a bounced payment. An ACH R01 says the
        // account could not fund the debit — the tenant's own affair, and the
        // fee is the agreed consequence. A chargeback says somebody disputed
        // the charge, and where the cause is card fraud the tenant did nothing
        // and is owed an apology rather than $35 they then have to argue back.
        //
        // So no automatic fee. `alertCardDispute()` puts it in front of an
        // admin with the fee as a decision they take, and own.
        if ($payment->method === Payment::METHOD_CARD) {
            return null;
        }

        if (! $this->settings->bool('fees.returned_fee_automatic', true)) {
            return null;
        }

        if ($payment->payer !== 'tenant') {
            return null;
        }

        $lease = $payment->lease;
        $amount = $lease?->returned_payment_fee;

        if (! $lease || ! $amount instanceof Money || ! $amount->isPositive()) {
            return null;
        }

        $this->ledger->postCharge(
            $lease,
            'returned_fee',
            'tenant',
            $amount,
            'Returned payment fee'.($code !== '' ? " ({$code})" : ''),
            "{$lease->id}:returnedfee:{$payment->id}",
            $this->calendar->today(),
        );

        return $amount;
    }

    /**
     * The card convenience fee for this payment.  [WP-39, Q-7a]
     *
     * Tenant only. A fee never touches the Housing Authority portion (I-7's
     * reasoning, and the HA does not pay by card in any case). Idempotent on
     * the payment id via the charge key, so re-running reconciliation over the
     * same settlement — which happens every day for ten days — cannot charge
     * twice (AC-PAY-12).
     */
    private function postConvenienceFee(Payment $payment): ?LedgerEntry
    {
        $fee = $payment->fee();
        $lease = $payment->lease;

        if ($fee->isZero() || ! $lease || $payment->payer !== 'tenant') {
            return null;
        }

        return $this->ledger->postCharge(
            $lease,
            'convenience_fee',
            'tenant',
            $fee,
            'Card payment fee',
            "{$lease->id}:convfee:pmt{$payment->id}",
            $this->calendar->today(),
        );
    }

    /**
     * Undo the fee when its payment is reversed.  [WP-39, D-22]
     *
     * A status transition rather than a reversing entry, following D-22: for a
     * returned payment the status change **is** the restoration, because
     * `returned` is not in BALANCE_AFFECTING. Posting a reversal as well would
     * credit the tenant the fee twice. The row stays visible on the ledger with
     * its original amount, which is what I-3 actually asks for — nothing is
     * edited and nothing is deleted.
     */
    private function reverseConvenienceFee(Payment $payment): void
    {
        $entry = LedgerEntry::where('charge_key', "{$payment->lease_id}:convfee:pmt{$payment->id}")->first();

        if (! $entry || ! in_array($entry->status, LedgerService::BALANCE_AFFECTING, true)) {
            return;
        }

        $this->ledger->transitionStatus($entry, 'returned');
    }

    /**
     * Put a card dispute in front of a human.  [WP-39]
     *
     * The balance has already been restored by the time this runs; this is not
     * the correction, it is the notification that one happened. A chargeback is
     * the one payment outcome nobody can act on automatically — whether it was
     * fraud, a duplicate, or a tenant who simply changed their mind decides
     * whether a fee is fair, and only a person can weigh that.
     *
     * Audit rather than a new table: `audit_logs` is already the admin-visible
     * record (WP-29) and a dispute is exactly the kind of thing it exists for.
     */
    private function alertCardDispute(Payment $payment, string $code, string $description): void
    {
        $this->audit->record('payment.card_disputed', $payment, [
            'amount' => $payment->amount->toDecimalString(),
            'convenience_fee' => $payment->fee()->toDecimalString(),
            'return_code' => $code,
            'return_description' => $description,
            'settled_at' => $payment->settled_at?->toDateTimeString(),
            // Named so the admin browsing the log knows the fee was a choice
            // withheld from the system, not one it forgot to make.
            'returned_fee_charged' => false,
            'action_required' => 'Review the dispute and decide whether the returned-payment fee applies.',
        ]);
    }

    /**
     * A NOC means retoken, not retry.  [AC-PAY-14]
     *
     * @param  array<string, mixed>  $noc
     */
    private function flagProfileForUpdate(Payment $payment, array $noc): void
    {
        $profiles = PaymentProfile::where('tenant_id', $payment->tenant_id)
            ->where('status', PaymentProfile::STATUS_ACTIVE)
            ->get();

        foreach ($profiles as $profile) {
            $profile->forceFill(['status' => PaymentProfile::STATUS_NEEDS_UPDATE])->save();
        }

        // Audited whether or not a profile existed: "the bank told us this
        // account moved" is worth knowing even for a one-off payment, and
        // admin needs to see it (AC-PAY-14).
        $this->audit->record('payment.noc.received', $payment, [
            'code' => $noc['code'] ?? null,
            'description' => $noc['description'] ?? null,
            'profiles_flagged' => $profiles->count(),
        ]);

        $this->noticesOfChange++;
    }

    /**
     * Pending payments the gateway has heard of but never settled.
     *
     * **Never auto-voided.** The money may be real and simply mis-referenced,
     * and voiding would erase the only evidence that a person needs to look.
     */
    private function flagUnmatched(CarbonImmutable $asOf): int
    {
        $stale = Payment::query()
            ->where('status', Payment::STATUS_PENDING)
            ->where('gateway', 'authorize_net')
            ->whereNotNull('gateway_transaction_id')
            ->where('submitted_at', '<', $asOf->subDays(self::WINDOW_DAYS))
            ->get();

        foreach ($stale as $payment) {
            $this->audit->record('payment.unmatched', $payment, [
                'gateway_transaction_id' => $payment->gateway_transaction_id,
                'submitted_at' => $payment->submitted_at?->toDateString(),
                'note' => 'Outside the reconciliation window and still pending. Needs a person; never auto-voided.',
            ]);
        }

        return $stale->count();
    }

    /** @return Collection<int, Payment> */
    private function flaggedPayments()
    {
        return Payment::query()
            ->where('status', Payment::STATUS_PENDING)
            ->whereNotNull('webhook_flagged_at')
            ->with(['tenant', 'lease'])
            ->orderBy('webhook_flagged_at')
            ->get();
    }

    /**
     * Returned items and notices of change, which arrive in the same list.
     *
     * @param  array<string, mixed>  $transaction
     * @return list<array<string, mixed>>
     */
    private function returnedItems(array $transaction): array
    {
        $items = $transaction['returnedItems'] ?? [];

        // A single item may arrive unwrapped.
        return isset($items['code']) ? [$items] : array_values((array) $items);
    }

    /**
     * NOC codes are C01–C09; return codes are R01 and upwards.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function firstNoticeOfChange(array $items): ?array
    {
        foreach ($items as $item) {
            if (str_starts_with(strtoupper((string) ($item['code'] ?? '')), 'C')) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function firstReturn(array $items): ?array
    {
        foreach ($items as $item) {
            if (str_starts_with(strtoupper((string) ($item['code'] ?? '')), 'R')) {
                return $item;
            }
        }

        return null;
    }

    private function entryFor(Payment $payment): ?LedgerEntry
    {
        return LedgerEntry::where('payment_id', $payment->id)->first();
    }

    private function clearFlag(Payment $payment): void
    {
        if ($payment->webhook_flagged_at) {
            $payment->forceFill(['webhook_flagged_at' => null])->save();
        }
    }

    /**
     * Run one payment's reconciliation without letting it take the run down.
     *
     * Twenty-six tenants share this job. One transaction the gateway describes
     * in a way we did not anticipate must not stop the other twenty-five from
     * being reconciled — but it must be visible, not swallowed.
     *
     * @param  array{settled:int, returned:int, noc:int, unmatched:int, errors:list<string>}  $result
     */
    private function attempt(Payment $payment, callable $work, array &$result): void
    {
        $before = $payment->status;

        try {
            $work();
        } catch (Throwable $e) {
            $result['errors'][] = "Payment {$payment->id}: {$e->getMessage()}";

            $this->audit->record('payment.reconcile.failed', $payment, [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $after = $payment->refresh()->status;

        if ($before === $after) {
            return;
        }

        match ($after) {
            Payment::STATUS_SETTLED => $result['settled']++,
            Payment::STATUS_RETURNED => $result['returned']++,
            default => null,
        };
    }
}
