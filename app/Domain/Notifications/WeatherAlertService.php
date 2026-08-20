<?php

namespace App\Domain\Notifications;

use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Models\WeatherAlert;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Weather and emergency alerts.  [FR-NTF-03, AC-NTF-07/08, WP-21]
 *
 * **Deduplication is a database constraint, not application logic.** The unique
 * index on `(property_id, nws_alert_id)` is what guarantees a tenant is emailed
 * once per storm; an `exists()` check before an insert would be a race, and
 * the hourly job is exactly the thing that would lose it. The insert is
 * attempted and the violation is the answer (AC-NTF-07).
 *
 * **NWS being down is a normal Tuesday**, not an incident (AC-NTF-08). It is
 * logged and retried next hour; nothing else in the system notices. That is
 * why this service never throws out of `poll()` — a weather API taking the
 * rent charges down with it would be a far worse failure than a missed alert.
 */
class WeatherAlertService
{
    public function __construct(
        private readonly NwsClient $nws,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * One pass over every property that can have weather.
     *
     * @return array{alerts:int, notified:int, errors:list<string>}
     */
    public function poll(): array
    {
        $result = ['alerts' => 0, 'notified' => 0, 'errors' => []];

        $properties = Property::query()
            ->get()
            // NWS is US-only (D-19). A property abroad is not a failure, it is
            // simply out of scope for this feature.
            ->filter(fn (Property $property) => $property->supportsWeatherAlerts());

        foreach ($properties->groupBy('state') as $state => $inState) {
            try {
                $alerts = $this->nws->activeAlertsForState((string) $state);
            } catch (Throwable $e) {
                // Logged and retried next hour. Never rethrown: the poll is
                // best-effort by design (AC-NTF-08).
                Log::warning('NWS poll failed.', ['state' => $state, 'error' => $e->getMessage()]);
                $result['errors'][] = "{$state}: {$e->getMessage()}";

                continue;
            }

            foreach ($inState as $property) {
                foreach ($alerts as $alert) {
                    if (! $this->matches($alert, $property)) {
                        continue;
                    }

                    $record = $this->record($property, $alert);

                    if ($record === null) {
                        // Already on file. Seen again next hour and every hour
                        // until it expires, which is the normal case.
                        continue;
                    }

                    $result['alerts']++;
                    $result['notified'] += $this->notifyResidents($property, $record);
                }
            }
        }

        return $result;
    }

    /**
     * An alert an admin issues by hand.  [FR-NTF-03]
     *
     * A burst pipe or a lift out of service is the same shape of thing as a
     * storm — something the building needs to know now — so it travels the same
     * path and lands in the same banner rather than in a second mechanism.
     */
    public function issueManual(
        Property $property,
        string $eventType,
        string $headline,
        ?CarbonImmutable $expiresAt,
        User $actor,
    ): WeatherAlert {
        $alert = $this->record($property, [
            // Namespaced so it can never collide with a real NWS id, and so
            // "who issued this" is answerable from the id alone.
            'id' => 'manual:'.Str::uuid(),
            'event' => $eventType,
            'severity' => 'Severe',
            'headline' => $headline,
            'effective' => now()->toIso8601String(),
            'expires' => $expiresAt?->toIso8601String(),
            'area_desc' => '',
        ]);

        // A fresh uuid every time, so the unique index cannot refuse it. If it
        // somehow does, that is worth hearing about rather than an alert
        // nobody was sent.
        if ($alert === null) {
            throw new RuntimeException('That alert could not be recorded, so nobody was told.');
        }

        $notified = $this->notifyResidents($property, $alert);

        $this->audit->record('weather.alert.issued', $alert, [
            'property_id' => $property->id,
            'event_type' => $eventType,
            'residents_notified' => $notified,
            'issued_by' => $actor->id,
        ]);

        return $alert;
    }

    /**
     * Does this alert cover this property?
     *
     * NWS gives us `areaDesc` — "Fulton; DeKalb; Cobb" — and we hold a county
     * on the property. That is the only geography the two share without a
     * geocoder.
     *
     * **With no county recorded, the state-wide alert is taken as covering the
     * property.** Over-alerting is a real cost — it teaches people to ignore
     * the banner — but a tornado warning that did not arrive is worse than one
     * that did not apply, and the fix is a county in the property record.
     *
     * @param  array<string, mixed>  $alert
     */
    private function matches(array $alert, Property $property): bool
    {
        $county = trim((string) $property->county);

        if ($county === '') {
            return true;
        }

        // "Fulton County" in our record against "Fulton" in theirs, either way
        // round, case-insensitively.
        $needle = Str::of($county)->replaceMatches('/\s+county$/i', '')->trim()->lower();

        return $needle->isNotEmpty()
            && Str::contains(Str::lower((string) $alert['area_desc']), (string) $needle);
    }

    /**
     * Insert, or discover it is already there.
     *
     * @param  array<string, mixed>  $alert
     * @return WeatherAlert|null null when this property already has this alert
     */
    private function record(Property $property, array $alert): ?WeatherAlert
    {
        try {
            $record = new WeatherAlert;
            $record->forceFill([
                'property_id' => $property->id,
                'nws_alert_id' => $alert['id'],
                'event_type' => $alert['event'],
                'severity' => $alert['severity'] ?? null,
                'headline' => $alert['headline'] ?? null,
                'effective_at' => $this->parse($alert['effective'] ?? null),
                'expires_at' => $this->parse($alert['expires'] ?? null),
                'created_at' => now(),
            ])->save();

            return $record;
        } catch (UniqueConstraintViolationException) {
            // AC-NTF-07. The constraint is the deduplication; a check-then-act
            // would race the hourly job against itself.
            return null;
        }
    }

    /** @return int residents notified */
    private function notifyResidents(Property $property, WeatherAlert $alert): int
    {
        $leases = Lease::query()
            ->where('status', Lease::STATUS_ACTIVE)
            ->whereHas('unit', fn ($q) => $q->where('property_id', $property->id))
            ->with('tenant')
            ->get();

        foreach ($leases as $lease) {
            if (! $lease->tenant) {
                continue;
            }

            $this->notifications->send(
                NotificationTemplate::WeatherAlert,
                $lease->tenant->email,
                [
                    'name' => $lease->tenant->fullName(),
                    'eventType' => $alert->event_type,
                    'headline' => $alert->headline,
                    // Long form, as everywhere a tenant reads (UI §8).
                    'expiresAt' => $alert->expires_at?->format('j F Y, g:ia'),
                ],
                tenantId: $lease->tenant->id,
            );
        }

        $alert->forceFill(['notified_at' => now()])->save();

        return $leases->count();
    }

    private function parse(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc()->toDateTimeString();
        } catch (Throwable) {
            // A date we cannot read is not worth losing the alert over.
            return null;
        }
    }
}
