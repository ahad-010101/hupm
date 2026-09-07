<?php

namespace App\Http\Controllers\Ha;

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Payments\PaymentIntentService;
use App\Exceptions\GatewayUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * An agency paying its portion.  [WP-43]
 *
 * **One payment per lease, never a lump sum.** Each payment stays attached to
 * the lease it settles, so nothing is ever split afterwards and reconciliation
 * is untouched — which is exactly the R-9 risk the reconciliation code warns
 * about. "Pay all" is a convenience over the same per-lease intents, not a
 * different mechanism.
 *
 * At roughly 25¢ an ACH debit, seventeen debits is the cheapest insurance in
 * the project.
 *
 * **ACH only.** No card and no convenience fee: an agency remits by bank
 * transfer, and adding a card fee to public money would be indefensible.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentIntentService $intents,
        private readonly BalanceCalculator $balances,
    ) {}

    /** Start a payment for one lease. Returns the hosted-page token. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lease_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'idempotency_key' => ['required', 'string', 'size:36'],
        ], [
            'amount.gt' => 'Enter an amount greater than zero.',
        ]);

        $lease = $this->authorityLease($request, (int) $validated['lease_id']);

        try {
            $intent = $this->intents->create(
                lease: $lease,
                amount: Money::fromString($validated['amount']),
                idempotencyKey: $validated['idempotency_key'],
                returnUrl: route('agency.pay.confirm'),
                cancelUrl: route('agency.pay.confirm', ['cancelled' => 1]),
                method: Payment::METHOD_ECHECK,
                appliesTo: Payment::APPLIES_TO_BALANCE,
                payer: 'housing_authority',
            );
        } catch (GatewayUnavailableException $e) {
            Log::warning('An agency could not start a payment.', [
                'lease_id' => $lease->id,
                'housing_authority_id' => $request->user()->housing_authority_id,
                'detail' => $e->getMessage(),
                ...$e->context,
            ]);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'payment_id' => $intent['payment']->id,
            'hosted_token' => $intent['token'],
            'redirect_url' => $intent['url'],
        ]);
    }

    /**
     * The lease, if it is one this agency funds.
     *
     * A lease belonging to another agency is a **404, not a 403** — a 403 would
     * confirm it exists, and which tenancies a rival agency funds is not this
     * one's business (I-9).
     */
    private function authorityLease(Request $request, int $leaseId): Lease
    {
        $lease = Lease::with('tenant')->find($leaseId);

        abort_if(
            $lease === null
                || $lease->housing_authority_id !== $request->user()->housing_authority_id,
            404,
        );

        return $lease;
    }

    /** The page an agency is returned to from the gateway. */
    public function confirm(Request $request)
    {
        $authorityId = $request->user()->housing_authority_id;

        return Inertia::render('Ha/PayResult', [
            'cancelled' => $request->boolean('cancelled'),
            'total' => (string) $this->balances->authorityBalance($authorityId),
        ]);
    }

    /** A fresh key per render, so a double submit is one payment (AC-PAY-02). */
    public static function newIdempotencyKey(): string
    {
        return (string) Str::uuid();
    }
}
