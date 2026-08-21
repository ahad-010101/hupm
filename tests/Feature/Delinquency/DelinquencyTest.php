<?php

use App\Domain\Delinquency\DelinquencyService;
use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Domain\Notifications\NotificationTemplate;
use App\Jobs\EvaluateDelinquency;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentProfile;
use App\Models\RecurringPayment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Management Review  [WP-24, FR-DEL-01/02/03, BR-10…BR-14]
|--------------------------------------------------------------------------
|
| The rule this package exists to honour is BR-12: the system must never
| permanently refuse all payment. Review switches off the tenant's own online
| payment and their autopay, and leaves admin-recorded payment working — at
| all times, deliberately. That is what resolves contradiction C2.
|
| Entering is automatic. Leaving is not, and never is, including when the
| balance reaches zero.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();

    $this->ledger = app(LedgerService::class);
    $this->delinquency = app(DelinquencyService::class);
    $this->settings = app(Settings::class);

    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->tenant = Tenant::factory()->create(['first_name' => 'Uriel', 'last_name' => 'Pouros']);
    $this->signer = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $this->lease = delinquentLease();
});

function delinquentLease(array $overrides = []): Lease
{
    $lease = new Lease;
    $lease->forceFill(array_merge([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => test()->tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '700.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
        'delinquency_state' => 'current',
    ], $overrides))->save();

    return $lease;
}

function oweRent(Lease $lease, string $amount = '500.00', string $payer = 'tenant', string $period = '2026-02'): void
{
    test()->ledger->postCharge(
        $lease, 'rent', $payer, Money::fromString($amount),
        'Rent — February 2026', "del:{$lease->id}:{$period}:{$payer}",
        CarbonImmutable::parse($period.'-01'), $period,
    );
}

function evaluateOn(Lease $lease, string $date): bool
{
    return test()->delinquency->shouldEnterReview($lease, CarbonImmutable::parse($date));
}

function runJobOn(string $date): int
{
    // Midday UTC, not the 02:30 the scheduler uses. The job runs at 02:30 in
    // GEORGIA, which is 07:30 UTC — freezing the clock at 02:30 UTC puts
    // BusinessCalendar::today() on the previous day and the job correctly does
    // nothing. The same five hours that stopped WP-10 posting anything at all.
    CarbonImmutable::setTestNow($date.' 12:00:00');
    Carbon::setTestNow($date.' 12:00:00');

    $count = app(EvaluateDelinquency::class)->handle(
        app(DelinquencyService::class),
        app(BusinessCalendar::class),
        app(AuditLogger::class),
    );

    CarbonImmutable::setTestNow();
    Carbon::setTestNow();

    return $count;
}

