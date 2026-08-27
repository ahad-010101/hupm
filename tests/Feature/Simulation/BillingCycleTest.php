<?php

use App\Domain\Arrangements\ArrangementService;
use App\Domain\Delinquency\DelinquencyService;
use App\Domain\Fees\LateFeeService;
use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Domain\Payments\PaymentRecordingService;
use App\Domain\Payments\ReconciliationService;
use App\Domain\Reporting\RentRollReport;
use App\Jobs\PostScheduledCharges;
use App\Models\HousingAuthority;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A whole month, end to end  [WP-33, PRD §7.4]
|--------------------------------------------------------------------------
|
| Every other test in this suite proves one rule in isolation. This one proves
| they compose — that a real month, with a part payment, a late fee, an account
| falling into Management Review, a cheque taken over the counter, a bank return
| a fortnight later, and an arrangement signed to clear the rest, leaves a ledger
| that still adds up and a rent roll that still agrees with it.
|
| That is a different claim from "each rule works", and it is the one that
| matters before real money goes through. The failures this catches are the ones
| that live between packages: a fee posted against the wrong payer, a reversal
| that restores the balance but not the charge, a report that totals a column the
| ledger no longer agrees with.
|
| **Scripted and repeatable, not a click-through** (WP-33 DoD). It runs in CI on
| every change, which is the part a manual rehearsal on staging can never be.
|
| The arithmetic is written out at each step rather than accumulated in a
| variable. When this breaks, the failure should say which step disagreed and by
| how much — not merely that a running total drifted somewhere.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // Deterministic clock. Every date below is relative to this, so the month
    // reads the same on a Tuesday in March as it does on the 31st.
    $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00', 'America/New_York'));

    $this->settings = app(Settings::class);
    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);
    $this->payments = app(PaymentRecordingService::class);
    $this->fees = app(LateFeeService::class);
    $this->delinquency = app(DelinquencyService::class);
    $this->arrangements = app(ArrangementService::class);

    // Fees ship disabled pending attorney review (WP-23). A simulated month has
    // to run with the policy switched on, or half the sequence never happens.
    $this->settings->set('fees.automation_enabled', 'true');
    $this->settings->set('delinquency.trigger_day', '5');

    $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Office']);

    $this->authority = HousingAuthority::factory()->create(['name' => 'Atlanta Housing']);

    $this->tenant = Tenant::factory()->create(['first_name' => 'Denise', 'last_name' => 'Okafor']);
    $this->signer = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $property = Property::factory()->create(['name' => 'Peachtree House']);

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create(['property_id' => $property->id])->id,
        'tenant_id' => $this->tenant->id,
        'housing_authority_id' => $this->authority->id,
        // Starts on the 1st of the month being simulated. The charge job posts
        // every period due since a lease began — correct catch-up behaviour
        // (I-8), and three months of rent is not the month under test.
        'start_date' => '2026-03-01',
        'end_date' => '2027-02-28',
        // The Section 8 shape this system exists for: the resident pays $500 of
        // a $1,200 rent and never sees the other $700 (I-4).
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '700.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'late_fee_flat' => '50.00',
        'late_fee_daily' => '0.00',
        'returned_payment_fee' => '35.00',
        'partial_payment_policy' => 'partial_allowed',
        'status' => 'active',
        'delinquency_state' => 'current',
    ])->save();

    $this->batches = [];
    $this->gatewayDown = false;

    Http::fake(function ($request) {
        $body = $request->data();
        $name = (string) array_key_first($body);

        if ($name === 'getSettledBatchListRequest') {
            return Http::response(anetBody([
                'batchList' => array_map(fn ($id) => ['batchId' => $id], array_keys(test()->batches)),
            ]));
        }

        if ($name === 'getTransactionListRequest') {
            return Http::response(anetBody([
                'transactions' => test()->batches[$body[$name]['batchId']] ?? [],
            ]));
        }

        return Http::response(anetBody([]));
    });
});

