<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Authorize.Net webhook  [WP-13, API-HOOK-01, TDD §9.1, R-6]
|--------------------------------------------------------------------------
|
| Supplementary, never authoritative. The endpoint records that the gateway
| spoke and marks the payment for priority reconciliation. If it could settle
| a payment, then forging a signature would settle a payment.
|
*/

uses(RefreshDatabase::class);

const SIGNATURE_KEY = '0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF';

beforeEach(function () {
    config(['services.authorize_net.signature_key' => SIGNATURE_KEY]);

    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);

    $this->tenant = Tenant::factory()->create();

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '500.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '0.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ])->save();

    $this->ledger->postCharge(
        $this->lease, 'rent', 'tenant', Money::fromString('500.00'),
        'Rent — February 2026', 'hook:rent', CarbonImmutable::parse('2026-02-01'), '2026-02',
    );

    $this->payment = Payment::factory()->create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->tenant->id,
        'amount' => '500.00',
        'method' => 'echeck',
        'gateway' => 'authorize_net',
        'gateway_transaction_id' => '60123456789',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $entry = $this->ledger->postPayment(
        $this->lease, 'tenant', Money::fromString('500.00'),
        'Payment submitted online', $this->payment->id, 'pending',
    );
    $this->entry = $entry;
});

/** Sign a body the way Authorize.Net does: HMAC-SHA512, hex key, hex digest. */
function signed(array $payload, ?string $key = null): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = strtoupper(hash_hmac('sha512', $body, hex2bin($key ?? SIGNATURE_KEY)));

    return [$body, 'sha512='.$signature];
}

function settlementEvent(string $transactionId = '60123456789', mixed $invoice = null): array
{
    return [
        'notificationId' => (string) Str::uuid(),
        'eventType' => 'net.authorize.payment.authcapture.created',
        'eventDate' => now()->toIso8601String(),
        'payload' => array_filter([
            'id' => $transactionId,
            'invoiceNumber' => $invoice,
            'responseCode' => 1,
            'entityName' => 'transaction',
        ]),
    ];
}

/*
 |--------------------------------------------------------------------------
 | Authentication
 |--------------------------------------------------------------------------
 */

it('rejects an unsigned webhook with 401 and records it', function () {
    $this->call('POST', '/webhooks/authorize-net', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(settlementEvent()))->assertStatus(401);

    expect(DB::table('audit_logs')->where('action', 'payment.webhook.rejected')->exists())->toBeTrue()
        ->and($this->payment->fresh()->webhook_flagged_at)->toBeNull();
});

it('rejects a body that was altered after signing', function () {
    [$body, $signature] = signed(settlementEvent());

    $tampered = str_replace('60123456789', '60999999999', $body);

    $this->call('POST', '/webhooks/authorize-net', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ANET_SIGNATURE' => $signature,
    ], $tampered)->assertStatus(401);
});

it('rejects a signature made with the wrong key', function () {
    [$body, $signature] = signed(settlementEvent(), str_repeat('FE', 32));

    $this->call('POST', '/webhooks/authorize-net', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ANET_SIGNATURE' => $signature,
    ], $body)->assertStatus(401);
});

it('fails closed when no signature key is configured', function () {
    // An unconfigured secret must never mean "accept everything" — that is how
    // an open endpoint reaches production.
    config(['services.authorize_net.signature_key' => null]);

    [$body, $signature] = signed(settlementEvent());

    $this->call('POST', '/webhooks/authorize-net', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ANET_SIGNATURE' => $signature,
    ], $body)->assertStatus(401);
});

it('needs no CSRF token, because the gateway has no cookie', function () {
    [$body, $signature] = signed(settlementEvent());

    $this->call('POST', '/webhooks/authorize-net', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ANET_SIGNATURE' => $signature,
    ], $body)->assertOk();
});

/*
 |--------------------------------------------------------------------------
 | What a valid event does — and does not — do
 |--------------------------------------------------------------------------
 */

it('R-6 flags the payment for reconciliation and moves no balance', function () {
    [$body, $signature] = signed(settlementEvent());

    $this->call('POST', '/webhooks/authorize-net', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ANET_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $payment = $this->payment->fresh();

    expect($payment->webhook_flagged_at)->not->toBeNull()
        ->and($payment->webhook_last_event)->toBe('net.authorize.payment.authcapture.created')
        // The whole point: a webhook that could settle a payment would be a way
        // to settle a payment by forging a signature.
        ->and($payment->status)->toBe('pending')
        ->and($this->entry->fresh()->status)->toBe('pending')
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('cannot settle a payment however the event is dressed up', function () {
    [$body, $signature] = signed([
        'eventType' => 'net.authorize.payment.void.created',
        'payload' => ['id' => '60123456789', 'responseCode' => 1, 'settleAmount' => '500.00'],
    ]);

    $this->call('POST', '/webhooks/authorize-net', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ANET_SIGNATURE' => $signature,
    ], $body)->assertOk();

    expect($this->payment->fresh()->status)->toBe('pending')
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('matches on the invoice number when the transaction id is new to us', function () {
    $this->payment->forceFill(['gateway_transaction_id' => null])->save();

    [$body, $signature] = signed(settlementEvent('60999999999', (string) $this->payment->id));

    $this->call('POST', '/webhooks/authorize-net', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ANET_SIGNATURE' => $signature,
    ], $body)->assertOk();

    // The invoice number is the payment id we sent with the hosted request.
    expect($this->payment->fresh()->gateway_transaction_id)->toBe('60999999999');
});

it('answers 200 for a transaction it has never heard of', function () {
    [$body, $signature] = signed(settlementEvent('60000000000'));

    // A refund issued in their dashboard, or a replay after a restore. A 500
    // here would earn a retry storm and fix nothing.
    $this->call('POST', '/webhooks/authorize-net', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ANET_SIGNATURE' => $signature,
    ], $body)->assertOk();

    expect(DB::table('audit_logs')->where('action', 'payment.webhook.unmatched')->exists())->toBeTrue();
});

it('is safe to deliver twice', function () {
    [$body, $signature] = signed(settlementEvent());
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ANET_SIGNATURE' => $signature];

    $this->call('POST', '/webhooks/authorize-net', [], [], [], $headers, $body)->assertOk();
    $this->call('POST', '/webhooks/authorize-net', [], [], [], $headers, $body)->assertOk();

    expect(Payment::count())->toBe(1)
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});
