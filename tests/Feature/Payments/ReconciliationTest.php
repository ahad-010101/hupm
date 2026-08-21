<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Domain\Notifications\NotificationTemplate;
use App\Domain\Payments\ReconciliationService;
use App\Jobs\ReconcilePayments;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentProfile;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\ReconciliationHealth;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Settlement reconciliation and returns  [WP-14, FR-PAY-04, R-6]
|--------------------------------------------------------------------------
|
| The second-highest-risk code in the build. Everything a balance depends on
| is decided here, from the settlement statement — never from the webhook.
|
| The window is ten days, which means the same settled transaction is seen
| again tomorrow, and the day after. Every assertion about "twice" is really
| an assertion about nine consecutive days.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.authorize_net.login_id' => 'sandbox-login',
        'services.authorize_net.transaction_key' => 'sandbox-key',
        'services.authorize_net.environment' => 'sandbox',
    ]);

    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);
    $this->reconciliation = app(ReconciliationService::class);

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
        'returned_payment_fee' => '35.00',
        'status' => 'active',
    ])->save();

    $this->batches = [];
    $this->unreadableBatches = [];
    $this->gatewayDown = false;
    fakeGateway();

    $this->rent = $this->ledger->postCharge(
        $this->lease, 'rent', 'tenant', Money::fromString('500.00'),
        'Rent — February 2026', 'rec:rent', CarbonImmutable::parse('2026-02-01'), '2026-02',
    );
});

/** A submitted-but-not-settled eCheck, exactly as WP-13 leaves it. */
function pendingEcheck(string $amount = '500.00', string $transactionId = '60123456789'): Payment
{
    $payment = Payment::factory()->create([
        'lease_id' => test()->lease->id,
        'tenant_id' => test()->tenant->id,
        'amount' => $amount,
        'method' => 'echeck',
        'gateway' => 'authorize_net',
        'gateway_transaction_id' => $transactionId,
        'idempotency_key' => (string) Str::uuid(),
        'submitted_at' => now()->subDays(2),
    ]);

    test()->ledger->postPayment(
        test()->lease, 'tenant', Money::fromString($amount),
        'Payment submitted online', $payment->id, 'pending',
    );

    return $payment;
}

function anetOk(array $payload): string
{
    return "\xEF\xBB\xBF".json_encode(array_merge([
        'messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'Successful.']]],
    ], $payload));
}

/**
 * A gateway that answers the question it was asked.
 *
 * Registered once, reading `$this->batches` at request time, so a test can
 * change the story mid-run — settle a payment, then have the same transaction
 * come back returned, which is exactly what a real ACH return looks like.
 *
 * A request-aware fake rather than a fixed sequence: `Http::fake()` merges
 * rather than replaces, so a second `fake()` call in one test leaves the first
 * stub in front of it, exhausted. That silently returned an empty batch list
 * and made a genuine failure look like "nothing settled".
 */
function fakeGateway(): void
{
    Http::fake(function ($request) {
        if (test()->gatewayDown) {
            return Http::response('', 500);
        }

        $body = $request->data();
        $name = (string) array_key_first($body);
        $batches = test()->batches;

        if ($name === 'getSettledBatchListRequest') {
            return Http::response(anetOk([
                'batchList' => array_map(fn ($id) => ['batchId' => $id], array_keys($batches)),
            ]));
        }

        if ($name === 'getTransactionListRequest') {
            $batchId = $body[$name]['batchId'];

            return in_array($batchId, test()->unreadableBatches, true)
                ? Http::response('', 500)
                : Http::response(anetOk(['transactions' => $batches[$batchId] ?? []]));
        }

        if ($name === 'getTransactionDetailsRequest') {
            $all = collect($batches)->flatten(1);

            return Http::response(anetOk([
                'transaction' => $all->firstWhere('transId', $body[$name]['transId']) ?? [],
            ]));
        }

        return Http::response(anetOk([]));
    });
}

function settledTransaction(string $transactionId = '60123456789', array $extra = []): array
{
    return array_merge([
        'transId' => $transactionId,
        'transactionStatus' => 'settledSuccessfully',
        'invoiceNumber' => null,
        'settleAmount' => '500.00',
    ], $extra);
}

