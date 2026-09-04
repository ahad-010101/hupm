<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Domain\Payments\AuthorizeNetGateway;
use App\Domain\Payments\PaymentIntentService;
use App\Exceptions\GatewayUnavailableException;
use App\Jobs\CleanupAbandonedPayments;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use App\Support\Settings;
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

    $this->unsettled = [];
    $this->gatewayDown = false;

    $this->actingAs($this->user);
});

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

it('offers only bank fields when the tenant chose a bank transfer', function () {
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

        // [WP-39] One instrument per hand-off. The method was chosen on our
        // page, before any fee was disclosed, so a card field appearing here
        // would let a tenant pay by a method we did not price.
        return $decoded['showCreditCard'] === false && $decoded['showBankAccount'] === true;
    });
});

/*
 |--------------------------------------------------------------------------
 | When it goes wrong
 |--------------------------------------------------------------------------
 */

it('does not tell a tenant there is nothing more to do about a payment nobody submitted', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'hosted-token-abc']))]);

    // Opened the hosted form and closed the tab. Authorize.Net issues a
    // transaction id only on submit, so its absence is what tells us this
    // money is not in flight with anyone's bank.
    $this->postJson('/portal/pay', payPayload())->assertOk();

    expect(Payment::sole()->gateway_transaction_id)->toBeNull();

    $this->get('/portal')->assertInertia(fn ($page) => $page
        // Still counted as pending, so nobody is invited to pay twice (I-6)...
        ->where('balances.pending', '500.00')
        // ...but flagged as unconfirmed, so the wording can differ.
        ->where('balances.unconfirmed', '500.00'));
});

it('calls a payment the gateway has heard of what it is: processing', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'hosted-token-abc']))]);

    $this->postJson('/portal/pay', payPayload())->assertOk();
    Payment::sole()->forceFill(['gateway_transaction_id' => '60000123'])->save();

    $this->get('/portal')->assertInertia(fn ($page) => $page
        ->where('balances.pending', '500.00')
        ->where('balances.unconfirmed', '0.00'));
});

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

it('writes the reason to the log even though the tenant is told something softer', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response('', 503)]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return str_contains($message, 'could not start a payment')
                && array_key_exists('lease_id', $context)
                && array_key_exists('return_url', $context);
        });

    $this->postJson('/portal/pay', payPayload())->assertStatus(502);

    // A 502 with an empty log is undiagnosable, and on shared hosting "it just
    // does not work" is the whole bug report. The tenant-facing message stays
    // soft; the reason has to be written down somewhere.
});

it('does not tell a tenant to try again when the gateway calls it a duplicate', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody([
        'messages' => [
            'resultCode' => 'Error',
            'message' => [['code' => '11', 'text' => 'A duplicate transaction has been submitted.']],
        ],
    ]))]);

    $message = $this->postJson('/portal/pay', payPayload())->assertStatus(502)->json('message');

    // The first attempt may well have gone through. "Nothing has been charged,
    // try again shortly" is the one sentence that turns this into paying the
    // rent twice (AC-PAY-02, I-6).
    expect($message)->toContain('just been submitted')
        ->and($message)->toContain('check your account')
        ->and($message)->not->toContain('Nothing has been charged');
});

/*
 |--------------------------------------------------------------------------
 | Coming back from the hosted page
 |--------------------------------------------------------------------------
 |
 | Accept Hosted's redirect mode returns **no transaction data** — its own
 | documentation says the continue button "sends an HTTP GET request to that
 | URL", and `transId` comes back only through the iframe communicator, which a
 | redirect integration does not have.
 |
 | So the resident always returns empty-handed, and guessing is wrong in a way
 | that costs money in both directions: told it worked when it did not, they do
 | not pay; told it failed when it worked, they pay twice. The confirm page asks
 | the gateway instead.
 |
 */

/**
 * One request-aware fake, registered once, reading a mutable property.
 *
 * `Http::fake()` **merges** rather than replaces — calling it a second time
 * leaves the first stub in front, so a later stub never runs and the unsettled
 * lookup silently receives the hosted-token body instead. The reconciliation
 * suite documents the same trap; a closure over a property the test can change
 * mid-run is the way round it.
 */
function fakeHostedFlow(): void
{
    Http::fake(function ($request) {
        if (test()->gatewayDown) {
            return Http::response('', 503);
        }

        $name = (string) array_key_first($request->data());

        if ($name === 'getUnsettledTransactionListRequest') {
            return Http::response(anetBody(['transactions' => test()->unsettled]));
        }

        return Http::response(anetBody(['token' => 'hosted-token-abc']));
    });
}

/** One unsettled transaction, as the gateway returns it. */
function unsettled(int $paymentId, string $status = 'capturedPendingSettlement', string $transId = '60000456'): array
{
    return [[
        'transId' => $transId,
        'transactionStatus' => $status,
        'invoiceNumber' => (string) $paymentId,
        'settleAmount' => '500.00',
    ]];
}