function autopayFor(Lease $lease): RecurringPayment
{
    $profile = new PaymentProfile;
    $profile->forceFill([
        'tenant_id' => $lease->tenant_id,
        'gateway_customer_profile_id' => '5001',
        'gateway_payment_profile_id' => '9001',
        'descriptor' => 'Checking ••••6789',
        'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ])->save();

    $recurring = new RecurringPayment;
    $recurring->forceFill([
        'lease_id' => $lease->id,
        'payment_profile_id' => $profile->id,
        'day_of_month' => 1,
        'status' => RecurringPayment::STATUS_ACTIVE,
        'created_at' => now(), 'updated_at' => now(),
    ])->save();

    return $recurring;
}

/*
 |--------------------------------------------------------------------------
 | Entering
 |--------------------------------------------------------------------------
 */

it('AC-DEL-01 enters review on day 5 with a balance, and records the transition', function () {
    oweRent($this->lease);

    expect(evaluateOn($this->lease, '2026-02-05'))->toBeFalse()
        ->and(evaluateOn($this->lease, '2026-02-06'))->toBeTrue();

    expect(runJobOn('2026-02-06'))->toBe(1);

    $lease = $this->lease->fresh();
    $event = DB::table('delinquency_events')->sole();

    expect($lease->delinquency_state)->toBe('management_review')
        ->and($lease->delinquency_since)->not->toBeNull()
        ->and($event->from_state)->toBe('current')
        ->and($event->to_state)->toBe('management_review')
        // A snapshot, not a live figure: what the balance was when this
        // happened is the question a dispute asks.
        ->and($event->balance_at_event)->toBe('500.00')
        // NULL actor means the nightly job did it, which is a meaningful thing
        // to be able to tell a tenant.
        ->and($event->actor_user_id)->toBeNull()
        ->and($event->reason)->toContain('day 5');
});

it('leaves an account alone while it owes nothing', function () {
    // No charges at all.
    expect(evaluateOn($this->lease, '2026-02-28'))->toBeFalse();

    oweRent($this->lease);
    $entry = $this->ledger->postPayment(
        $this->lease, 'tenant', Money::fromString('500.00'), 'Paid', null, 'cleared',
    );

    expect(evaluateOn($this->lease->fresh(), '2026-02-28'))->toBeFalse();
});

it('never looks at the housing authority balance', function () {
    // Only the authority owes. The resident is square.
    oweRent($this->lease, '700.00', 'housing_authority');

    // BR-10 is about the tenant's account. The authority pays on its own
    // schedule under the HAP contract and is not "delinquent".
    expect(evaluateOn($this->lease, '2026-02-28'))->toBeFalse();
});

it('GATE Q-6 honours a different trigger day without a code change', function () {
    $this->settings->set('delinquency.trigger_day', 10);
    $this->settings->flush();

    oweRent($this->lease);

    expect(evaluateOn($this->lease, '2026-02-06'))->toBeFalse()
        ->and(evaluateOn($this->lease, '2026-02-11'))->toBeTrue()
        ->and($this->delinquency->triggerDay())->toBe(10);
});

it('does not re-enter or re-notify an account already under review', function () {
    oweRent($this->lease);
    runJobOn('2026-02-06');
    runJobOn('2026-02-07');
    runJobOn('2026-02-20');

    expect(DB::table('delinquency_events')->count())->toBe(1)
        ->and(DB::table('notification_logs')
            ->where('template', NotificationTemplate::ManagementReview->value)->count())->toBe(1);
});

it('leaves an ended lease alone', function () {
    oweRent($this->lease);
    $this->lease->forceFill(['status' => 'ended'])->save();

    expect(evaluateOn($this->lease->fresh(), '2026-02-28'))->toBeFalse()
        ->and(runJobOn('2026-02-28'))->toBe(0);
});

it('tells the tenant what to do, neutrally, with a number to call', function () {
    $this->settings->set('company.phone', '(404) 555-0142');
    $this->settings->flush();

    oweRent($this->lease);
    runJobOn('2026-02-06');

    $log = DB::table('notification_logs')
        ->where('template', NotificationTemplate::ManagementReview->value)->sole();

    // UI §8: neutral and actionable. This message may be read back in a Georgia
    // dispossessory proceeding.
    expect($log->subject)->toBe('Please contact us about your account')
        ->and(strtolower($log->subject))->not->toContain('delinquent')
        ->not->toContain('failure');
});

/*
 |--------------------------------------------------------------------------
 | What review turns off, and what it must not
 |--------------------------------------------------------------------------
 */

it('BR-11 suspends autopay rather than cancelling it', function () {
    $autopay = autopayFor($this->lease);
    oweRent($this->lease);

    runJobOn('2026-02-06');

    // Suspended, not cancelled: the tenant did not choose this, and the two
    // must stay distinguishable.
    expect($autopay->fresh()->status)->toBe('suspended')
        ->and($autopay->fresh()->suspended_reason)->toContain('Management Review')
        ->and($this->delinquency->autopayPermitted($this->lease->fresh()))->toBeFalse();
});

it('AC-DEL-03 refuses an autopay debit while under review', function () {
    autopayFor($this->lease);
    oweRent($this->lease);
    runJobOn('2026-02-06');

    // The predicate WP-16's job will consult. It lives with delinquency so the
    // reason a debit is refused cannot drift from the state that causes it.
    expect($this->delinquency->autopayPermitted($this->lease->fresh()))->toBeFalse()
        ->and(RecurringPayment::active()->count())->toBe(0);
});

it('AC-DEL-04 refuses a tenant payment constructed directly by URL', function () {
    oweRent($this->lease);
    runJobOn('2026-02-06');

    // Refused in the service, not just hidden on the screen — AC-DEL-04 is
    // specifically about somebody building the request once the button is gone.
    $this->actingAs($this->signer)->postJson('/portal/pay', [
        'lease_id' => $this->lease->id,
        'amount' => '100.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertForbidden();

    expect(Payment::count())->toBe(0);
});

it('AC-DEL-02 keeps balance, ledger and documents open to the tenant', function () {
    oweRent($this->lease);
    runJobOn('2026-02-06');

    // BR-13. The account is under review, not switched off.
    $this->actingAs($this->signer)->get('/portal')->assertOk();
    $this->actingAs($this->signer)->get('/portal/ledger')->assertOk();
    $this->actingAs($this->signer)->get('/portal/documents')->assertOk();
    $this->actingAs($this->signer)->get('/portal/ledger/export')->assertOk();
});

it('AC-PAY-15 keeps admin-recorded payment working throughout', function () {
    oweRent($this->lease);
    runJobOn('2026-02-06');

    // I-12 / BR-12, and the whole of contradiction C2: the system must never
    // permanently refuse all payment.
    $this->actingAs($this->admin)->post('/admin/payments/record', [
        'lease_id' => $this->lease->id,
        'payer' => 'tenant',
        'amount' => '500.00',
        'received_on' => now()->toDateString(),
        'method' => 'cheque',
        'reference' => '10412',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    expect(app(BalanceCalculator::class)
        ->tenantBalance($this->tenant->id)->toDecimalString())->toBe('0.00');
});

/*
 |--------------------------------------------------------------------------
 | Leaving
 |--------------------------------------------------------------------------
 */

it('AC-DEL-05 refuses a release with no reason', function () {
    oweRent($this->lease);
    runJobOn('2026-02-06');

    $this->actingAs($this->admin)
        ->post("/admin/delinquency/{$this->lease->id}/release", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($this->lease->fresh()->delinquency_state)->toBe('management_review');
});

it('AC-DEL-06 does not resume autopay on release', function () {
    $autopay = autopayFor($this->lease);
    oweRent($this->lease);
    runJobOn('2026-02-06');

    $this->actingAs($this->admin)
        ->post("/admin/delinquency/{$this->lease->id}/release", [
            'reason' => 'Payment arrangement agreed over the phone.',
        ])->assertSessionHasNoErrors();

    expect($this->lease->fresh()->delinquency_state)->toBe('current')
        ->and($this->lease->fresh()->delinquency_since)->toBeNull()
        // Restarting a standing debit on somebody who has just been through
        // this, without them saying so, is how an account goes overdrawn.
        ->and($autopay->fresh()->status)->toBe('suspended');
});

it('BR-14 never releases automatically, not even on a zero balance', function () {
    oweRent($this->lease);
    runJobOn('2026-02-06');

    $entry = $this->ledger->postPayment(
        $this->lease, 'tenant', Money::fromString('500.00'), 'Paid in full', null, 'cleared',
    );

    runJobOn('2026-02-20');

    // A tenant who pays in full still gets a person looking at their account.
    // That is what makes it Management Review rather than an automatic gate.
    expect($this->lease->fresh()->delinquency_state)->toBe('management_review');
});

it('records who released the account and what the balance was', function () {
    oweRent($this->lease);
    runJobOn('2026-02-06');

    $this->actingAs($this->admin)->post("/admin/delinquency/{$this->lease->id}/release", [
        'reason' => 'Paid in full at the office.',
    ]);

    $release = DB::table('delinquency_events')->orderByDesc('id')->first();

    expect($release->from_state)->toBe('management_review')
        ->and($release->to_state)->toBe('current')
        ->and($release->actor_user_id)->toBe($this->admin->id)
        ->and($release->reason)->toBe('Paid in full at the office.')
        ->and(DB::table('audit_logs')->where('action', 'delinquency.review.released')->exists())->toBeTrue();
});

it('refuses to release an account that is not under review', function () {
    expect(fn () => $this->delinquency->release($this->lease, 'No reason to', $this->admin))
        ->toThrow(InvalidArgumentException::class, 'not under review');
});

it('lets an account re-enter review after a release', function () {
    oweRent($this->lease);
    runJobOn('2026-02-06');
    $this->delinquency->release($this->lease->fresh(), 'Arrangement agreed.', $this->admin);

    oweRent($this->lease, '500.00', 'tenant', '2026-03');
    runJobOn('2026-03-10');

    expect($this->lease->fresh()->delinquency_state)->toBe('management_review')
        ->and(DB::table('delinquency_events')->count())->toBe(3);
});

/*
 |--------------------------------------------------------------------------
 | The queue
 |--------------------------------------------------------------------------
 */

it('shows the queue with a number to call and the balance beside it', function () {
    $this->tenant->update(['phone' => '(404) 555-0199']);
    oweRent($this->lease);
    runJobOn('2026-02-06');

    $props = [];
    $this->actingAs($this->admin)->get('/admin/delinquency')->assertOk()
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    expect($props['accounts'])->toHaveCount(1)
        ->and($props['accounts'][0]['balance'])->toBe('500.00')
        ->and($props['accounts'][0]['phone'])->toBe('(404) 555-0199')
        ->and($props['accounts'][0]['history'])->toHaveCount(1)
        ->and($props['accounts'][0]['history'][0]['by'])->toBe('the system');
});

it('flags leases whose own grace outlasts the review trigger', function () {
    // Two clocks that were never reconciled: BR-10 sets a portfolio-wide day,
    // BR-07 puts grace on the lease. This resident loses online payment while
    // still inside the grace their lease grants them.
    $generous = delinquentLease([
        'unit_id' => Unit::factory()->create()->id,
        'grace_period_days' => 15,
    ]);

    $props = [];
    $this->actingAs($this->admin)->get('/admin/delinquency')
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    expect($props['insideGrace'])->toHaveCount(1)
        ->and($props['insideGrace'][0]['grace_days'])->toBe(15)
        ->and($props['triggerDay'])->toBe(5);
});

/*
 |--------------------------------------------------------------------------
 | Running the rule by hand
 |--------------------------------------------------------------------------
 |
 | The nightly job is the normal path. This exists for the morning after a cron
 | that did not fire — and it has to go through the job, not the service, so
 | the screen's "last evaluated" line is true afterwards. The reconciliation
 | button taught that lesson the expensive way.
 |
 */

it('lets an admin run the review by hand, and records the run', function () {
    oweRent($this->lease);
    $admin = User::factory()->create(['role' => 'admin']);

    // 06 Feb: the 1st plus a five-day trigger.
    CarbonImmutable::setTestNow('2026-02-06 12:00:00');
    Carbon::setTestNow('2026-02-06 12:00:00');

    $this->actingAs($admin)->get('/admin/delinquency')
        ->assertInertia(fn ($page) => $page
            ->where('waiting', 1)
            // Never having run is how you find out the scheduler is not
            // running at all.
            ->where('lastEvaluated', null));

    $this->actingAs($admin)->post('/admin/delinquency/evaluate')
        ->assertRedirect()
        ->assertSessionHas('status', fn (string $s) => str_contains($s, '1 account entered'));

    expect($this->lease->fresh()->delinquency_state)->toBe(DelinquencyService::STATE_REVIEW)
        ->and(DB::table('job_runs')->where('job_name', EvaluateDelinquency::class)
            ->where('status', 'success')->count())->toBe(1);

    $this->actingAs($admin)->get('/admin/delinquency')
        ->assertInertia(fn ($page) => $page->where('waiting', 0)->whereNot('lastEvaluated', null));

    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('says plainly when running it changes nothing', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    // Nobody owes anything, so the rule matches nobody. Silence here would
    // read as a broken button.
    $this->actingAs($admin)->post('/admin/delinquency/evaluate')
        ->assertSessionHas('status', fn (string $s) => str_contains($s, 'nothing changed'));

    expect($this->lease->fresh()->delinquency_state)->toBe(DelinquencyService::STATE_CURRENT);
});

it('moves nobody a second time when run twice', function () {
    oweRent($this->lease);
    $admin = User::factory()->create(['role' => 'admin']);

    CarbonImmutable::setTestNow('2026-02-06 12:00:00');
    Carbon::setTestNow('2026-02-06 12:00:00');

    $this->actingAs($admin)->post('/admin/delinquency/evaluate');
    $this->actingAs($admin)->post('/admin/delinquency/evaluate');

    expect(DB::table('delinquency_events')->count())->toBe(1);

    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('keeps a tenant out of the manual review trigger', function () {
    $resident = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $this->actingAs($resident)->post('/admin/delinquency/evaluate')->assertForbidden();
});

it('keeps a tenant out of the queue and the release action', function () {
    oweRent($this->lease);
    runJobOn('2026-02-06');

    $this->actingAs($this->signer)->get('/admin/delinquency')->assertForbidden();
    $this->actingAs($this->signer)
        ->post("/admin/delinquency/{$this->lease->id}/release", ['reason' => 'Let me out'])
        ->assertForbidden();

    expect($this->lease->fresh()->delinquency_state)->toBe('management_review');
});

it('evaluates the whole portfolio in one run', function () {
    oweRent($this->lease);

    $second = delinquentLease(['unit_id' => Unit::factory()->create()->id]);
    oweRent($second);

    expect(runJobOn('2026-02-06'))->toBe(2)
        ->and(DB::table('job_runs')->where('job_name', EvaluateDelinquency::class)->value('records_processed'))
        ->toBe(2);
});
