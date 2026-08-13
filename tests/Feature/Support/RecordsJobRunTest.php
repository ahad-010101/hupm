<?php

use App\Concerns\RecordsJobRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| RecordsJobRun  [WP-02, risk R-5]
|--------------------------------------------------------------------------
|
| On shared hosting a broken cron produces no error anywhere — no daemon
| crashes, no supervisor alerts. An empty job_runs table for the day is the
| only signal that the cron entry's absolute PHP path is wrong, which is the
| single most common failure mode on this kind of host.
|
*/

uses(RefreshDatabase::class);

/** A stand-in for a scheduled job. */
class FakeJob
{
    use RecordsJobRun;

    public function __construct(private readonly \Closure $work) {}

    public function run(): int
    {
        return $this->trackJobRun($this->work);
    }
}

it('records a successful run with the number of records processed', function () {
    (new FakeJob(fn () => 42))->run();

    $row = DB::table('job_runs')->first();

    expect($row->job_name)->toBe(FakeJob::class)
        ->and($row->status)->toBe('success')
        ->and($row->records_processed)->toBe(42)
        ->and($row->started_at)->not->toBeNull()
        ->and($row->finished_at)->not->toBeNull()
        ->and($row->error)->toBeNull();
});

it('records a failure and re-throws, so the scheduler still reports it', function () {
    $job = new FakeJob(fn () => throw new RuntimeException('gateway timeout'));

    expect(fn () => $job->run())->toThrow(RuntimeException::class);

    $row = DB::table('job_runs')->first();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('gateway timeout')
        ->and($row->finished_at)->not->toBeNull();
});

it('leaves a running row behind if the process dies mid-run', function () {
    // The row is written before the work starts, so a job killed by the host's
    // execution-time limit leaves evidence rather than nothing at all. A
    // `running` row with no finished_at is the signature of that.
    $observed = null;

    (new FakeJob(function () use (&$observed) {
        $observed = DB::table('job_runs')->first();

        return 1;
    }))->run();

    expect($observed->status)->toBe('running')
        ->and($observed->finished_at)->toBeNull();
});

it('stores only the exception message, never a trace', function () {
    // A stack trace can carry request data, and nothing resembling bank detail
    // may ever reach a log (I-5).
    $job = new FakeJob(fn () => throw new RuntimeException('boom'));

    try {
        $job->run();
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::table('job_runs')->first()->error)->toBe('boom')
        ->and(DB::table('job_runs')->first()->error)->not->toContain('#0 ');
});
