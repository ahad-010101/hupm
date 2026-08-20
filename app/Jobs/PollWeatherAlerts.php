<?php

namespace App\Jobs;

use App\Concerns\RecordsJobRun;
use App\Domain\Notifications\WeatherAlertService;
use App\Support\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly NWS poll.  [TDD §8, FR-NTF-03, AC-NTF-08]
 *
 * The only scheduled job in this system that is allowed to come back
 * empty-handed without it meaning anything. A weather API is a nice-to-have
 * bolted onto a rent ledger: if NWS is down, the correct behaviour is a log
 * line and another try in an hour, and nothing else in the application should
 * be able to tell.
 *
 * `$tries = 1` for that reason. Retrying a failed poll inside the hour would
 * queue work behind the jobs that actually move money, on a host where the
 * whole queue is drained by one cron tick.
 */
class PollWeatherAlerts implements ShouldQueue
{
    use Queueable;
    use RecordsJobRun;

    public int $tries = 1;

    public function handle(WeatherAlertService $weather, AuditLogger $audit): int
    {
        return $this->trackJobRun(function () use ($weather, $audit) {
            $result = $weather->poll();

            if ($result['errors'] !== []) {
                $audit->record('weather.poll.partial_failure', null, [
                    'errors' => array_slice($result['errors'], 0, 10),
                ]);
            }

            return $result['alerts'];
        });
    }
}
