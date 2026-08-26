<?php

namespace App\Support;

use App\Jobs\ReconcilePayments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * When did reconciliation last succeed?  [UI §3.9, R-6]
 *
 * "The last successful reconciliation time must be visible at all times. If it
 * is more than 36 hours old, display a warning banner — a silently failed
 * reconciliation job is the most dangerous failure mode in the system."
 *
 * The danger is specific: nothing breaks, no page errors, no exception is
 * thrown. Balances simply stop tracking reality, quietly, until somebody
 * notices a tenant is being chased for rent they paid a fortnight ago. On
 * shared hosting the usual cause is a cron entry with the wrong absolute PHP
 * path (R-5), which fails without a sound.
 *
 * 36 hours rather than 24: a daily job that runs at 06:00 is not late at 06:05
 * the next morning, and a banner that cries wolf every morning is a banner
 * nobody reads.
 */
class ReconciliationHealth
{
    /**
     * The shipped default, in hours.
     *
     * Thirty-six rather than twenty-four: a daily job that runs at 06:00 is
     * not late at 06:05 the next morning, and a banner that cries wolf every
     * morning is a banner nobody reads.
     *
     * Overridable through `reconciliation.stale_hours`, which existed as a
     * settings row before anything read it. The banner and the WP-31 email
     * alert both resolve the threshold here, so they cannot disagree about
     * when reconciliation has gone stale.
     */
    private const DEFAULT_STALE_AFTER_HOURS = 36;

    public function __construct(private readonly Settings $settings) {}

    public function staleAfterHours(): int
    {
        return max(1, $this->settings->int('reconciliation.stale_hours', self::DEFAULT_STALE_AFTER_HOURS));
    }

    /** @return array{last_success: ?string, hours_ago: ?int, stale: bool, never_run: bool} */
    public function status(): array
    {
        $last = DB::table('job_runs')
            ->where('job_name', ReconcilePayments::class)
            ->where('status', 'success')
            ->orderByDesc('finished_at')
            ->value('finished_at');

        if (! $last) {
            // Never having run is not the same as being stale, and the two need
            // different words: one is "not set up yet", the other is "it broke".
            return ['last_success' => null, 'hours_ago' => null, 'stale' => false, 'never_run' => true];
        }

        $at = Carbon::parse($last);
        $hours = (int) $at->diffInHours(now());

        return [
            'last_success' => $at->toIso8601String(),
            'hours_ago' => $hours,
            'stale' => $hours > $this->staleAfterHours(),
            'never_run' => false,
        ];
    }
}
