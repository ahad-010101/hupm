<?php

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
