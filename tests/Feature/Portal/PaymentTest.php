<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Jobs\CleanupAbandonedPayments;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Tenant-initiated eCheck payment  [WP-13, FR-PAY-01/02, TDD §9.1]
|--------------------------------------------------------------------------
|
| The rule the whole package turns on: submitting is not paying. A payment
| reduces a balance on settlement and never before (I-6), because ACH takes
| 2–5 business days and can still come back.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    RateLimiter::clear('tenant:1');

    config([
        'services.authorize_net.login_id' => 'sandbox-login',
        'services.authorize_net.transaction_key' => 'sandbox-key',
        'services.authorize_net.environment' => 'sandbox',
        'services.authorize_net.signature_key' => str_repeat('AB', 32),
    ]);

    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);

    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '700.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'partial_payment_policy' => 'partial_allowed',
        'status' => 'active',
    ])->save();

    // $500 owed by the tenant, $700 by the authority.
    $this->ledger->postCharge(
        $this->lease, 'rent', 'tenant', Money::fromString('500.00'),
        'Rent — February 2026', 'pay:tenant', CarbonImmutable::parse('2026-02-01'), '2026-02',
    );
    $this->ledger->postCharge(
        $this->lease, 'rent', 'housing_authority', Money::fromString('700.00'),
        'Rent — February 2026', 'pay:ha', CarbonImmutable::parse('2026-02-01'), '2026-02',
    );

    $this->actingAs($this->user);
});

/** One Authorize.Net JSON body, BOM and all. */
function anetBody(array $payload): string
{
    return "\xEF\xBB\xBF".json_encode(array_merge([
        'messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'Successful.']]],
    ], $payload));
}

function payPayload(array $overrides = []): array
{
    return array_merge([
        'lease_id' => test()->lease->id,
        'amount' => '500.00',
        'idempotency_key' => (string) Str::uuid(),
    ], $overrides);
}

/*
 |--------------------------------------------------------------------------
 | Submitting
 |--------------------------------------------------------------------------
 */

it('AC-PAY-01 does not move the balance when a payment is submitted', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'hosted-token-abc']))]);

    $this->postJson('/portal/pay', payPayload())
        ->assertOk()
        ->assertJsonStructure(['payment_id', 'hosted_token', 'redirect_url'])
        ->assertJsonPath('hosted_token', 'hosted-token-abc');

    $entry = LedgerEntry::where('type', 'payment')->sole();

    expect(Payment::sole()->status)->toBe('pending')
        // BR-05: pending moves nothing. Submitting is not paying (I-6).
        ->and($entry->status)->toBe('pending')
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00')
        // Shown beside the balance, never inside it.
        ->and($this->balances->pendingPayments($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('AC-PAY-02 creates one gateway transaction when Pay is clicked twice', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'hosted-token-abc']))]);

    $payload = payPayload();

    $first = $this->postJson('/portal/pay', $payload)->assertOk();
    $second = $this->postJson('/portal/pay', $payload)->assertOk();

    expect(Payment::count())->toBe(1)
        ->and(LedgerEntry::where('type', 'payment')->count())->toBe(1)
        ->and($second->json('payment_id'))->toBe($first->json('payment_id'))
        // The same form, not a second one. Two tokens would be two chances to
        // submit the same payment.
        ->and($second->json('hosted_token'))->toBe($first->json('hosted_token'));
});

it('sends the amount and an invoice reference, and never anything resembling bank data', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'hosted-token-abc']))]);

    $this->postJson('/portal/pay', payPayload(['amount' => '250.00']));

    Http::assertSent(function ($request) {
        $body = $request->data();
        $transaction = $body['getHostedPaymentPageRequest']['transactionRequest'] ?? [];

        return ($transaction['amount'] ?? null) === '250.00'
            && ($transaction['order']['invoiceNumber'] ?? null) === (string) Payment::sole()->id
            // AC-PAY-05: what leaves here is an amount and a reference.
            && ! str_contains(strtolower(json_encode($body)), 'routing')
            && ! str_contains(strtolower(json_encode($body)), 'accountnumber');
    });
});

it('offers no card option — cards are out of scope for v1', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'hosted-token-abc']))]);

    $this->postJson('/portal/pay', payPayload());

    Http::assertSent(function ($request) {
        // The intent also asks whether this tenant has a saved profile, so more
        // than one request is recorded. Only the hosted-page one carries this.
        $settings = collect($request->data()['getHostedPaymentPageRequest']['hostedPaymentSettings']['setting'] ?? [])
            ->firstWhere('settingName', 'hostedPaymentPaymentOptions');

        if (! $settings) {
            return false;
        }

        $decoded = json_decode($settings['settingValue'], true);

        // Q-7: offering a card field we do not reconcile is worse than not
        // offering one.
        return $decoded['showCreditCard'] === false && $decoded['showBankAccount'] === true;
    });
});

