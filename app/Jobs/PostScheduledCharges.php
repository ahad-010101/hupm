<?php

namespace App\Jobs;

use App\Concerns\RecordsJobRun;
use App\Domain\Charges\ChargePostingService;
use App\Models\Lease;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Daily rent and recurring charge posting.  [TDD §8, FR-CHG-01]
 *
 * Runs at 01:00. **Charges post before fees, and fees before delinquency**
 * (TDD §8) — each job has to see a settled state, or a late fee is calculated
 * against a balance that has not been charged yet.
 *
 * A failure on one lease must not stop the other twenty-six. Each lease is its
 * own transaction inside ChargePostingService, so one bad lease is skipped and
 * reported rather than taking the night's run down with it — and because
 * posting is idempotent (D-01), the next run picks up whatever was missed
 * without double-charging anyone.
 */
class PostScheduledCharges implements ShouldQueue
{
    use Queueable;
    use RecordsJobRun;

    /** TDD §8: three attempts, then alert. */
    public int $tries = 3;

    public function handle(
        ChargePostingService $charges,
        BusinessCalendar $calendar,
        AuditLogger $audit,
    ): int {
        return $this->trackJobRun(function () use ($charges, $calendar, $audit) {
            $today = $calendar->today();
            $posted = 0;
            $failed = [];

            Lease::query()
                ->where('status', Lease::STATUS_ACTIVE)
                ->with('tenant')
                ->orderBy('id')
                ->chunkById(100, function ($leases) use ($charges, $today, &$posted, &$failed) {
                    foreach ($leases as $lease) {
                        try {
                            $posted += $charges->postFor($lease, $today);
                        } catch (Throwable $e) {
                            // One lease with bad data must not cost the other
                            // twenty-six their rent charge.
                            $failed[] = ['lease_id' => $lease->id, 'error' => $e->getMessage()];
                        }
                    }
                });

            if ($failed !== []) {
                $audit->record('charges.posting.partial_failure', null, [
                    'date' => $today->toDateString(),
                    'failed' => $failed,
                ]);
            }

            return $posted;
        });
    }
}
