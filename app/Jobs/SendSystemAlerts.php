<?php

namespace App\Jobs;

use App\Concerns\RecordsJobRun;
use App\Domain\Notifications\AlertDetector;
use App\Domain\Notifications\SystemAlert;
use App\Mail\SystemAlertMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tell an administrator when something has quietly gone wrong.  [TDD §10, WP-31]
 *
 * Hourly, because the fastest-moving of the five conditions — six failed jobs
 * in an hour — is measured over an hour. The rest are slower, and their
 * cooldowns stop the extra checks from turning into extra email.
 *
 * **The cooldown is the design.** Five conditions checked hourly, with no
 * memory, is a hundred and twenty emails a day while one of them is true. That
 * is not louder, it is quieter: it teaches the recipient to filter the sender,
 * and then the one alert that mattered arrives in a folder nobody opens.
 *
 * So each alert is sent at most once per its own cooldown, and the marker is
 * cleared the moment the condition stops being true — a fault that recurs after
 * being fixed gets a fresh email rather than being swallowed by a timer that
 * has not expired.
 *
 * Idempotent (I-8): running it twice in a minute sends one set of emails,
 * because the second run finds every marker already in place.
 */
class SendSystemAlerts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RecordsJobRun;
    use SerializesModels;

    /** Cache key holding the time an alert was last emailed. */
    public static function cooldownKey(SystemAlert $alert): string
    {
        return 'alert.sent.'.$alert->value;
    }

    public function handle(AlertDetector $detector): void
    {
        $this->trackJobRun(function () use ($detector) {
            $triggered = $detector->detect();

            $this->clearMarkersFor($triggered);

            $recipients = $this->administrators();

            if ($recipients === []) {
                // Not silent: a system with nobody to alert is itself a finding,
                // and it is one somebody discovers at exactly the wrong moment.
                if ($triggered !== []) {
                    Log::warning('System alerts fired with no administrator to send them to.', [
                        'alerts' => array_map(fn (array $row) => $row['alert']->value, $triggered),
                    ]);
                }

                return 0;
            }

            $sent = 0;

            foreach ($triggered as ['alert' => $alert, 'detail' => $detail]) {
                if (! $this->due($alert)) {
                    continue;
                }

                // Queued rather than sent inline. The scheduler has fifty
                // seconds a minute; a slow mail API must not be what stops the
                // charge job from running.
                Mail::to($recipients)->queue(new SystemAlertMail($alert, $detail));

                Cache::put(
                    self::cooldownKey($alert),
                    now()->toIso8601String(),
                    now()->addHours($alert->cooldownHours()),
                );

                // The detail is counts and timestamps by construction, so this
                // is safe to log — and a record that the alert was raised is
                // worth having when somebody asks why nobody was told.
                Log::warning('System alert raised.', [
                    'alert' => $alert->value,
                    'recipients' => count($recipients),
                ]);

                $sent++;
            }

            return $sent;
        });
    }

    /**
     * Forget the marker for anything no longer true.
     *
     * Without this, a fault fixed within the cooldown and then recurring would
     * be met with silence for the rest of the window — which is the failure
     * this whole package exists to prevent.
     *
     * @param  list<array{alert: SystemAlert, detail: array<string, string|int>}>  $triggered
     */
    private function clearMarkersFor(array $triggered): void
    {
        $active = array_map(fn (array $row) => $row['alert'], $triggered);

        foreach (SystemAlert::cases() as $alert) {
            if (! in_array($alert, $active, true)) {
                Cache::forget(self::cooldownKey($alert));
            }
        }
    }

    private function due(SystemAlert $alert): bool
    {
        return ! Cache::has(self::cooldownKey($alert));
    }

    /** @return list<string> */
    private function administrators(): array
    {
        return User::query()
            ->where('role', 'admin')
            ->where('status', '!=', 'suspended')
            ->whereNotNull('email')
            ->orderBy('id')
            ->pluck('email')
            ->all();
    }
}