/** A submitted-but-not-settled eCheck, exactly as the portal leaves one. */
function submittedEcheck(string $amount, string $transactionId): Payment
{
    $payment = Payment::factory()->create([
        'lease_id' => test()->lease->id,
        'tenant_id' => test()->tenant->id,
        'amount' => $amount,
        'method' => 'echeck',
        'gateway' => 'authorize_net',
        'gateway_transaction_id' => $transactionId,
        'idempotency_key' => (string) Str::uuid(),
        'submitted_at' => now(),
    ]);

    // I-6: submitting posts a `pending` row that moves no balance.
    test()->ledger->postPayment(
        test()->lease, 'tenant', Money::fromString($amount),
        'Payment submitted online', $payment->id, 'pending',
    );

    return $payment;
}

/** The resident's balance, as a plain string. */
function owed(): string
{
    return test()->balances->tenantBalance(test()->tenant->id)->toDecimalString();
}

/** What the authority owes, which the resident never sees. */
function authorityOwed(): string
{
    return test()->balances->haBalance(test()->tenant->id)->toDecimalString();
}

it('WP-33 runs a whole month and leaves a ledger that still adds up', function () {
    /*
     |----------------------------------------------------------------------
     | 1 March — rent is charged, to both payers
     |----------------------------------------------------------------------
     */
    app()->call([new PostScheduledCharges, 'handle']);

    expect(owed())->toBe('500.00', 'The resident is charged their portion and nothing else.');
    expect(authorityOwed())->toBe('700.00', 'The authority is charged separately.');

    // I-4 holds at the source: two rows, two payers, never one combined figure.
    expect(LedgerEntry::where('type', 'charge')->where('category', 'rent')->count())->toBe(2);

    /*
     |----------------------------------------------------------------------
     | 3 March — the resident pays part of it online
     |----------------------------------------------------------------------
     */
    $this->travelTo(CarbonImmutable::parse('2026-03-03 10:00:00', 'America/New_York'));

    $echeck = submittedEcheck('200.00', '60000000001');

    expect(owed())->toBe('500.00', 'Submitting moves nothing. Only settlement does (I-6).');

    /*
     |----------------------------------------------------------------------
     | 6 March — the bank settles it, reconciliation applies it
     |----------------------------------------------------------------------
     */
    $this->travelTo(CarbonImmutable::parse('2026-03-06 06:00:00', 'America/New_York'));

    $this->batches = ['1001' => [[
        'transId' => '60000000001',
        'transactionStatus' => 'settledSuccessfully',
        'invoiceNumber' => (string) $echeck->id,
        'settleAmount' => '200.00',
    ]]];

    $settled = app(ReconciliationService::class)->run();

    expect($settled['settled'])->toBe(1)
        ->and($echeck->fresh()->status)->toBe(Payment::STATUS_SETTLED);

    expect(owed())->toBe('300.00', '500 charged, 200 cleared.');

    /*
     |----------------------------------------------------------------------
     | 6 March — the authority's cheque arrives, covering many residents
     |----------------------------------------------------------------------
     */
    $this->payments->recordRemittance(
        authority: $this->authority,
        total: Money::fromString('700.00'),
        receivedOn: CarbonImmutable::parse('2026-03-06', 'America/New_York'),
        lines: [['lease_id' => $this->lease->id, 'amount' => '700.00']],
        batchKey: 'HA-2026-03',
        reference: '884120',
    );

    expect(authorityOwed())->toBe('0.00', 'The authority side is square.');
    expect(owed())->toBe('300.00', 'And it changed nothing on the resident side.');

    /*
     |----------------------------------------------------------------------
     | 7 March — grace has expired, so a late fee is due
     |----------------------------------------------------------------------
     */
    $this->travelTo(CarbonImmutable::parse('2026-03-07 02:00:00', 'America/New_York'));

    $posted = $this->fees->postFor($this->lease->fresh());

    expect($posted)->toBe(1);
    expect(owed())->toBe('350.00', '300 outstanding plus a 50 fee.');

    // I-7, and the reason this system exists: the authority is not late. It pays
    // on its own schedule, and a fee against its share would surface in a
    // dispute as a charge nobody could justify.
    expect(authorityOwed())->toBe('0.00');
    expect(LedgerEntry::where('category', 'late_fee')->where('payer', 'housing_authority')->count())
        ->toBe(0, 'A late fee was charged to the housing authority.');

    /*
     |----------------------------------------------------------------------
     | 7 March — day 5 has passed, so the account enters Management Review
     |----------------------------------------------------------------------
     */
    expect($this->delinquency->shouldEnterReview($this->lease->fresh()))->toBeTrue();

    $this->delinquency->enterReview($this->lease->fresh(), 'Day 5 past due');

    expect($this->lease->fresh()->delinquency_state)->toBe('management_review');

    // BR-12 / I-12: online payment is off, autopay is off, and the office can
    // still take money at the counter. The system never refuses all payment.
    expect($this->delinquency->autopayPermitted($this->lease->fresh()))->toBeFalse();

    /*
     |----------------------------------------------------------------------
     | 10 March — a cheque, over the counter, during Management Review
     |----------------------------------------------------------------------
     */
    $this->travelTo(CarbonImmutable::parse('2026-03-10 11:00:00', 'America/New_York'));

    $this->payments->record(
        lease: $this->lease->fresh(),
        payer: 'tenant',
        amount: Money::fromString('150.00'),
        receivedOn: CarbonImmutable::parse('2026-03-10', 'America/New_York'),
        method: 'cheque',
        idempotencyKey: (string) Str::uuid(),
        reference: '10412',
    );

    // I-12. An account in review is one the office most needs to be able to
    // take money for.
    expect(owed())->toBe('200.00', '350 less a 150 cheque.');
    expect($this->lease->fresh()->delinquency_state)->toBe('management_review');

    /*
     |----------------------------------------------------------------------
     | 18 March — the bank returns the March eCheck, R01
     |----------------------------------------------------------------------
     */
    $this->travelTo(CarbonImmutable::parse('2026-03-18 06:00:00', 'America/New_York'));

    $this->batches = ['1002' => [[
        'transId' => '60000000001',
        'transactionStatus' => 'returnedItem',
        'invoiceNumber' => (string) $echeck->id,
        'settleAmount' => '200.00',
        'returnedItems' => [['code' => 'R01', 'description' => 'Insufficient funds']],
    ]]];

    $returned = app(ReconciliationService::class)->run();

    expect($returned['returned'])->toBe(1)
        ->and($echeck->fresh()->status)->toBe(Payment::STATUS_RETURNED)
        ->and($echeck->fresh()->return_code)->toBe('R01');

    // The 200 comes back and a 35 fee lands with it.
    expect(owed())->toBe('435.00', '200 restored plus a 35 returned-payment fee.');

    /*
     | **[D-22] There is deliberately no reversal row here**, and that is worth
     | stating because its absence looks like a bug.
     |
     | `returned` is not a balance-affecting status, so the moment the payment
     | entry flips to it the money stops counting and the balance is already
     | back where it was. FR-PAY-04 also says to post a reversal; doing both
     | would restore the same 200 twice. The `reversal` type exists for an
     | admin correcting a charge, which is a different act.
     |
     | I-3 still holds: the row was not edited beyond its status and nothing
     | was deleted. The original payment entry is still there, still 200,
     | still readable a year from now.
    */
    $entry = LedgerEntry::where('payment_id', $echeck->id)->where('type', 'payment')->sole();

    expect($entry->status)->toBe('returned')
        ->and($entry->amount->toDecimalString())->toBe('-200.00');

    expect(LedgerEntry::where('category', 'returned_fee')->count())->toBe(1);
    expect(LedgerEntry::where('type', 'reversal')->count())
        ->toBe(0, 'A reversal was posted as well as the status change; the balance is restored twice.');

    /*
     |----------------------------------------------------------------------
     | 20 March — an arrangement to clear the rest
     |----------------------------------------------------------------------
     */
    $this->travelTo(CarbonImmutable::parse('2026-03-20 14:00:00', 'America/New_York'));

    $arrangement = $this->arrangements->draft(
        lease: $this->lease->fresh(),
        // A down payment today, and the rest in two instalments. The service
        // refuses a schedule that does not add up to what is left after it —
        // an agreement whose numbers disagree is one both parties will read
        // differently later. 35 + 200 + 200 = the 435 outstanding.
        amountAccepted: Money::fromString('35.00'),
        schedule: [
            ['due_on' => '2026-04-01', 'amount' => '200.00'],
            ['due_on' => '2026-05-01', 'amount' => '200.00'],
        ],
        lateFeesContinue: false,
        defaultTerms: 'Two instalments. Missing either ends the arrangement.',
        actor: $this->admin,
    );

    $this->arrangements->approve($arrangement->fresh(), $this->admin);

    // BR-19: approving generates the agreement and sends it to be signed.
    expect($arrangement->fresh()->status)->toBe('pending_signature');

    /*
     |----------------------------------------------------------------------
     | 21 March — the office releases the review, with a reason
     |----------------------------------------------------------------------
     */
    $this->travelTo(CarbonImmutable::parse('2026-03-21 09:00:00', 'America/New_York'));

    // AC-DEL-05 / BR-14: leaving is never automatic and never reasonless, even
    // when an arrangement is in place.
    $this->delinquency->release($this->lease->fresh(), 'Arrangement agreed and signed', $this->admin);

    expect($this->lease->fresh()->delinquency_state)->toBe('current');

    /*
     |----------------------------------------------------------------------
     | Month end — does any of it still add up?
     |----------------------------------------------------------------------
     */

    // The ledger, from first principles: every signed row this month, summed.
    $fromRows = LedgerEntry::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('payer', 'tenant')
        ->whereIn('status', ['posted', 'cleared'])
        ->sum(DB::raw('amount'));

    expect(Money::fromString((string) $fromRows)->toDecimalString())
        ->toBe(owed(), 'The balance on screen disagrees with the rows behind it.');

    // And against the arithmetic written out longhand: 500 charged, 50 late fee,
    // 35 returned fee, less a 150 cheque. The 200 eCheck settled and came back,
    // so it nets to nothing.
    expect(owed())->toBe('435.00');

    // I-1: no column stores this. If one ever does, these two stop agreeing.
    expect(DB::getSchemaBuilder()->hasColumn('leases', 'balance'))->toBeFalse();
});

