<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Payments\AuthorizeNetGateway;
use App\Domain\Payments\PaymentIntentService;
use App\Exceptions\GatewayUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\CreatePaymentRequest;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentProfile;
use App\Models\Tenant;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's own payment flow.  [UI §3.2/§3.3, API-POR-05/06, FR-PAY-01]
 *
 * **Nothing on this path may name the Housing Authority portion** (I-4). The
 * balance a tenant is shown, offered to pay, and charged is the tenant portion
 * and nothing else — not filtered in the component, absent from the props.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentIntentService $intents,
        private readonly BalanceCalculator $balances,
        private readonly AuthorizeNetGateway $gateway,
        private readonly Settings $settings,
    ) {}

    /** UI §3.2. */
    public function show(Request $request): Response
    {
        $tenant = $this->tenantFor($request);
        $lease = $tenant?->activeLease();

        $inReview = $lease?->delinquency_state === 'management_review';

        return Inertia::render('Portal/Pay', [
            // AC-LED-02 / I-4: the tenant portion. There is no other figure here.
            'balance' => $tenant ? (string) $this->balances->tenantBalance($tenant->id) : null,
            'pending' => $tenant ? (string) $this->balances->pendingPayments($tenant->id) : null,
            'hasLease' => $lease !== null,
            'leaseId' => $lease?->id,
            // AC-PAY-03: the form is replaced, not disabled — a disabled form
            // still tells someone to try harder.
            'inManagementReview' => $inReview,
            'officePhone' => $this->settings->string('company.phone')
                ?: $this->settings->string('company.emergency_phone'),
            'policy' => $lease ? $this->policyFor($lease) : null,
            'savedMethods' => $tenant ? $this->savedMethodsFor($tenant) : [],
            'gatewayReady' => $this->gateway->isConfigured(),
            // One per render, so a double submit is one payment (AC-PAY-02).
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    /** API-POR-05. Returns JSON; the browser posts the token to the gateway. */
    public function store(CreatePaymentRequest $request): JsonResponse
    {
        $lease = Lease::with('tenant')->findOrFail($request->integer('lease_id'));

        try {
            $intent = $this->intents->create(
                lease: $lease,
                amount: Money::fromString($request->string('amount')->value()),
                idempotencyKey: $request->string('idempotency_key')->value(),
                returnUrl: route('portal.pay.confirm'),
                cancelUrl: route('portal.pay.confirm', ['cancelled' => 1]),
            );
        } catch (GatewayUnavailableException $e) {
            // 502, and the message is the one the tenant sees. Nothing has been
            // charged and the balance has not moved (AC-PAY-04).
            return response()->json([
                'message' => "We couldn't reach the payment provider. Nothing has been charged. Please try again shortly.",
            ], 502);
        }

        return response()->json([
            'payment_id' => $intent['payment']->id,
            'hosted_token' => $intent['token'],
            'redirect_url' => $intent['url'],
        ]);
    }

    /**
     * API-POR-06. The tenant is back from the gateway.
     *
     * A GET that only reads and, at most, files a transaction id it was handed.
     * Landing here twice — a refresh, a browser back — cannot create a second
     * payment, because there is nothing here that creates one (UI §7).
     */
    public function confirm(Request $request): Response
    {
        $tenant = $this->tenantFor($request);
        $payment = $this->latestPendingFor($tenant);

        $cancelled = $request->boolean('cancelled');

        if ($payment && $cancelled) {
            $this->intents->abandon($payment, null, 'cancelled at the gateway');
        }

        if ($payment && ! $cancelled) {
            $this->intents->recordReturn($payment, $this->transactionIdFrom($request));
            $this->syncSavedMethods($tenant);
        }

        return Inertia::render('Portal/PayResult', [
            'state' => $cancelled ? 'cancelled' : ($payment ? 'submitted' : 'unknown'),
            'amount' => $payment && ! $cancelled ? (string) $payment->amount : null,
            'reference' => $payment && ! $cancelled ? $payment->id : null,
            'balance' => $tenant ? (string) $this->balances->tenantBalance($tenant->id) : null,
        ]);
    }

    /**
     * Bring the saved methods in line with CIM.  [FR-PAY-02, AC-PAY-06]
     *
     * Only ever writes an opaque id and the descriptor the gateway produced.
     * If the gateway is unreachable this quietly does nothing — a stale list of
     * saved methods is not worth failing a tenant's return page over.
     */
    private function syncSavedMethods(?Tenant $tenant): void
    {
        if (! $tenant || ! $this->gateway->isConfigured()) {
            return;
        }

        try {
            $customerProfileId = $this->gateway->customerProfileId($tenant);

            if (! $customerProfileId) {
                return;
            }

            $profiles = $this->gateway->paymentProfiles($customerProfileId);
        } catch (GatewayUnavailableException) {
            return;
        }

        foreach ($profiles as $profile) {
            DB::table('payment_profiles')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'gateway_payment_profile_id' => $profile['id'],
                ],
                [
                    'gateway_customer_profile_id' => $customerProfileId,
                    'descriptor' => $profile['descriptor'],
                    'status' => PaymentProfile::STATUS_ACTIVE,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    /** @return array<string, mixed> */
    private function policyFor(Lease $lease): array
    {
        return [
            'partial_payment_policy' => $lease->partial_payment_policy,
            'minimum' => $lease->partial_minimum_amount ? (string) $lease->partial_minimum_amount : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function savedMethodsFor(Tenant $tenant): array
    {
        return PaymentProfile::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', PaymentProfile::STATUS_REMOVED)
            ->orderBy('id')
            ->get()
            // The descriptor and the status. Never an id the browser has no use
            // for, and never anything resembling an account number (I-5).
            ->map(fn (PaymentProfile $profile) => [
                'id' => $profile->id,
                'descriptor' => $profile->descriptor,
                'needs_update' => $profile->status === PaymentProfile::STATUS_NEEDS_UPDATE,
            ])
            ->all();
    }

    private function tenantFor(Request $request): ?Tenant
    {
        $tenantId = $request->user()?->tenant_id;

        return $tenantId ? Tenant::find($tenantId) : null;
    }

    private function latestPendingFor(?Tenant $tenant): ?Payment
    {
        if (! $tenant) {
            return null;
        }

        return Payment::query()
            ->where('tenant_id', $tenant->id)
            ->where('gateway', 'authorize_net')
            ->where('status', Payment::STATUS_PENDING)
            ->orderByDesc('id')
            ->first();
    }

    private function transactionIdFrom(Request $request): ?string
    {
        // Accept Hosted names it differently depending on the integration mode,
        // and may not send one at all. Reconciliation is what makes a payment
        // real (R-6); this is only a shortcut when the id happens to arrive.
        foreach (['transId', 'transactionId', 'x_trans_id'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                return substr($value, 0, 60);
            }
        }

        return null;
    }
}