/*
 |--------------------------------------------------------------------------
 | When it goes wrong
 |--------------------------------------------------------------------------
 */

it('AC-PAY-04 leaves the balance alone when the gateway is unreachable', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response('', 503)]);

    $this->postJson('/portal/pay', payPayload())
        ->assertStatus(502)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Nothing has been charged'));

    // F1: the attempt is on the record, and neither row counts.
    expect(Payment::sole()->status)->toBe('failed')
        ->and(LedgerEntry::where('type', 'payment')->sole()->status)->toBe('void')
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00')
        ->and($this->balances->pendingPayments($this->tenant->id)->toDecimalString())->toBe('0.00');
});

it('treats a gateway error response as unreachable rather than as a rejection', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(
        "\xEF\xBB\xBF".json_encode([
            'messages' => ['resultCode' => 'Error', 'message' => [['code' => 'E00007', 'text' => 'Authentication failed.']]],
        ])
    )]);

    $this->postJson('/portal/pay', payPayload())->assertStatus(502);

    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('I-5 keeps our own credentials out of the log when the gateway echoes them back', function () {
    // Found by making a real call with a wrong credential: Authorize.Net quotes
    // the value it objected to, so a rejected API Login ID arrives inside the
    // error message and goes straight into the log unless it is scrubbed.
    Log::spy();

    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody([
        'messages' => [
            'resultCode' => 'Error',
            'message' => [[
                'code' => 'E00003',
                'text' => "The value &#39;sandbox-login&#39; is invalid. Key sandbox-key rejected.",
            ]],
        ],
    ]))]);

    $this->postJson('/portal/pay', payPayload())->assertStatus(502);

    Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
        return ! str_contains($context['text'], 'sandbox-login')
            && ! str_contains($context['text'], 'sandbox-key')
            && str_contains($context['text'], '[redacted]')
            // The code survives, because that is what makes the line useful.
            && $context['code'] === 'E00003';
    });
});

it('AC-PAY-03 refuses a payment while the account is in Management Review', function () {
    $this->lease->forceFill(['delinquency_state' => 'management_review'])->save();
    Http::fake();

    // AC-DEL-04 is specifically about constructing the request once the button
    // has gone, so the refusal lives in the service and not only in the view.
    $this->postJson('/portal/pay', payPayload())->assertForbidden();

    expect(Payment::count())->toBe(0);
    Http::assertNothingSent();
});

it('replaces the pay form with Contact Management rather than disabling it', function () {
    $this->lease->forceFill(['delinquency_state' => 'management_review'])->save();

    $this->get('/portal/pay')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Portal/Pay')
            ->where('inManagementReview', true));
});

it('enforces the lease partial-payment policy with a reason a tenant can act on', function () {
    $this->lease->forceFill(['partial_payment_policy' => 'full_only'])->save();
    Http::fake();

    $this->postJson('/portal/pay', payPayload(['amount' => '100.00']))
        ->assertStatus(422)
        ->assertJsonPath('errors.amount.0', fn (string $m) => str_contains($m, 'full balance'));

    Http::assertNothingSent();
});

it('applies the lease minimum to a part payment', function () {
    $this->lease->forceFill(['partial_minimum_amount' => '200.00'])->save();
    Http::fake();

    $this->postJson('/portal/pay', payPayload(['amount' => '50.00']))
        ->assertStatus(422)
        ->assertJsonPath('errors.amount.0', fn (string $m) => str_contains($m, '$200.00'));
});

it('always allows paying the whole balance, whatever the policy says', function () {
    $this->lease->forceFill([
        'partial_payment_policy' => 'full_only',
        'partial_minimum_amount' => '400.00',
    ])->save();
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    // No policy exists to stop someone clearing their account.
    $this->postJson('/portal/pay', payPayload(['amount' => '500.00']))->assertOk();
});

it('rate limits to five attempts an hour per tenant', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/portal/pay', payPayload())->assertOk();
    }

    $this->postJson('/portal/pay', payPayload())
        ->assertStatus(429)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'call the office'));
});

it('I-9 returns 404, not 403, for another tenant’s lease', function () {
    $other = Tenant::factory()->create();
    $otherLease = new Lease;
    $otherLease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $other->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => '900.00', 'tenant_portion' => '900.00', 'ha_portion' => '0.00',
        'rent_due_day' => 1, 'grace_period_days' => 5, 'status' => 'active',
    ])->save();

    // A 403 would confirm the lease exists.
    $this->postJson('/portal/pay', payPayload(['lease_id' => $otherLease->id]))->assertNotFound();
});