it('confirms a real payment by asking the gateway, since the redirect hands back nothing', function () {
    fakeHostedFlow();
    $this->postJson('/portal/pay', payPayload())->assertOk();

    $this->unsettled = unsettled(Payment::sole()->id);

    $this->get('/portal/pay/confirm')->assertInertia(fn ($page) => $page
        ->component('Portal/PayResult')
        ->where('state', 'submitted'));

    // The id is filed, so reconciliation can match on it rather than falling
    // back to the invoice number.
    expect(Payment::sole()->gateway_transaction_id)->toBe('60000456');
});

it('says the bank refused it when the bank refused it', function () {
    fakeHostedFlow();
    $this->postJson('/portal/pay', payPayload())->assertOk();

    $this->unsettled = unsettled(Payment::sole()->id, 'declined');

    // A definite answer, and a different one from "we do not know". Telling
    // this resident their payment is on its way is how they find out otherwise
    // a fortnight later, in arrears.
    $this->get('/portal/pay/confirm')->assertInertia(fn ($page) => $page->where('state', 'declined'));
});

it('admits it does not know when the gateway has never heard of the payment', function () {
    fakeHostedFlow();
    $this->postJson('/portal/pay', payPayload())->assertOk();

    // Nothing matching: the form was abandoned, or refused outright.
    $this->unsettled = [];

    $this->get('/portal/pay/confirm')->assertInertia(fn ($page) => $page->where('state', 'unconfirmed'));

    expect(Payment::sole()->gateway_transaction_id)->toBeNull();
});

it('admits it does not know when it cannot reach the gateway at all', function () {
    fakeHostedFlow();
    $this->postJson('/portal/pay', payPayload())->assertOk();

    $this->gatewayDown = true;

    // Honest rather than optimistic. Reconciliation settles it within a day
    // regardless (R-6); what this must never do is guess.
    $this->get('/portal/pay/confirm')->assertInertia(fn ($page) => $page->where('state', 'unconfirmed'));
});

it('takes the transaction id from the query string when an iframe integration supplies one', function () {
    fakeHostedFlow();
    $this->postJson('/portal/pay', payPayload())->assertOk();

    $this->get('/portal/pay/confirm?transId=60000999')
        ->assertInertia(fn ($page) => $page->where('state', 'submitted'));

    expect(Payment::sole()->gateway_transaction_id)->toBe('60000999');

    // An id in hand means there is nothing to look up. (Other calls do go out
    // on this path — the saved-method sync — so it is this one request that
    // has to be absent, not all of them.)
    Http::assertNotSent(fn ($request) => array_key_first($request->data()) === 'getUnsettledTransactionListRequest');
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
                'text' => 'The value &#39;sandbox-login&#39; is invalid. Key sandbox-key rejected.',
            ]],
        ],
    ]))]);

    $this->postJson('/portal/pay', payPayload())->assertStatus(502);

    Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
        // A failed payment writes more than one warning — the gateway's
        // rejection and the controller's "a tenant could not start a payment".
        // Only the first carries the echoed text, so skip the rest rather than
        // reading a key they do not have.
        if (! isset($context['text'])) {
            return false;
        }

        return ! str_contains($context['text'], 'sandbox-login')
            && ! str_contains($context['text'], 'sandbox-key')
            && str_contains($context['text'], '[redacted]')
            // The code survives, because that is what makes the line useful.
            && $context['code'] === 'E00003';
    });
});

it('names the localhost return URL instead of blaming the provider', function () {
    // Authorize.Net rejects the hostname `localhost` with "must begin with
    // http:// or https://" — a message about something else entirely, which
    // sends you looking in the wrong place. Found against the live sandbox; no
    // fixture would have said a word.
    Http::fake();

    $call = fn () => app(PaymentIntentService::class)->create(
        lease: $this->lease,
        amount: Money::fromString('400.00'),
        idempotencyKey: (string) Str::uuid(),
        returnUrl: 'http://localhost:8000/portal/pay/confirm',
        cancelUrl: 'http://localhost:8000/portal/pay/confirm?cancelled=1',
    );

    expect($call)->toThrow(GatewayUnavailableException::class);

    // Nothing sent and nothing written. This is our configuration, not an
    // outage, so there is no failed attempt worth recording (F1 is about the
    // provider being down, which is a different fact).
    Http::assertNothingSent();
    expect(Payment::count())->toBe(0)
        ->and(LedgerEntry::where('type', 'payment')->count())->toBe(0);
});

it('accepts 127.0.0.1 and a real domain, which is what the gateway allows', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);
    $gateway = app(AuthorizeNetGateway::class);

    foreach (['http://127.0.0.1:8000/portal/pay/confirm', 'https://hupm.example.com/portal/pay/confirm'] as $url) {
        $gateway->assertUsableReturnUrl($url);
    }

    expect(true)->toBeTrue();
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

