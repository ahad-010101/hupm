<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Health endpoint  [API-PUB-07, DEVIATION D-06]
    |--------------------------------------------------------------------------
    |
    | Read from the environment rather than the `settings` table, because
    | /health has to be able to answer at the moment the database is the thing
    | that has broken. A setting that lives in the database cannot describe the
    | database being unreachable.
    |
    | Without HEALTH_TOKEN set, the detailed response is never available — the
    | endpoint fails closed. D-06's whole argument is that publishing job-timing
    | internals invites targeted probing, so an unset token must not mean "open".
    |
    */

    'health' => [

        'token' => env('HEALTH_TOKEN'),

        /*
         | Cron fires `schedule:run` every minute (TDD §12.4). Fifteen gives a
         | loaded shared host plenty of slack while still catching the failure
         | that actually happens: a cron entry with the wrong absolute PHP path,
         | which stops the scheduler dead without a sound (R-5).
         */
        'scheduler_stale_after_minutes' => (int) env('HEALTH_SCHEDULER_STALE_MINUTES', 15),

        /*
         | The queue drains once a minute with --stop-when-empty. A backlog this
         | deep means the worker is not running, not that it is busy.
         */
        'queue_depth_warning' => (int) env('HEALTH_QUEUE_DEPTH_WARNING', 500),
    ],

];