it('WP-33 AC-ADM-03 reconciles the rent roll to the ledger', function () {
    app()->call([new PostScheduledCharges, 'handle']);

    $report = app(RentRollReport::class)->build('2026-03');

    // The report totals a column; the ledger sums rows. They are computed by
    // different code from different queries, and a report that quietly disagrees
    // with the ledger is worse than no report — somebody acts on it.
    $charged = LedgerEntry::query()
        ->where('type', 'charge')
        ->where('category', 'rent')
        ->where('period', '2026-03')
        ->sum(DB::raw('amount'));

    expect($report->rows)->not->toBeEmpty('The rent roll produced no rows to reconcile.');

    $reportTotal = Money::fromString((string) ($report->totals['charged'] ?? '0'));

    expect($reportTotal->toDecimalString())
        ->toBe(Money::fromString((string) $charged)->toDecimalString());
});

it('WP-33 emails each resident-facing event once, and records every one', function () {
    app()->call([new PostScheduledCharges, 'handle']);

    $echeck = submittedEcheck('500.00', '60000000002');

    $this->batches = ['1001' => [[
        'transId' => '60000000002',
        'transactionStatus' => 'settledSuccessfully',
        'invoiceNumber' => (string) $echeck->id,
        'settleAmount' => '500.00',
    ]]];

    app(ReconciliationService::class)->run();

    // Running reconciliation again must not send a second receipt. A resident
    // emailed twice about one payment telephones to ask which one is real
    // (AC-PAY-12, I-8).
    app(ReconciliationService::class)->run();

    $receipts = DB::table('notification_logs')
        ->where('template', 'payment_receipt')
        ->count();

    expect($receipts)->toBe(1, 'The receipt was sent more than once.');

    // Every message is on the record whether or not it was deliverable — a
    // notification nobody can account for is the failure this log exists for.
    expect(DB::table('notification_logs')->whereNull('status')->count())->toBe(0);
});

it('WP-33 never puts the housing authority figure in front of the resident', function () {
    app()->call([new PostScheduledCharges, 'handle']);

    $this->actingAs($this->signer);

    // I-4, at the end of the month rather than the start: the portal is the one
    // surface where a leak would be seen by the person it must not reach.
    $html = $this->get('/portal')->assertOk()->getContent();

    expect($html)->not->toContain('700.00')
        ->and($html)->not->toContain('1200.00')
        ->and($html)->not->toContain('ha_portion');

    $ledger = $this->get('/portal/ledger')->assertOk()->getContent();

    expect($ledger)->not->toContain('700.00')
        ->and($ledger)->not->toContain('housing_authority');
});