it('keeps an admin out of the tenant payment flow', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get('/portal/pay')->assertForbidden();
});

/*
 |--------------------------------------------------------------------------
 | Coming back
 |--------------------------------------------------------------------------
 */

it('records the transaction id when the gateway hands one back', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);
    $this->postJson('/portal/pay', payPayload());

    $this->get('/portal/pay/confirm?transId=60123456789')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Portal/PayResult')->where('state', 'submitted'));

    expect(Payment::sole()->gateway_transaction_id)->toBe('60123456789')
        // Still pending. Only settlement clears it (I-6).
        ->and(Payment::sole()->status)->toBe('pending')
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('UI §7 creates no second payment when the confirm page is revisited', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);
    $this->postJson('/portal/pay', payPayload());

    $this->get('/portal/pay/confirm?transId=60123456789');
    $this->get('/portal/pay/confirm?transId=60123456789');
    $this->get('/portal/pay/confirm');

    expect(Payment::count())->toBe(1)
        ->and(LedgerEntry::where('type', 'payment')->count())->toBe(1);
});

it('voids the payment when the tenant cancels at the gateway', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);
    $this->postJson('/portal/pay', payPayload());

    $this->get('/portal/pay/confirm?cancelled=1')
        ->assertInertia(fn ($page) => $page->where('state', 'cancelled'));

    expect(Payment::sole()->status)->toBe('void')
        ->and(LedgerEntry::where('type', 'payment')->sole()->status)->toBe('void')
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('says nothing is in progress rather than alarming someone who bookmarked the page', function () {
    $this->get('/portal/pay/confirm')
        ->assertInertia(fn ($page) => $page->where('state', 'unknown'));
});

/*
 |--------------------------------------------------------------------------
 | Saved methods and abandonment
 |--------------------------------------------------------------------------
 */

it('AC-PAY-06 stores only a profile id and a masked descriptor', function () {
    Http::fake([
        // The call order is the design: the intent looks up the tenant's CIM
        // profile first so the hosted page can offer it, then asks for a form.
        // On return the sync repeats the lookup and reads the profile itself.
        'apitest.authorize.net/*' => Http::sequence()
            ->push(anetBody(['profile' => ['customerProfileId' => '5001']]), 200)
            ->push(anetBody(['token' => 'tok']), 200)
            ->push(anetBody(['profile' => ['customerProfileId' => '5001']]), 200)
            ->push(anetBody(['profile' => [
                'customerProfileId' => '5001',
                'paymentProfiles' => [[
                    'customerPaymentProfileId' => '9001',
                    'payment' => ['bankAccount' => ['accountType' => 'checking', 'accountNumber' => 'XXXX6789']],
                ]],
            ]]), 200),
    ]);

    $this->postJson('/portal/pay', payPayload());
    $this->get('/portal/pay/confirm?transId=60123456789');

    $profile = DB::table('payment_profiles')->sole();

    expect($profile->gateway_payment_profile_id)->toBe('9001')
        ->and($profile->descriptor)->toBe('Checking ••••6789')
        // I-5: there is no column that could hold more, and nothing tried to.
        ->and(collect((array) $profile)->keys()->all())
        ->not->toContain('account_number', 'routing_number');
});

it('voids a payment the tenant started a day ago and never finished', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);
    $this->postJson('/portal/pay', payPayload());

    Payment::sole()->forceFill(['submitted_at' => now()->subHours(30)])->save();

    app(CleanupAbandonedPayments::class)->handle(app(App\Domain\Payments\PaymentIntentService::class));

    expect(Payment::sole()->status)->toBe('void')
        ->and(LedgerEntry::where('type', 'payment')->sole()->status)->toBe('void');
});

it('leaves an old payment alone once the gateway knows about it', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);
    $this->postJson('/portal/pay', payPayload());

    Payment::sole()->forceFill([
        'submitted_at' => now()->subDays(4),
        'gateway_transaction_id' => '60123456789',
    ])->save();

    app(CleanupAbandonedPayments::class)->handle(app(App\Domain\Payments\PaymentIntentService::class));

    // ACH over a bank holiday weekend outlasts any naive cutoff. A transaction
    // the gateway has heard of belongs to reconciliation, whatever its age.
    expect(Payment::sole()->status)->toBe('pending');
});

it('I-4 never sends the housing authority figure to the pay screen', function () {
    $props = null;

    $this->get('/portal/pay')->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    $encoded = json_encode($props);

    expect($encoded)->not->toContain('700.00')
        ->not->toContain('housing_authority')
        ->and($props['balance'])->toBe('500.00');
});
