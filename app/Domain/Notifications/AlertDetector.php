<?php

namespace App\Domain\Notifications;

use App\Domain\Payments\ReconciliationService;
use App\Jobs\SendSystemAlerts;
use App\Models\Payment;
use App\Support\ReconciliationHealth;
use Illuminate\Support\Facades\DB;

/**
 * Are any of the five conditions true right now?  [TDD §10, WP-31]
 *
 * Detection only. Whether anybody is told, and how often, is
 * {@see SendSystemAlerts}'s problem — keeping them apart means each
 * condition can be tested by inducing it, which is what the definition of done
 * asks for, without sending anything.
 *
 * Every threshold below is a judgement about noise. An alert that fires on a
 * normal Tuesday is an alert that gets filtered, and a filtered alert is worse
 * than no alert at all: it looks like coverage.
 */
class AlertDetector
{
    /**
     * More than five in an hour.
     *
     * One failure is a transient — a timeout, a locked row, a moment where the
     * gateway was unreachable — and the queue retries three times before giving
     * up. Six in an hour is a pattern, and patterns do not resolve themselves.
     */
    public const FAILED_JOB_THRESHOLD = 5;

    /**
     * More than five percent of a day's payments returned.
     *
     * Normal ACH return rates run well under one percent. Five percent is not
     * several residents having a bad month at the same time; it is a batch that
     * went out wrong, or a change at the bank.
     *
     * Held as a whole number of percent so the comparison can be integer
     * arithmetic. Nothing on this path is a float.
     */
    public const RETURN_RATE_PERCENT = 5;

    /**
     * Below this, a percentage is noise.
     *
     * Two payments in a day, one returned, is fifty percent and means nothing.
     * Without this floor the alert fires on any quiet day with one return —
     * which is most quiet days.
     */
    public const RETURN_RATE_MINIMUM_PAYMENTS = 10;

    public function __construct(private readonly ReconciliationHealth $reconciliation) {}

    /**
     * Every condition currently true, with enough detail to act on.
     *
     * @return list<array{alert: SystemAlert, detail: array<string, string|int>}>
     */
    public function detect(): array
    {
        return array_values(array_filter([
            $this->reconciliationStale(),
            $this->backupFailed(),
            $this->failedJobs(),
            $this->highReturnRate(),
            $this->unmatchedPayment(),
        ]));
    }

    /** @return array{alert: SystemAlert, detail: array<string, string|int>}|null */
    private function reconciliationStale(): ?array
    {
        $status = $this->reconciliation->status();

        // Never having run is not the same as having stopped. A system that has
        // not gone live yet must not email somebody every day about it.
        if ($status['never_run'] || ! $status['stale']) {
            return null;
        }

        return [
            'alert' => SystemAlert::ReconciliationStale,
            'detail' => [
                'Last successful run' => (string) $status['last_success'],
                'Hours since' => (int) $status['hours_ago'],
                'Threshold' => $this->reconciliation->staleAfterHours().' hours',
            ],
        ];
    }

    /**
     * @return array{alert: SystemAlert, detail: array<string, string|int>}|null
     *
     * Matched on the job name rather than a specific class, so this keeps
     * working when WP-32 lands whatever it lands. Until then the condition is
     * simply never true — which is honest: there is no backup to fail yet.
     */
    private function backupFailed(): ?array
    {
        $failure = DB::table('job_runs')
            ->where('job_name', 'like', '%Backup%')
            ->where('status', 'failed')
            ->where('started_at', '>=', now()->subDay())
            ->orderByDesc('started_at')
            ->first();

        if (! $failure) {
            return null;
        }

        return [
            'alert' => SystemAlert::BackupFailed,
            'detail' => [
                'Job' => class_basename($failure->job_name),
                'Started' => (string) $failure->started_at,
                // The message, never a trace: a trace can carry request data,
                // and this is going out by email (I-5).
                'Reported' => (string) ($failure->error ?: 'No reason recorded.'),
            ],
        ];
    }

    /** @return array{alert: SystemAlert, detail: array<string, string|int>}|null */
    private function failedJobs(): ?array
    {
        $since = now()->subHour();

        // Both halves of "failed". A scheduled job that threw leaves a failed
        // job_runs row; a queued one that exhausted its retries lands in
        // failed_jobs. Counting only one of them misses half the outages.
        $scheduled = DB::table('job_runs')
            ->where('status', 'failed')
            ->where('started_at', '>=', $since)
            ->count();

        $queued = DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();

        $total = $scheduled + $queued;

        if ($total <= self::FAILED_JOB_THRESHOLD) {
            return null;
        }

        return [
            'alert' => SystemAlert::FailedJobs,
            'detail' => [
                'Failures in the last hour' => $total,
                'Scheduled jobs' => $scheduled,
                'Queued jobs' => $queued,
                'Threshold' => 'more than '.self::FAILED_JOB_THRESHOLD,
            ],
        ];
    }

    /** @return array{alert: SystemAlert, detail: array<string, string|int>}|null */
    private function highReturnRate(): ?array
    {
        $since = now()->subDay();

        $returned = Payment::query()
            ->where('status', Payment::STATUS_RETURNED)
            ->where('updated_at', '>=', $since)
            ->count();

        // The denominator is everything the bank gave an answer about, not
        // everything submitted: payments still in flight have not had the
        // chance to be returned and would flatter the rate.
        $decided = Payment::query()
            ->whereIn('status', [Payment::STATUS_SETTLED, Payment::STATUS_RETURNED])
            ->where('updated_at', '>=', $since)
            ->count();

        if ($decided < self::RETURN_RATE_MINIMUM_PAYMENTS || $returned === 0) {
            return null;
        }

        // Cross-multiplied rather than divided. A ratio is not money, so I-10
        // does not strictly bite here — but the architecture test does not know
        // that, and it is right not to guess. Integers are also simply exact:
        // there is no rounding to argue about at the threshold.
        if ($returned * 100 <= $decided * self::RETURN_RATE_PERCENT) {
            return null;
        }

        // Tenths of a percent, formatted by hand. 3 in 17 becomes "17.6%".
        $tenths = intdiv($returned * 1000, $decided);

        return [
            'alert' => SystemAlert::HighReturnRate,
            'detail' => [
                'Returned' => $returned,
                'Settled or returned' => $decided,
                'Rate' => intdiv($tenths, 10).'.'.($tenths % 10).'%',
                'Threshold' => self::RETURN_RATE_PERCENT.'%',
            ],
        ];
    }

    /**
     * @return array{alert: SystemAlert, detail: array<string, string|int>}|null
     *
     * The same definition the admin exceptions panel uses: still pending, the
     * gateway has heard of it, and older than the reconciliation window. These
     * are never voided automatically (FR-PAY-04 step 6) precisely because the
     * money may well have moved.
     */
    private function unmatchedPayment(): ?array
    {
        $cutoff = now()->subDays(ReconciliationService::WINDOW_DAYS);

        $unmatched = Payment::query()
            ->where('status', Payment::STATUS_PENDING)
            ->whereNotNull('gateway_transaction_id')
            ->where('submitted_at', '<', $cutoff)
            ->orderBy('submitted_at');

        $count = (clone $unmatched)->count();

        if ($count === 0) {
            return null;
        }

        $oldest = $unmatched->first();

        return [
            'alert' => SystemAlert::UnmatchedPayment,
            'detail' => [
                'Payments affected' => $count,
                'Oldest reference' => (string) $oldest->id,
                'Submitted' => (string) $oldest->submitted_at?->toDateString(),
                'Window' => ReconciliationService::WINDOW_DAYS.' days',
            ],
        ];
    }
}
