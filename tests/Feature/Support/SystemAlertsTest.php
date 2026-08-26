<?php

use App\Domain\Notifications\AlertDetector;
use App\Domain\Notifications\SystemAlert;
use App\Jobs\ReconcilePayments;
use App\Jobs\SendSystemAlerts;
use App\Mail\SystemAlertMail;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Operational alerts  [TDD §10, WP-31]
|--------------------------------------------------------------------------
|
| Five conditions, every one of them silent. Nothing errors, no page breaks, no
| resident complains — the symptom arrives a month later as numbers that do not
| add up. On shared hosting there is no daemon to crash and no supervisor to
| notice, so an email is the only thing that reaches somebody not looking.
|
| The definition of done asks for each alert to fire in a deliberately induced
| condition rather than being assumed, so each one below is induced for real:
| a backdated job_runs row, a table of failures, a batch of returned payments.
|
| The cooldown gets as much attention as the firing. Five conditions checked
| hourly with no memory is a hundred and twenty emails a day, which teaches the
| recipient to filter the sender — and then the alert that mattered lands in a
| folder nobody opens.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Cache::flush();

    $this->admin = User::factory()->create([
        'role' => 'admin', 'email' => 'office@example.test', 'status' => 'active',
    ]);

    $this->detector = app(AlertDetector::class);
});

/** Run the job the way the scheduler does. */
function runAlerts(): void
{
    app()->call([new SendSystemAlerts, 'handle']);
}

/** @return list<string> */
function detected(): array
{
    return array_map(
        fn (array $row) => $row['alert']->value,
        app(AlertDetector::class)->detect(),
    );
}

function jobRun(string $name, string $status, ?string $startedAt = null, ?string $error = null): void
{
    DB::table('job_runs')->insert([
        'job_name' => $name,
        'started_at' => $startedAt ?? now(),
        'finished_at' => $startedAt ?? now(),
        'status' => $status,
        'records_processed' => 0,
        'error' => $error,
        'created_at' => now(),
    ]);
}

/**
 * A payment on a real lease.
 *
 * The factory leaves lease_id out deliberately — a payment with no lease has
 * nothing to allocate against — so every caller has to supply one.
 */
function alertPayment(array $attributes = []): Payment
{
    $tenant = Tenant::factory()->create();

    $lease = new Lease;
    $lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => '500.00', 'tenant_portion' => '500.00', 'ha_portion' => '0.00',
        'rent_due_day' => 1, 'grace_period_days' => 5, 'status' => 'active',
    ])->save();

    return Payment::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'lease_id' => $lease->id,
        'status' => Payment::STATUS_SETTLED,
        'gateway' => 'authorize_net',
    ], $attributes));
}

/*
 |--------------------------------------------------------------------------
 | 1 — Reconciliation has stopped
 |--------------------------------------------------------------------------
 */

it('fires when reconciliation has not succeeded in 36 hours', function () {
    jobRun(ReconcilePayments::class, 'success', now()->subHours(40)->toDateTimeString());

    expect(detected())->toContain(SystemAlert::ReconciliationStale->value);

    runAlerts();

    Mail::assertQueued(SystemAlertMail::class, fn (SystemAlertMail $mail) => $mail->alert === SystemAlert::ReconciliationStale
        && $mail->hasTo('office@example.test'));
});

it('stays quiet while reconciliation is merely recent', function () {
    jobRun(ReconcilePayments::class, 'success', now()->subHours(20)->toDateTimeString());

    expect(detected())->not->toContain(SystemAlert::ReconciliationStale->value);
});

it('does not shout at a system that has never gone live', function () {
    // No job_runs rows at all. Never-run is not stale, and an alert that starts
    // the day the system is deployed is one nobody ever reads again.
    expect(detected())->not->toContain(SystemAlert::ReconciliationStale->value);
});

