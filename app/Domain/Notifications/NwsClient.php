<?php

namespace App\Domain\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The National Weather Service.  [TDD §9.4, FR-NTF-03]
 *
 * Free, no key, and it asks for a descriptive User-Agent instead — NWS blocks
 * anonymous clients, so the contact address below is not politeness, it is
 * authentication of a sort.
 *
 * **There is no ZIP endpoint.** The spec says "poll by property ZIP", and NWS
 * retired ZIP lookup: an alert is scoped to zones and counties, and going from
 * a ZIP to a zone needs a geocoder we do not have and would not add for this.
 * So the poll is per state — one request covering all twenty-five properties —
 * and alerts are matched to a property by county, which is a column we already
 * hold. See matchesProperty() on WeatherAlertPoller for what happens when a
 * property has no county recorded.
 *
 * Cached 60 minutes (TDD §7), which is also the poll interval: a second call
 * inside the hour is a bug somewhere else and should not cost an outbound
 * request on a shared host.
 */
class NwsClient
{
    private const BASE = 'https://api.weather.gov';

    private const CACHE_MINUTES = 60;

    private const TIMEOUT_SECONDS = 15;

    /**
     * Active alerts for one US state.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException when NWS cannot be reached or answers badly
     */
    public function activeAlertsForState(string $state): array
    {
        $state = strtoupper(trim($state));

        return Cache::remember(
            "nws.alerts.{$state}",
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->fetch($state),
        );
    }

    /** Drop the cache, so an admin's manual re-poll actually re-polls. */
    public function forget(string $state): void
    {
        Cache::forget('nws.alerts.'.strtoupper(trim($state)));
    }

    /** @return list<array<string, mixed>> */
    private function fetch(string $state): array
    {
        try {
            $response = Http::withHeaders([
                    // NWS requires this and will 403 without it. An address they
                    // can write to is the point, not the product name.
                    'User-Agent' => sprintf(
                        '%s (%s)',
                        config('app.name', 'HUPM'),
                        config('mail.from.address', 'admin@example.com'),
                    ),
                    'Accept' => 'application/geo+json',
                ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->retry(2, 500, throw: false)
                ->get(self::BASE.'/alerts/active', ['area' => $state]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Could not reach the National Weather Service: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new RuntimeException("The National Weather Service answered {$response->status()}.");
        }

        $features = $response->json('features');

        if (! is_array($features)) {
            throw new RuntimeException('The National Weather Service returned something unreadable.');
        }

        return array_values(array_filter(array_map(
            fn ($feature) => $this->normalise($feature),
            $features,
        )));
    }

    /**
     * GeoJSON to the handful of fields the schema holds.
     *
     * @param  mixed  $feature
     * @return array<string, mixed>|null
     */
    private function normalise($feature): ?array
    {
        if (! is_array($feature) || ! isset($feature['properties'])) {
            return null;
        }

        $p = $feature['properties'];
        $id = (string) ($p['id'] ?? $feature['id'] ?? '');

        if ($id === '') {
            // Without an id there is no deduplication, and without
            // deduplication a tenant is emailed every hour until the storm
            // passes (AC-NTF-07). Better to skip it.
            Log::warning('Skipped an NWS alert with no id.');

            return null;
        }

        return [
            'id' => substr($id, 0, 200),
            'event' => substr((string) ($p['event'] ?? 'Weather alert'), 0, 100),
            'severity' => substr((string) ($p['severity'] ?? ''), 0, 50) ?: null,
            'headline' => substr((string) ($p['headline'] ?? $p['description'] ?? ''), 0, 500) ?: null,
            'effective' => $p['effective'] ?? $p['onset'] ?? null,
            'expires' => $p['expires'] ?? $p['ends'] ?? null,
            // "Fulton; DeKalb; Cobb". The only geography in the payload that a
            // county column can be matched against without a geocoder.
            'area_desc' => (string) ($p['areaDesc'] ?? ''),
        ];
    }
}
