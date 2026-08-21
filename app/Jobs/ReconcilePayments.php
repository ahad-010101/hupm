<?php

namespace App\Jobs;

use App\Concerns\RecordsJobRun;
use App\Domain\Payments\ReconciliationService;
use App\Support\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily settlement reconciliation.  [TDD §8, FR-PAY-04, R-6]
 *
 * The authoritative answer to "did that payment actually arrive". Runs at
 * 06:00, after the overnight settlement window, and re-reads ten days so a
 * missed run heals itself rather than losing a day of returns forever.
 *
 * **A silent failure here is the most dangerous thing this system can do**
 * (UI §3.9): balances would drift from reality with nothing on screen to say
 * so. Hence RecordsJobRun on every run, and a banner on the admin console the
 * moment the last success is more than 36 hours old.
 */
class ReconcilePayments implements ShouldQueue
{
    use Queueable;
    use RecordsJobRun;

    /** TDD §9.1: settlement queries retry. Payment submissions never do. */
    public int $tries = 3;

    /**
     * The full outcome of the last run, for a caller that wants to report it.
     *
     * The nightly run needs only a count. The *manual* run needs to tell the
     * admin what it found — and it has to go through this job rather than
     * calling the service, because a run that records nothing in `job_runs`
     * leaves the staleness banner up. An alarm whose own suggested remedy
     * cannot silence it teaches people to ignore the alarm.
     *
     * @var array{settled:int, returned:int, noc:int, unmatched:int, errors:list<string>}|null
     */
    public ?array $lastResult = null;

    public function handle(ReconciliationService $reconciliation, AuditLogger $audit): int
    {
        return $this->trackJobRun(function () use ($reconciliation, $audit) {
            $result = $this->lastResult = $reconciliation->run();

            if ($result['errors'] !== []) {
                // Recorded rather than thrown: the run did useful work on every
                // other payment, and losing that to one bad transaction would
                // be worse than the transaction itself.
                $audit->record('payment.reconcile.partial_failure', null, [
                    'errors' => array_slice($result['errors'], 0, 20),
                ]);
            }

            return $result['settled'] + $result['returned'];
        });
    }
}