/*
 |--------------------------------------------------------------------------
 | Settlement
 |--------------------------------------------------------------------------
 */

it('AC-PAY-10 clears the payment, moves the balance by exactly the amount, and emails one receipt', function () {
    $payment = pendingEcheck();

    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');

    $this->batches = ['B-900' => [settledTransaction()]];
    $result = $this->reconciliation->run();

    expect($result['settled'])->toBe(1)
        ->and($payment->fresh()->status)->toBe('settled')
        ->and(LedgerEntry::where('payment_id', $payment->id)->sole()->status)->toBe('cleared')
        // Exactly the amount. Not the settle amount the gateway reported, which
        // we do not treat as authoritative over our own record.
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('0.00')
        ->and(DB::table('notification_logs')->where('template', NotificationTemplate::PaymentReceipt->value)->count())
        ->toBe(1);
});

it('allocates the settled payment against the charge it paid', function () {
    $payment = pendingEcheck();

    $this->batches = ['B-900' => [settledTransaction()]];
    $this->reconciliation->run();

    expect(PaymentAllocation::sole()->charge_entry_id)->toBe($this->rent->id)
        ->and(PaymentAllocation::sole()->amount->toDecimalString())->toBe('500.00');
});

it('AC-PAY-12 produces no duplicate entries and no second receipt on a re-run', function () {
    $payment = pendingEcheck();

    $this->batches = ['B-900' => [settledTransaction()]];
    $this->reconciliation->run();
    $second = $this->reconciliation->run();

    // A ten-day window means seeing this same transaction again tomorrow, and
    // the day after. The guard is our own status, not "have we seen this batch".
    expect($second['settled'])->toBe(0)
        ->and(LedgerEntry::where('payment_id', $payment->id)->count())->toBe(1)
        ->and(PaymentAllocation::count())->toBe(1)
        ->and(DB::table('notification_logs')->where('template', NotificationTemplate::PaymentReceipt->value)->count())
        ->toBe(1)
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('0.00');
});

it('matches on the invoice number when the tenant never came back with an id', function () {
    $payment = pendingEcheck();
    $payment->forceFill(['gateway_transaction_id' => null])->save();

    $this->batches = ['B-900' => [settledTransaction('60999999999', ['invoiceNumber' => (string) $payment->id])]];
    $this->reconciliation->run();

    expect($payment->fresh()->status)->toBe('settled')
        ->and($payment->fresh()->gateway_transaction_id)->toBe('60999999999');
});

/*
 |--------------------------------------------------------------------------
 | Returns
 |--------------------------------------------------------------------------
 */

it('AC-PAY-11 restores the balance, posts the returned fee and stores the code', function () {
    $payment = pendingEcheck();

    // It settled first, as a real return does — the money arrives, then comes
    // back days later.
    $this->batches = ['B-900' => [settledTransaction()]];
    $this->reconciliation->run();
    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('0.00');

    // Days later the bank sends it back. The payment is `settled` by now, not
    // `pending` — which is the normal ACH timeline and the case that matters.
    $this->batches = ['B-900' => [settledTransaction('60123456789', [
        'transactionStatus' => 'returnedItem',
        'returnedItems' => [['code' => 'R01', 'description' => 'Insufficient funds']],
    ])]];
    $result = $this->reconciliation->run();

    expect($result['returned'])->toBe(1)
        ->and($payment->fresh()->status)->toBe('returned')
        ->and($payment->fresh()->return_code)->toBe('R01')
        ->and($payment->fresh()->return_description)->toBe('Insufficient funds')
        ->and(LedgerEntry::where('payment_id', $payment->id)->sole()->status)->toBe('returned')
        // Pre-payment $500 plus the $35 fee the lease sets.
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('535.00')
        ->and(LedgerEntry::where('category', 'returned_fee')->sole()->amount->toDecimalString())
        ->toBe('35.00');
});