it('uses the configured staleness threshold, not a second hard-coded one', function () {
    // reconciliation.stale_hours existed as a settings row before anything read
    // it. The banner and this alert now resolve it in the same place, so they
    // cannot disagree about when reconciliation has gone stale.
    app(Settings::class)->set('reconciliation.stale_hours', '12');

    jobRun(ReconcilePayments::class, 'success', now()->subHours(20)->toDateTimeString());

    expect(detected())->toContain(SystemAlert::ReconciliationStale->value);
});

/*
 |--------------------------------------------------------------------------
 | 2 — The backup failed
 |--------------------------------------------------------------------------
 */

it('fires when a backup run failed', function () {
    jobRun('App\Jobs\RunDatabaseBackup', 'failed', now()->subHours(2)->toDateTimeString(), 'No space left on device');

    expect(detected())->toContain(SystemAlert::BackupFailed->value);

    runAlerts();

    Mail::assertQueued(SystemAlertMail::class, function (SystemAlertMail $mail) {
        return $mail->alert === SystemAlert::BackupFailed
            && $mail->detail['Reported'] === 'No space left on device';
    });
});

it('is quiet while there is no backup to fail', function () {
    // WP-32 has not landed. Matching on the job name rather than a class means
    // this starts working the day it does, and says nothing until then.
    expect(detected())->not->toContain(SystemAlert::BackupFailed->value);
});

it('ignores a backup that succeeded, and one that failed last week', function () {
    jobRun('App\Jobs\RunDatabaseBackup', 'success', now()->subHours(2)->toDateTimeString());
    jobRun('App\Jobs\RunDatabaseBackup', 'failed', now()->subDays(8)->toDateTimeString(), 'Old news');

    expect(detected())->not->toContain(SystemAlert::BackupFailed->value);
});

/*
 |--------------------------------------------------------------------------
 | 3 — Jobs are failing
 |--------------------------------------------------------------------------
 */

it('fires above five failures in an hour, counting both kinds', function () {
    // A scheduled job that threw leaves a failed job_runs row; a queued one that
    // exhausted its retries lands in failed_jobs. Counting one misses half.
    foreach (range(1, 3) as $i) {
        jobRun('App\Jobs\PostScheduledCharges', 'failed', now()->subMinutes(10)->toDateTimeString(), 'Boom');
    }

    foreach (range(1, 3) as $i) {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'Boom',
            'failed_at' => now()->subMinutes(5),
        ]);
    }

    expect(detected())->toContain(SystemAlert::FailedJobs->value);

    runAlerts();

    Mail::assertQueued(SystemAlertMail::class, fn (SystemAlertMail $mail) => $mail->alert === SystemAlert::FailedJobs
        && $mail->detail['Failures in the last hour'] === 6);
});

it('tolerates the odd transient failure', function () {
    // The queue retries three times before giving up. One failure is a timeout
    // or a locked row, not an outage.
    foreach (range(1, 5) as $i) {
        jobRun('App\Jobs\PostScheduledCharges', 'failed', now()->subMinutes(10)->toDateTimeString(), 'Boom');
    }

    expect(detected())->not->toContain(SystemAlert::FailedJobs->value);
});

it('counts only the last hour, not the whole history', function () {
    foreach (range(1, 20) as $i) {
        jobRun('App\Jobs\PostScheduledCharges', 'failed', now()->subHours(4)->toDateTimeString(), 'Yesterday');
    }

    expect(detected())->not->toContain(SystemAlert::FailedJobs->value);
});

/*
 |--------------------------------------------------------------------------
 | 4 — Too many payments returned
 |--------------------------------------------------------------------------
 */

