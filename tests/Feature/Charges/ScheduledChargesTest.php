<?php

use App\Domain\Charges\ChargePostingService;
use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Notifications\NotificationService;
use App\Jobs\PostScheduledCharges;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Scheduled charge posting  [WP-10, FR-CHG-01, TDD §8]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->charges = app(ChargePostingService::class);
    $this->balances = app(BalanceCalculator::class);
    $this->tenant = Tenant::factory()->create();
});

function makeLease(array $overrides = []): Lease
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
    ], $overrides))->save();

    return $lease;
}

/** Run the posting for a lease at a fixed business date. */
function postAt(Lease $lease, string $date): int
{
    CarbonImmutable::setTestNow($date.' 01:00:00');
    Carbon::setTestNow($date.' 01:00:00');

    $count = test()->charges->postFor($lease, CarbonImmutable::parse($date));

    CarbonImmutable::setTestNow();
    Carbon::setTestNow();

    return $count;
}

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('posts tenant and housing authority rent for the period', function () {
    $lease = makeLease();

    postAt($lease, '2026-01-01');

    $entries = LedgerEntry::where('period', '2026-01')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->firstWhere('payer', 'tenant')->amount->toDecimalString())->toBe('500.00')
        ->and($entries->firstWhere('payer', 'housing_authority')->amount->toDecimalString())->toBe('700.00')
        // I-4 does not apply to storage — the HA obligation is real (BR-01).
        // It applies to what a tenant is shown.
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('AC-CHG-01 creates no duplicates when run twice on the same day', function () {
    $lease = makeLease();

    postAt($lease, '2026-01-01');
    $second = postAt($lease, '2026-01-01');

    expect($second)->toBe(0)
        ->and(LedgerEntry::count())->toBe(2)
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('AC-CHG-02 posts only the tenant charge when the HA portion is zero', function () {
    $lease = makeLease([
        'total_contract_rent' => '500.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '0.00',
        'is_subsidised' => false,
    ]);

    postAt($lease, '2026-01-01');

    // An entry for $0.00 is noise on a ledger someone has to read.
    expect(LedgerEntry::count())->toBe(1)
        ->and(LedgerEntry::first()->payer)->toBe('tenant');
});

it('AC-CHG-03 prorates a mid-period start by day', function () {
    // Moves in on 15 January: 17 days of a 31-day month.
    $lease = makeLease(['start_date' => '2026-01-15']);

    postAt($lease, '2026-01-15');

    $tenantCharge = LedgerEntry::where('payer', 'tenant')->first();

    // 500.00 × 17/31 = 274.1935… → 274.19 (half up, integer cents).
    expect($tenantCharge->amount->toDecimalString())->toBe('274.19')
        ->and($tenantCharge->description)->toContain('17 of 31 days')
        // Dated the day they move in, not the due day that already passed.
        ->and($tenantCharge->posted_on->toDateString())->toBe('2026-01-15');
});

it('AC-CHG-03 prorates the housing authority portion the same way', function () {
    $lease = makeLease(['start_date' => '2026-01-15']);

    postAt($lease, '2026-01-15');

    // 700.00 × 17/31 = 383.87
    expect(LedgerEntry::where('payer', 'housing_authority')->first()->amount->toDecimalString())
        ->toBe('383.87');
});

it('AC-CHG-03 charges the full month from the next period onward', function () {
    $lease = makeLease(['start_date' => '2026-01-15']);

    postAt($lease, '2026-02-01');

    $february = LedgerEntry::where('period', '2026-02')->where('payer', 'tenant')->first();

    expect($february->amount->toDecimalString())->toBe('500.00')
        ->and($february->description)->not->toContain('days');
});

it('GATE Q-10 charges a full month when proration is set to none', function () {
    // The convention is unanswered. Shipping `daily` as a default is a guess,
    // so the alternative has to actually work rather than be a settings row
    // nobody wired up.
    app(Settings::class)->set('charges.proration_method', 'none');
    app(Settings::class)->flush();

    $lease = makeLease(['start_date' => '2026-01-15']);
    postAt($lease, '2026-01-15');

    expect(LedgerEntry::where('payer', 'tenant')->first()->amount->toDecimalString())->toBe('500.00');
});

it('AC-CHG-04 posts every missed period exactly once after an outage', function () {
    $lease = makeLease();

    // The host was down. Nothing ran in January, February or March.
    postAt($lease, '2026-04-01');

    $periods = LedgerEntry::where('payer', 'tenant')->pluck('period')->sort()->values()->all();

    expect($periods)->toBe(['2026-01', '2026-02', '2026-03', '2026-04'])
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('2000.00');
});

it('AC-CHG-04 is safe to re-run immediately after a catch-up', function () {
    $lease = makeLease();

    postAt($lease, '2026-04-01');
    $again = postAt($lease, '2026-04-01');

    // Catch-up is not a special mode — it is the ordinary answer to "what is
    // due", so running it twice behaves like any other double run.
    expect($again)->toBe(0)
        ->and(LedgerEntry::where('payer', 'tenant')->count())->toBe(4);
});

it('posts nothing before the due day', function () {
    $lease = makeLease(['rent_due_day' => 15]);

    postAt($lease, '2026-01-14');
    expect(LedgerEntry::count())->toBe(0);

    postAt($lease, '2026-01-15');
    expect(LedgerEntry::count())->toBe(2);
});

it('D-01 posts two utility schedules in the same period', function () {
    $lease = makeLease();

    foreach ([['Water', '45.00'], ['Trash collection', '22.50']] as [$description, $amount]) {
        DB::table('lease_charge_schedules')->insert([
            'lease_id' => $lease->id, 'category' => 'utility',
            'description' => $description, 'amount' => $amount,
            'payer' => 'tenant', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    postAt($lease, '2026-01-01');

    // The unique index the spec originally specified permitted only ONE
    // utility charge per (lease, period, category, payer). Both of these
    // existing is the proof that replacing it was necessary.
    $utilities = LedgerEntry::where('category', 'utility')->get();

    expect($utilities)->toHaveCount(2)
        ->and($utilities->pluck('description')->sort()->values()->all())
        ->toBe(['Trash collection', 'Water'])
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('567.50');
});

it('skips an inactive schedule', function () {
    $lease = makeLease();

    DB::table('lease_charge_schedules')->insert([
        'lease_id' => $lease->id, 'category' => 'utility', 'description' => 'Old parking',
        'amount' => '30.00', 'payer' => 'tenant', 'active' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    postAt($lease, '2026-01-01');

    expect(LedgerEntry::where('category', 'utility')->count())->toBe(0);
});

it('AC-REG-07 posts nothing for an ended lease', function () {
    $lease = makeLease();
    $lease->forceFill(['status' => 'ended', 'end_date' => '2026-01-10'])->save();

    expect(postAt($lease, '2026-02-01'))->toBe(0)
        ->and(LedgerEntry::count())->toBe(0);
});

it('stops posting at the lease end date', function () {
    $lease = makeLease(['end_date' => '2026-03-31']);

    postAt($lease, '2026-06-01');

    expect(LedgerEntry::where('payer', 'tenant')->pluck('period')->sort()->values()->all())
        ->toBe(['2026-01', '2026-02', '2026-03']);
});

it('queues the rent-due email once per period', function () {
    $lease = makeLease();

    postAt($lease, '2026-01-01');

    expect(DB::table('notification_logs')->where('template', 'rent_due')->count())->toBe(1);

    postAt($lease, '2026-01-01');

    // No second email for a period already charged.
    expect(DB::table('notification_logs')->where('template', 'rent_due')->count())->toBe(1);
});

it('records a rent-due email as not_deliverable for a tenant with no address', function () {
    $this->tenant->update(['email' => null]);
    $lease = makeLease();

    postAt($lease, '2026-01-01');

    // Q-4: never a silent failure. Admin sees it (AC-NTF-03).
    expect(DB::table('notification_logs')->where('status', 'not_deliverable')->count())->toBe(1);
});

it('leaves no partial state when a lease fails mid-period', function () {
    $lease = makeLease();

    // Fail after the tenant charge has been written but before the period is
    // finished. A half-posted period is worse than a missed one: the next run
    // would see the period as handled and never complete it.
    $this->app->bind(NotificationService::class, function () {
        return new class(app(AuditLogger::class)) extends NotificationService
        {
            public function __construct(private $unused) {}

            public function send($template, $email, array $data = [], $tenantId = null, $userId = null): int
            {
                throw new RuntimeException('Mail provider unreachable');
            }
        };
    });

    expect(fn () => app(ChargePostingService::class)->postFor($lease, CarbonImmutable::parse('2026-01-01')))
        ->toThrow(RuntimeException::class);

    expect(LedgerEntry::count())->toBe(0);
});

it('TDD §8 keeps one bad lease from stopping the others', function () {
    $good = makeLease();

    $other = Tenant::factory()->create();
    $bad = new Lease;
    $bad->forceFill([
        'unit_id' => Unit::factory()->create()->id, 'tenant_id' => $other->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => '900.00', 'tenant_portion' => '900.00', 'ha_portion' => '0.00',
        'rent_due_day' => 1, 'status' => 'active',
    ])->save();

    // Break exactly one lease: the mail send throws for this tenant only.
    $this->app->bind(NotificationService::class, function () use ($other) {
        return new class($other->id) extends NotificationService
        {
            public function __construct(private int $poisoned) {}

            public function send($template, $email, array $data = [], $tenantId = null, $userId = null): int
            {
                if ($tenantId === $this->poisoned) {
                    throw new RuntimeException('Mail provider unreachable for this tenant');
                }

                return 0;
            }
        };
    });

    // Midday UTC, so the Georgia date is unambiguously 1 January.
    // 01:00 UTC would be 31 December in Georgia (D-07).
    CarbonImmutable::setTestNow('2026-01-01 12:00:00');
    Carbon::setTestNow('2026-01-01 12:00:00');

    app(PostScheduledCharges::class)->handle(
        app(ChargePostingService::class),
        app(BusinessCalendar::class),
        app(AuditLogger::class),
    );

    // The healthy lease still got its rent.
    expect(LedgerEntry::where('lease_id', $good->id)->count())->toBe(2)
        ->and(DB::table('job_runs')->where('job_name', PostScheduledCharges::class)->where('status', 'success')->count())->toBe(1);
});

it('records a job run so a silent cron failure is detectable', function () {
    makeLease();

    // Midday UTC, so the Georgia date is unambiguously 1 January.
    // 01:00 UTC would be 31 December in Georgia (D-07).
    CarbonImmutable::setTestNow('2026-01-01 12:00:00');
    Carbon::setTestNow('2026-01-01 12:00:00');

    app(PostScheduledCharges::class)->handle(
        app(ChargePostingService::class),
        app(BusinessCalendar::class),
        app(AuditLogger::class),
    );

    $run = DB::table('job_runs')->first();

    // R-5: on shared hosting a broken cron fails silently. An empty job_runs
    // table for the day is how it gets noticed.
    expect($run->status)->toBe('success')
        ->and($run->records_processed)->toBe(2)
        ->and($run->finished_at)->not->toBeNull();
});

it('posts through LedgerService, never directly', function () {
    $lease = makeLease();
    postAt($lease, '2026-01-01');

    // I-2 is asserted structurally in SoleLedgerWriterTest; here it is asserted
    // behaviourally — every posted row carries the audit trail only the service
    // writes.
    expect(DB::table('audit_logs')->where('action', 'ledger.charge.posted')->count())->toBe(2);
});
