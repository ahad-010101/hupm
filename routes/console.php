<?php

use App\Jobs\CleanupAbandonedPayments;
use App\Jobs\PostScheduledCharges;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| The host gives us exactly one cron entry:
|
|   * * * * * <abs-php> /home/user/hupm/artisan schedule:run >> /dev/null 2>&1
|
| Everything recurring hangs off that, including draining the queue — there is
| no daemon and no supervisor on shared hosting, so `queue:work` cannot be left
| running (TDD §8).
|
| Every job here must be idempotent and safe to re-run after a missed day
| (INVARIANT I-8), because a cron that did not fire is a normal event on this
| kind of host, not an exception.
|
*/

/*
 | Charges post before fees, and fees before delinquency evaluation (TDD §8),
 | so each job sees a settled state. A late fee calculated against a balance
 | that has not been charged yet is simply wrong, and the ordering is the only
 | thing preventing it.
 |
 | 01:00 in the COMPANY timezone, not UTC (D-07) — otherwise the job decides
 | "today" is the previous day in Georgia.
 */
Schedule::job(new PostScheduledCharges)
    ->dailyAt('01:00')
    ->timezone(config('app.business_timezone', 'America/New_York'))
    ->withoutOverlapping()
    ->name('post-scheduled-charges');

/*
 | Payments a tenant started and never finished (FR-PAY-01 A1). Hourly rather
 | than daily so the portal does not show "processing" against money nobody
 | sent for most of a day. Only touches rows the gateway has never heard of.
 */
Schedule::job(new CleanupAbandonedPayments)
    ->hourly()
    ->withoutOverlapping()
    ->name('cleanup-abandoned-payments');

// Drain the queue once a minute and exit. --max-time=50 keeps it comfortably
// inside the next minute's tick so two workers never overlap; --stop-when-empty
// means an idle minute costs almost nothing.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Failed jobs are retained for inspection rather than pruned aggressively —
// a notification that failed three times is evidence someone needs to see.
Schedule::command('queue:prune-failed --hours=720')->weekly();