it('AC-PAY-06 rate limits to five attempts an hour per tenant', function () {
    /*
     | Five, per TDD 6.2. This test said fifteen and passed, because the
     | limiter said fifteen -- underneath a comment that said five. Corrected
     | by the WP-34 security review [2026-08-27].
     |
     | The number now comes from the constant rather than being typed here
     | twice. A limit test that hard-codes the figure it is checking against
     | agrees with whatever the code does, which is how this one certified the
     | wrong answer for weeks.
    */
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    $allowed = AppServiceProvider::PAYMENT_ATTEMPTS_PER_HOUR;

    expect($allowed)->toBe(5, 'TDD 6.2 says five payment submissions an hour.');

    for ($i = 0; $i < $allowed; $i++) {
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

    app(CleanupAbandonedPayments::class)->handle(app(PaymentIntentService::class));

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

    app(CleanupAbandonedPayments::class)->handle(app(PaymentIntentService::class));

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

/*
 |--------------------------------------------------------------------------
 | Card payments  [WP-39, Q-7 closed 2026-09-04]
 |--------------------------------------------------------------------------
 |
 | Cards ride the same Accept Hosted page as eCheck, so I-5 and I-6 are
 | untouched. What is new is the convenience fee, and the arithmetic that has
 | to net to zero (D-28).
 |
 */

it('AC-PAY-16 refuses a card payment while cards are switched off', function () {
    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    // Default is off. The radio would be hidden, but AC-DEL-04's reasoning
    // applies: a tenant can construct the request by hand.
    $this->postJson('/portal/pay', payPayload(['method' => 'card']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('method');

    expect(Payment::count())->toBe(0)
        // Nothing written means nothing to undo (AC-PAY-04).
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('AC-PAY-17 charges the gateway the rent plus the fee, and records both', function () {
    app(Settings::class)->set('payments.cards_enabled', 'true');
    app(Settings::class)->set('payments.card_convenience_fee', '4.95');

    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    $this->postJson('/portal/pay', payPayload(['amount' => '345.98', 'method' => 'card']))
        ->assertOk();

    $payment = Payment::sole();

    // [D-28] `amount` is what the gateway takes, not the rent portion.
    expect($payment->amount->toDecimalString())->toBe('350.93')
        ->and($payment->fee()->toDecimalString())->toBe('4.95')
        ->and($payment->rentPortion()->toDecimalString())->toBe('345.98')
        ->and($payment->method)->toBe('card')
        // I-6 still: submitting is not paying, whatever the instrument.
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('AC-PAY-17 asks the gateway for card fields and no bank fields', function () {
    app(Settings::class)->set('payments.cards_enabled', 'true');

    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    $this->postJson('/portal/pay', payPayload(['method' => 'card']));

    Http::assertSent(function ($request) {
        $settings = collect($request->data()['getHostedPaymentPageRequest']['hostedPaymentSettings']['setting'] ?? [])
            ->firstWhere('settingName', 'hostedPaymentPaymentOptions');

        if (! $settings) {
            return false;
        }

        $decoded = json_decode($settings['settingValue'], true);

        return $decoded['showCreditCard'] === true
            && $decoded['showBankAccount'] === false
            // Never stored and never seen by us, but it is the cheapest
            // defence against the fraud that becomes a chargeback later.
            && $decoded['cardCodeRequired'] === true;
    });
});

it('AC-PAY-18 charges no fee on a bank transfer, however the fee is set', function () {
    app(Settings::class)->set('payments.cards_enabled', 'true');
    app(Settings::class)->set('payments.card_convenience_fee', '4.95');

    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    $this->postJson('/portal/pay', payPayload(['amount' => '345.98']))->assertOk();

    $payment = Payment::sole();

    expect($payment->amount->toDecimalString())->toBe('345.98')
        // NULL, not 0.00 — "no fee" and "predates fees" read alike on purpose.
        ->and($payment->convenience_fee)->toBeNull()
        ->and($payment->method)->toBe('echeck');
});

it('AC-PAY-19 evaluates the lease policy on the rent, not on the rent plus fee', function () {
    app(Settings::class)->set('payments.cards_enabled', 'true');
    app(Settings::class)->set('payments.card_convenience_fee', '4.95');

    // full_only: the tenant must pay the whole $500 balance and nothing else
    // will do. With the fee added the gateway takes $504.95, and if the policy
    // saw that number it would reject a payment that is exactly correct.
    $this->lease->forceFill(['partial_payment_policy' => 'full_only'])->save();

    Http::fake(['apitest.authorize.net/*' => Http::response(anetBody(['token' => 'tok']))]);

    $this->postJson('/portal/pay', payPayload(['amount' => '500.00', 'method' => 'card']))
        ->assertOk();

    expect(Payment::sole()->amount->toDecimalString())->toBe('504.95');
});
