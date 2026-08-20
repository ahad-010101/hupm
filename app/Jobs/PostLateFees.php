<?php

namespace App\Jobs;

use App\Concerns\RecordsJobRun;
use App\Domain\Fees\LateFeeService;
use App\Models\Lease;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Daily late fee posting.  [TDD §8, FR-FEE-01]
 *
 * 02:00, **after** charge posting at 01:00. The ordering is not cosmetic: a
 * fee calculated against a period whose rent has not been charged yet would
 * find nothing outstanding and skip a lease that is genuinely late. Charges,
 * then fees, then delinquency — each job needs the one before it to have
 * settled (TDD §8).
 *
 * Does nothing at all while `fees.automation_enabled` is false, which is how it
 * ships. The run is still recorded, so a switched-off job and a broken cron do
 * not look the same in `job_runs`.
 */
class PostLateFees implements ShouldQueue
{
    use Queueable;
    use RecordsJobRun;

    public int $tries = 3;

    public function handle(LateFeeService $fees, BusinessCalendar $calendar, AuditLogger $audit): int
    {
        return $this->trackJobRun(function () use ($fees, $calendar, $audit) {
            if (! $fees->isEnabled()) {
                // Recorded rather than silent: "nothing happened because it is
                // switched off" and "nothing happened because cron is broken"
                // are different facts and need to look different.
                $audit->record('fees.late.skipped', null, [
                    'reason' => 'fees.automation_enabled is off pending attorney review of the fee language',
                ]);

                return 0;
            }

            $today = $calendar->today();
            $posted = 0;
            $failed = [];

            Lease::query()
                ->where('status', Lease::STATUS_ACTIVE)
                ->with('tenant')
                ->orderBy('id')
                ->chunkById(100, function ($leases) use ($fees, $today, &$posted, &$failed) {
                    foreach ($leases as $lease) {
                        try {
                            $posted += $fees->postFor($lease, $today);
                        } catch (Throwable $e) {
                            // One bad lease must not cost the other twenty-six
                            // their assessment, and posting is idempotent, so
                            // tomorrow picks up whatever was missed.
                            $failed[] = ['lease_id' => $lease->id, 'error' => $e->getMessage()];
                        }
                    }
                });

            if ($failed !== []) {
                $audit->record('fees.late.partial_failure', null, [
                    'date' => $today->toDateString(),
                    'failed' => $failed,
                ]);
            }

            return $posted;
        });
    }
}
