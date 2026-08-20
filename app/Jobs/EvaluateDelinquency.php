<?php

namespace App\Jobs;

use App\Concerns\RecordsJobRun;
use App\Domain\Delinquency\DelinquencyService;
use App\Models\Lease;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Daily Management Review evaluation.  [TDD §8, FR-DEL-01]
 *
 * 02:30, **after** fees at 02:00 and charges at 01:00. The order is the whole
 * point of running these as three jobs: an account evaluated before its rent
 * has been charged looks paid up, and one evaluated before its late fee has
 * posted is judged on the wrong balance.
 *
 * **This job only ever puts accounts in.** Releasing is a manual act with a
 * recorded reason (BR-14), so there is deliberately no code path here that
 * takes an account out — not even when the balance reaches zero. A tenant who
 * pays in full still gets a person looking at their account, which is the
 * point of calling it Management Review.
 */
class EvaluateDelinquency implements ShouldQueue
{
    use Queueable;
    use RecordsJobRun;

    public int $tries = 3;

    public function handle(
        DelinquencyService $delinquency,
        BusinessCalendar $calendar,
        AuditLogger $audit,
    ): int {
        return $this->trackJobRun(function () use ($delinquency, $calendar, $audit) {
            $today = $calendar->today();
            $entered = 0;
            $failed = [];

            Lease::query()
                ->where('status', Lease::STATUS_ACTIVE)
                ->where('delinquency_state', DelinquencyService::STATE_CURRENT)
                ->with('tenant')
                ->orderBy('id')
                ->chunkById(100, function ($leases) use ($delinquency, $today, &$entered, &$failed) {
                    foreach ($leases as $lease) {
                        try {
                            if (! $delinquency->shouldEnterReview($lease, $today)) {
                                continue;
                            }

                            $delinquency->enterReview(
                                $lease,
                                sprintf(
                                    'Outstanding tenant balance on day %d after the due date.',
                                    $delinquency->triggerDay(),
                                ),
                            );

                            $entered++;
                        } catch (Throwable $e) {
                            // One bad lease must not stop the rest being
                            // evaluated, and the next run picks it up.
                            $failed[] = ['lease_id' => $lease->id, 'error' => $e->getMessage()];
                        }
                    }
                });

            if ($failed !== []) {
                $audit->record('delinquency.evaluate.partial_failure', null, [
                    'date' => $today->toDateString(),
                    'failed' => $failed,
                ]);
            }

            return $entered;
        });
    }
}