it('D-22 restores the balance once, not twice', function () {
    $payment = pendingEcheck();
    $this->lease->forceFill(['returned_payment_fee' => '0.00'])->save();

    $this->batches = ['B-900' => [settledTransaction('60123456789', [
        'transactionStatus' => 'returnedItem',
        'returnedItems' => [['code' => 'R01', 'description' => 'Insufficient funds']],
    ])]];
    $this->reconciliation->run();

    // FR-PAY-04 asks for both a `returned` status AND a reversing entry. Doing
    // both would credit the account twice: `returned` is not a balance-affecting
    // status, so the flip alone puts the balance back. There is no reversal row.
    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00')
        ->and(LedgerEntry::where('type', 'reversal')->count())->toBe(0)
        ->and(LedgerEntry::where('payment_id', $payment->id)->count())->toBe(1);
});

it('D-02 puts the charges the payment had covered back to outstanding', function () {
    $payment = pendingEcheck();

    $this->batches = ['B-900' => [settledTransaction()]];
    $this->reconciliation->run();
    expect($this->balances->outstandingCharges($this->tenant->id))->toBeEmpty();

    $this->batches = ['B-900' => [settledTransaction('60123456789', [
        'transactionStatus' => 'returnedItem',
        'returnedItems' => [['code' => 'R01', 'description' => 'Insufficient funds']],
    ])]];
    $this->reconciliation->run();

    $outstanding = $this->balances->outstandingCharges($this->tenant->id);

    expect($outstanding->pluck('id'))->toContain($this->rent->id)
        // Evidence, not a delete.
        ->and(PaymentAllocation::sole()->isReversed())->toBeTrue();
});

it('emails the tenant about a return without ever saying "failed"', function () {
    pendingEcheck();

    $this->batches = ['B-900' => [settledTransaction('60123456789', [
        'transactionStatus' => 'returnedItem',
        'returnedItems' => [['code' => 'R01', 'description' => 'Insufficient funds']],
    ])]];
    $this->reconciliation->run();

    $log = DB::table('notification_logs')
        ->where('template', NotificationTemplate::PaymentReturned->value)
        ->sole();

    // UI §8: an ACH return is a bank event. A tenant told their payment failed
    // pays a second time.
    expect($log->subject)->toBe('Your payment was returned by the bank')
        ->and(strtolower($log->subject))->not->toContain('failed');
});

it('GATE Q-9 posts no returned fee when the setting is off', function () {
    app(Settings::class)->set('fees.returned_fee_automatic', false);
    app(Settings::class)->flush();

    pendingEcheck();
    $this->batches = ['B-900' => [settledTransaction('60123456789', [
        'transactionStatus' => 'returnedItem',
        'returnedItems' => [['code' => 'R01', 'description' => 'Insufficient funds']],
    ])]];
    $this->reconciliation->run();

    expect(LedgerEntry::where('category', 'returned_fee')->count())->toBe(0)
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('charges the returned fee once even if the return is seen again', function () {
    pendingEcheck();
    $this->batches = ['B-900' => [settledTransaction('60123456789', [
        'transactionStatus' => 'returnedItem',
        'returnedItems' => [['code' => 'R01', 'description' => 'Insufficient funds']],
    ])]];

    $this->reconciliation->run();
    $this->reconciliation->run();

    expect(LedgerEntry::where('category', 'returned_fee')->count())->toBe(1)
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('535.00');
});

/*
 |--------------------------------------------------------------------------
 | Notice of change
 |--------------------------------------------------------------------------
 */

it('AC-PAY-14 flags the stored profile and alerts admin on a NOC', function () {
    $payment = pendingEcheck();

    $profile = new PaymentProfile;
    $profile->forceFill([
        'tenant_id' => $this->tenant->id,
        'gateway_customer_profile_id' => '5001',
        'gateway_payment_profile_id' => '9001',
        'descriptor' => 'Checking ••••6789',
        'status' => PaymentProfile::STATUS_ACTIVE,
        'created_at' => now(), 'updated_at' => now(),
    ])->save();

    $this->batches = ['B-900' => [settledTransaction('60123456789', [
        'returnedItems' => [['code' => 'C01', 'description' => 'Incorrect account number']],
    ])]];
    $result = $this->reconciliation->run();

    expect($result['noc'])->toBe(1)
        // A NOC is not a failure: the debit worked, the account details moved.
        ->and($payment->fresh()->status)->toBe('settled')
        ->and($profile->fresh()->status)->toBe(PaymentProfile::STATUS_NEEDS_UPDATE)
        ->and($profile->fresh()->isUsable())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'payment.noc.received')->exists())->toBeTrue();
});

