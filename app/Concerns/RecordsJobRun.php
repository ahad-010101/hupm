<?php

namespace App\Concerns;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Wraps a scheduled job in a `job_runs` record.  [INVARIANT I-8 support]
 *
 * On shared hosting a broken cron fails silently: there is no daemon to crash,
 * no supervisor to alert, and the only symptom is that nothing happens. An
 * empty `job_runs` table for the day is how R-5 — the wrong absolute PHP path
 * in the cron entry — actually gets detected, and it is the single most common
 * shared-hosting failure mode.
 *
 * A row is written *before* the work starts, so a job that dies mid-run leaves
 * a `running` row with no `finished_at` rather than no evidence at all.
 *
 * Usage:
 *
 *     public function handle(): void
 *     {
 *         $this->trackJobRun(fn () => $this->post());  // returns records processed
 *     }
 */
trait RecordsJobRun
{
    /**
     * @param  callable():int  $work  Returns the number of records processed.
     */
    protected function trackJobRun(callable $work): int
    {
        $id = DB::table('job_runs')->insertGetId([
            'job_name' => static::class,
            'started_at' => now(),
            'status' => 'running',
            'records_processed' => 0,
            'created_at' => now(),
        ]);

        try {
            $processed = $work();
        } catch (Throwable $e) {
            DB::table('job_runs')->where('id', $id)->update([
                'finished_at' => now(),
                'status' => 'failed',
                // Message only. A full trace can carry request data, and nothing
                // resembling bank detail may ever reach a log (I-5).
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        DB::table('job_runs')->where('id', $id)->update([
            'finished_at' => now(),
            'status' => 'success',
            'records_processed' => $processed,
        ]);

        return $processed;
    }
}