it('fires when more than five percent of a day’s payments come back', function () {
    foreach (range(1, 19) as $i) {
        alertPayment(['status' => Payment::STATUS_SETTLED]);
    }
    alertPayment(['status' => Payment::STATUS_RETURNED]);

    // One in twenty is 5% exactly, which is not "more than"; two is.
    expect(detected())->not->toContain(SystemAlert::HighReturnRate->value);

    alertPayment(['status' => Payment::STATUS_RETURNED]);

    expect(detected())->toContain(SystemAlert::HighReturnRate->value);

    runAlerts();

    Mail::assertQueued(SystemAlertMail::class, fn (SystemAlertMail $mail) => $mail->alert === SystemAlert::HighReturnRate);
});

it('does not call one return out of three a fifty percent problem', function () {
    // Without a floor this alert fires on any quiet day with a single return,
    // which is most quiet days.
    alertPayment(['status' => Payment::STATUS_RETURNED]);
    alertPayment(['status' => Payment::STATUS_SETTLED]);
    alertPayment(['status' => Payment::STATUS_SETTLED]);

    expect(detected())->not->toContain(SystemAlert::HighReturnRate->value);
});

it('measures against payments the bank answered, not payments in flight', function () {
    // Pending payments have not had the chance to be returned, and counting
    // them would flatter the rate into silence.
    foreach (range(1, 12) as $i) {
        alertPayment(['status' => Payment::STATUS_SETTLED]);
    }
    foreach (range(1, 3) as $i) {
        alertPayment(['status' => Payment::STATUS_RETURNED]);
    }
    foreach (range(1, 200) as $i) {
        alertPayment(['status' => Payment::STATUS_PENDING]);
    }

    expect(detected())->toContain(SystemAlert::HighReturnRate->value);
});

/*
 |--------------------------------------------------------------------------
 | 5 — A payment nobody matched
 |--------------------------------------------------------------------------
 */

it('fires for a payment the gateway knows about and we never matched', function () {
    alertPayment([
        'status' => Payment::STATUS_PENDING,
        'gateway_transaction_id' => '120089164113',
        'submitted_at' => now()->subDays(12),
    ]);

    expect(detected())->toContain(SystemAlert::UnmatchedPayment->value);

    runAlerts();

    Mail::assertQueued(SystemAlertMail::class, fn (SystemAlertMail $mail) => $mail->alert === SystemAlert::UnmatchedPayment);
});

it('leaves a payment inside the reconciliation window alone', function () {
    alertPayment([
        'status' => Payment::STATUS_PENDING,
        'gateway_transaction_id' => '120089164113',
        'submitted_at' => now()->subDays(3),
    ]);

    expect(detected())->not->toContain(SystemAlert::UnmatchedPayment->value);
});

it('ignores an old payment the gateway never heard of', function () {
    // That one is abandoned, and CleanupAbandonedPayments voids it. Only a
    // payment with a transaction id is money that may actually have moved.
    alertPayment([
        'status' => Payment::STATUS_PENDING,
        'gateway_transaction_id' => null,
        'submitted_at' => now()->subDays(30),
    ]);

    expect(detected())->not->toContain(SystemAlert::UnmatchedPayment->value);
});

/*
 |--------------------------------------------------------------------------
 | Not becoming noise
 |--------------------------------------------------------------------------
 */

it('sends each alert once per cooldown, however often it is checked', function () {
    jobRun(ReconcilePayments::class, 'success', now()->subHours(40)->toDateTimeString());

    runAlerts();
    runAlerts();
    runAlerts();

    // Hourly checks with no memory would be a hundred and twenty emails a day.
    Mail::assertQueuedCount(1);
});

it('I-8 is idempotent — a second run in the same minute changes nothing', function () {
    jobRun(ReconcilePayments::class, 'success', now()->subHours(40)->toDateTimeString());

    runAlerts();
    runAlerts();

    Mail::assertQueuedCount(1);
    expect(DB::table('job_runs')->where('job_name', SendSystemAlerts::class)->count())->toBe(2);
});

