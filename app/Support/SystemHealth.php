<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is the system actually working?  [API-PUB-07, TDD §10, WP-31]
 *
 * Four questions, chosen because each one has a failure mode that is silent on
 * shared hosting:
 *
 * - **Database** — obvious, and the only check that must not assume the others
 *   can run.
 * - **Scheduler** — the single cron entry drives every recurring thing in the
 *   system, including draining the queue. When it stops, nothing errors: rent
 *   is not charged, late fees are not posted, delinquency is not evaluated, and
 *   the first symptom is a tenant asking why they were not billed (R-5).
 * - **Reconciliation** — balances quietly stop tracking reality (R-6). See
 *   {@see ReconciliationHealth} for why this is the most dangerous of the four.
 * - **Queue depth** — the worker runs for fifty seconds a minute with
 *   `--stop-when-empty`. A deep queue means it is not running at all, so every
 *   email — invitations, receipts, notices — is silently stuck.
 *
 * Every check is wrapped: a health endpoint that throws is worse than useless,
 * because the monitor then reports "down" for a reason nobody can read. A check
 * that cannot run reports itself as failed and says why.
 */
class SystemHealth
{
    /**
     * Written by the every-minute scheduled heartbeat in `routes/console.php`.
     *
     * A heartbeat rather than "the most recent job_runs row": the earliest
     * daily job is at 05:00, so at 03:00 an otherwise healthy system would look
     * twenty-two hours stale.
     */
    public const HEARTBEAT_KEY = 'scheduler.heartbeat';

    public function __construct(private readonly ReconciliationHealth $reconciliation) {}

    /**
     * The full picture. Only ever served to a caller holding the token (D-06).
     *
     * @return array{status: string, checks: array<string, array<string, mixed>>}
     */
    public function report(): array
    {
        $checks = [
            'database' => $this->database(),
            'scheduler' => $this->scheduler(),
            'reconciliation' => $this->reconciliationCheck(),
            'queue' => $this->queue(),
        ];

        $healthy = ! collect($checks)->contains(fn (array $check) => $check['ok'] === false);

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ];
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        try {
            DB::select('SELECT 1');

            return ['ok' => true];
        } catch (Throwable $e) {
            // The class, not the message: a connection error quotes the DSN,
            // which carries the database user (I-5 in spirit — nothing
            // identifying belongs in an unauthenticated-adjacent response).
            return ['ok' => false, 'error' => class_basename($e)];
        }
    }

    /** @return array<string, mixed> */
    private function scheduler(): array
    {
        try {
            $last = Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => class_basename($e)];
        }

        if (! $last) {
            // Never having beaten is not the same as having stopped, but for
            // the scheduler both need looking at: on a host where cron was
            // never configured, "not set up yet" *is* the outage.
            return ['ok' => false, 'last_run' => null, 'never_run' => true];
        }

        $at = Carbon::parse($last);
        $minutes = (int) $at->diffInMinutes(now());

        return [
            'ok' => $minutes <= $this->schedulerStaleAfter(),
            'last_run' => $at->toIso8601String(),
            'minutes_ago' => $minutes,
            'never_run' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function reconciliationCheck(): array
    {
        try {
            $status = $this->reconciliation->status();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => class_basename($e)];
        }

        // `never_run` is deliberately not a failure. A system that has not gone
        // live yet has never reconciled, and a health endpoint that reports
        // degraded from the moment it is deployed teaches everyone to ignore it.
        return [
            'ok' => ! $status['stale'],
            'last_success' => $status['last_success'],
            'hours_ago' => $status['hours_ago'],
            'never_run' => $status['never_run'],
        ];
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        try {
            $depth = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => class_basename($e)];
        }

        return [
            'ok' => $depth <= $this->queueDepthWarning(),
            'depth' => $depth,
            'failed' => $failed,
        ];
    }

    private function schedulerStaleAfter(): int
    {
        return max(1, (int) config('hupm.health.scheduler_stale_after_minutes', 15));
    }

    private function queueDepthWarning(): int
    {
        return max(1, (int) config('hupm.health.queue_depth_warning', 500));
    }
}