/*
 |--------------------------------------------------------------------------
 | The window, and what it does not touch
 |--------------------------------------------------------------------------
 */

it('AC-PAY-13 processes the whole backlog after three days of silence', function () {
    $a = pendingEcheck('500.00', '60000000001');
    $b = pendingEcheck('120.00', '60000000002');
    $c = pendingEcheck('80.00', '60000000003');

    $this->batches = [
        'B-1' => [
            settledTransaction('60000000001'),
            settledTransaction('60000000002', ['settleAmount' => '120.00']),
        ],
        'B-2' => [
            settledTransaction('60000000003', [
                'transactionStatus' => 'returnedItem',
                'returnedItems' => [['code' => 'R02', 'description' => 'Account closed']],
            ]),
        ],
    ];

    $result = $this->reconciliation->run();

    // Three days of settlement in one run, two outcomes, no special mode.
    expect($result['settled'])->toBe(2)
        ->and($result['returned'])->toBe(1)
        ->and($a->fresh()->status)->toBe('settled')
        ->and($b->fresh()->status)->toBe('settled')
        ->and($c->fresh()->status)->toBe('returned')
        ->and($c->fresh()->return_code)->toBe('R02');
});

it('never auto-voids a payment it cannot match, and surfaces it instead', function () {
    $payment = pendingEcheck();
    $payment->forceFill(['submitted_at' => now()->subDays(14)])->save();

    $this->batches = ['B-900' => [settledTransaction('60000000999')]];
    $result = $this->reconciliation->run();

    // The money may be real and simply mis-referenced. Voiding would erase the
    // only evidence that a person needs to look (FR-PAY-04 step 6).
    expect($result['unmatched'])->toBe(1)
        ->and($payment->fresh()->status)->toBe('pending')
        ->and(DB::table('audit_logs')->where('action', 'payment.unmatched')->exists())->toBeTrue();
});

it('leaves an admin-recorded cheque alone', function () {
    $cheque = Payment::factory()->settled()->create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->tenant->id,
        'amount' => '200.00',
        'method' => 'cheque',
        'gateway' => 'manual',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $this->batches = ['B-900' => [settledTransaction('60000000999')]];
    $this->reconciliation->run();

    // Manual money never went near the gateway and is already settled.
    expect($cheque->fresh()->status)->toBe('settled')
        ->and(DB::table('notification_logs')->count())->toBe(0);
});

it('one unreadable batch does not cost the other nine days', function () {
    $payment = pendingEcheck();

    $this->batches = ['B-BAD' => [], 'B-GOOD' => [settledTransaction()]];
    $this->unreadableBatches = ['B-BAD'];

    $result = $this->reconciliation->run();

    expect($result['settled'])->toBe(1)
        ->and($result['errors'])->toHaveCount(1)
        ->and($payment->fresh()->status)->toBe('settled');
});

/*
 |--------------------------------------------------------------------------
 | The job, and knowing it ran
 |--------------------------------------------------------------------------
 */

it('records every run, so a cron that never fires is visible', function () {
    pendingEcheck();
    $this->batches = ['B-900' => [settledTransaction()]];

    app(ReconcilePayments::class)->handle(
        app(ReconciliationService::class),
        app(AuditLogger::class),
    );

    $run = DB::table('job_runs')->where('job_name', ReconcilePayments::class)->sole();

    expect($run->status)->toBe('success')
        ->and($run->records_processed)->toBe(1)
        ->and($run->finished_at)->not->toBeNull();
});

it('keeps the settlement window inside the 31-day ceiling the gateway enforces', function () {
    // Authorize.Net rejects a wider range outright. Widening this "to be safe"
    // after an outage would break the 06:00 job remotely and quietly, which is
    // the worst way for this particular job to fail.
    expect(ReconciliationService::WINDOW_DAYS)
        ->toBeLessThanOrEqual(ReconciliationService::MAX_WINDOW_DAYS);
});