it('alerts again immediately when a fault is fixed and comes back', function () {
    jobRun(ReconcilePayments::class, 'success', now()->subHours(40)->toDateTimeString());
    runAlerts();

    // Fixed: a fresh successful run.
    jobRun(ReconcilePayments::class, 'success', now()->toDateTimeString());
    runAlerts();

    expect(Cache::has(SendSystemAlerts::cooldownKey(SystemAlert::ReconciliationStale)))->toBeFalse();

    // Broken again inside what would have been the cooldown window. Silence
    // here is the failure this whole package exists to prevent.
    DB::table('job_runs')->delete();
    jobRun(ReconcilePayments::class, 'success', now()->subHours(40)->toDateTimeString());
    runAlerts();

    Mail::assertQueuedCount(2);
});

it('records what it did, so a run that sent nothing is distinguishable from one that never ran', function () {
    jobRun(ReconcilePayments::class, 'success', now()->subHours(40)->toDateTimeString());

    runAlerts();

    $run = DB::table('job_runs')->where('job_name', SendSystemAlerts::class)->latest('id')->first();

    expect($run->status)->toBe('success')->and($run->records_processed)->toBe(1);
});

/*
 |--------------------------------------------------------------------------
 | Who hears about it
 |--------------------------------------------------------------------------
 */

it('emails every active administrator and nobody else', function () {
    $second = User::factory()->create(['role' => 'admin', 'email' => 'marta@example.test', 'status' => 'active']);
    User::factory()->create(['role' => 'admin', 'email' => 'gone@example.test', 'status' => 'suspended']);
    User::factory()->create(['role' => 'tenant', 'email' => 'resident@example.test']);

    jobRun(ReconcilePayments::class, 'success', now()->subHours(40)->toDateTimeString());
    runAlerts();

    Mail::assertQueued(SystemAlertMail::class, function (SystemAlertMail $mail) use ($second) {
        return $mail->hasTo('office@example.test')
            && $mail->hasTo($second->email)
            && ! $mail->hasTo('gone@example.test')
            && ! $mail->hasTo('resident@example.test');
    });
});

it('does not fall over when there is nobody to tell', function () {
    User::query()->delete();

    jobRun(ReconcilePayments::class, 'success', now()->subHours(40)->toDateTimeString());

    runAlerts();

    Mail::assertNothingQueued();

    // A system with no administrator is itself a finding, and it is one
    // somebody discovers at exactly the wrong moment.
    expect(DB::table('job_runs')->where('job_name', SendSystemAlerts::class)->first()->status)->toBe('success');
});

/*
 |--------------------------------------------------------------------------
 | I-5 — what goes in an email
 |--------------------------------------------------------------------------
 */

it('I-5 puts no resident name and no bank detail in an alert', function () {
    $tenant = Tenant::factory()->create(['first_name' => 'Uriel', 'last_name' => 'Pouros']);

    alertPayment([
        'tenant_id' => $tenant->id,
        'status' => Payment::STATUS_PENDING,
        'gateway_transaction_id' => '120089164113',
        'submitted_at' => now()->subDays(12),
    ]);

    $triggered = collect(app(AlertDetector::class)->detect())
        ->firstWhere(fn (array $row) => $row['alert'] === SystemAlert::UnmatchedPayment);

    $rendered = json_encode($triggered['detail']);

    expect($rendered)->not->toContain('Pouros')
        ->and($rendered)->not->toContain('Uriel');

    foreach (['routing', 'account_number', 'cvv'] as $forbidden) {
        expect($rendered)->not->toContain($forbidden);
    }
});

it('every alert says what has happened and what to do about it', function () {
    // UI §8 applies to an administrator at the weekend as much as to a
    // resident: an alert with no next step is a worry, not information.
    foreach (SystemAlert::cases() as $alert) {
        expect(trim($alert->subject()))->not->toBe('');
        expect(trim($alert->summary()))->not->toBe('');
        expect(trim($alert->action()))->not->toBe('');
        expect($alert->cooldownHours())->toBeGreaterThan(0);
    }
});