it('R-6 calls reconciliation stale after 36 hours, and not before', function () {
    $health = app(ReconciliationHealth::class);

    expect($health->status()['never_run'])->toBeTrue()
        // Never run is not the same as broken, and they need different words.
        ->and($health->status()['stale'])->toBeFalse();

    $record = fn (string $when) => DB::table('job_runs')->insert([
        'job_name' => ReconcilePayments::class,
        'started_at' => $when, 'finished_at' => $when,
        'status' => 'success', 'records_processed' => 0, 'created_at' => $when,
    ]);

    $record(now()->subHours(30)->toDateTimeString());
    expect($health->status()['stale'])->toBeFalse();

    DB::table('job_runs')->delete();
    $record(now()->subHours(40)->toDateTimeString());

    // A daily job is not late at 06:05 the next morning, and a banner that
    // cries wolf every morning is a banner nobody reads.
    expect($health->status()['stale'])->toBeTrue()
        ->and($health->status()['hours_ago'])->toBe(40);
});

it('a failed run leaves a failed record rather than silence', function () {
    pendingEcheck();
    $this->gatewayDown = true;

    try {
        app(ReconcilePayments::class)->handle(
            app(ReconciliationService::class),
            app(AuditLogger::class),
        );
    } catch (Throwable) {
        // The job is meant to fail loudly here.
    }

    expect(DB::table('job_runs')->where('status', 'failed')->count())->toBe(1)
        // And nothing moved.
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

/*
 |--------------------------------------------------------------------------
 | The admin surface
 |--------------------------------------------------------------------------
 */

it('API-ADM-16 lets an admin reconcile by hand, using the same service', function () {
    $payment = pendingEcheck();
    $this->batches = ['B-900' => [settledTransaction()]];

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post('/admin/payments/reconcile')
        ->assertRedirect()
        ->assertSessionHas('status', fn (string $s) => str_contains($s, '1 settled'));

    expect($payment->fresh()->status)->toBe('settled');
});

it('R-6 clears the staleness banner when an admin runs it by hand', function () {
    // The banner tells the admin to run it by hand. Running it by hand used to
    // record nothing, because the button called the service while the freshness
    // check reads what the *job* writes — so the banner stayed up however many
    // times it was pressed. An alarm whose own remedy cannot silence it is an
    // alarm people learn to ignore.
    DB::table('job_runs')->insert([
        'job_name' => ReconcilePayments::class,
        'started_at' => now()->subHours(62),
        'finished_at' => now()->subHours(62),
        'status' => 'success',
        'records_processed' => 0,
        'created_at' => now()->subHours(62),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);

    expect(app(ReconciliationHealth::class)->status()['stale'])->toBeTrue();

    $this->batches = ['B-900' => [settledTransaction()]];
    $this->actingAs($admin)->post('/admin/payments/reconcile')->assertRedirect();

    expect(app(ReconciliationHealth::class)->status()['stale'])->toBeFalse();

    $this->actingAs($admin)->get('/admin/payments')
        ->assertInertia(fn ($page) => $page->where('reconciliation.stale', false));
});

it('keeps a tenant out of the reconciliation action', function () {
    $user = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $this->actingAs($user)->post('/admin/payments/reconcile')->assertForbidden();
});

it('R-6 puts the staleness warning on every admin page, not one screen', function () {
    DB::table('job_runs')->insert([
        'job_name' => ReconcilePayments::class,
        'started_at' => now()->subHours(40), 'finished_at' => now()->subHours(40),
        'status' => 'success', 'records_processed' => 0, 'created_at' => now()->subHours(40),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);

    // A reconciliation that stopped is invisible by nature: nothing errors and
    // no page looks broken. The warning has to follow the admin around rather
    // than wait on a screen nobody opens.
    foreach (['/admin', '/admin/tenants', '/admin/ledger'] as $url) {
        $this->actingAs($admin)->get($url)
            ->assertInertia(fn ($page) => $page->where('reconciliation.stale', true));
    }
});

it('never shows a tenant the reconciliation state', function () {
    $user = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $this->actingAs($user)->get('/portal')
        ->assertInertia(fn ($page) => $page->where('reconciliation', null));
});

it('shows the last successful run on the payments screen at all times', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get('/admin/payments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Payments/Index')
            ->where('reconciliation.never_run', true)
            ->has('unmatchedCount'));
});
