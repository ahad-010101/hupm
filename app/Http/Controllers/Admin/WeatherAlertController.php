<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notifications\NwsClient;
use App\Domain\Notifications\WeatherAlertService;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\WeatherAlert;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weather and emergency alerts.  [FR-NTF-03, WP-21]
 *
 * Two sources, one list: what the National Weather Service issued, and what an
 * admin issued by hand. A burst pipe is the same shape of thing as a storm —
 * something the building needs to know now — so it lands in the same banner
 * rather than in a second mechanism nobody remembers exists.
 */
class WeatherAlertController extends Controller
{
    public function __construct(
        private readonly WeatherAlertService $weather,
        private readonly NwsClient $nws,
    ) {}

    public function index(): Response
    {
        $alerts = WeatherAlert::query()
            ->with('property:id,name,county,state')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->subDays(7)))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('Admin/Alerts/Index', [
            'alerts' => $alerts->map(fn (WeatherAlert $alert) => [
                'id' => $alert->id,
                'property' => $alert->property?->name,
                'event' => $alert->event_type,
                'severity' => $alert->severity,
                'headline' => $alert->headline,
                'expires_on' => $alert->expires_at?->format('j M Y, g:ia'),
                'notified_on' => $alert->notified_at?->format('j M Y, g:ia'),
                'live' => $alert->expires_at === null || $alert->expires_at->isFuture(),
                // Namespaced ids are ours; anything else came from NWS.
                'manual' => str_starts_with((string) $alert->nws_alert_id, 'manual:'),
            ])->all(),
            'properties' => Property::orderBy('name')->get(['id', 'name', 'county', 'country_code'])
                ->map(fn (Property $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    // Surfaced because a property with no county is alerted for
                    // anything state-wide, which is noisier than it needs to be.
                    'county' => $p->county,
                    'weather_capable' => $p->supportsWeatherAlerts(),
                ]),
        ]);
    }

    /** An admin-issued emergency or service interruption. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'event_type' => ['required', 'string', 'max:100'],
            'headline' => ['required', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ], [
            'headline.required' => 'Say what is happening and what residents should do.',
            'expires_at.after' => 'An alert that has already expired will not reach anyone.',
        ]);

        $alert = $this->weather->issueManual(
            Property::findOrFail($validated['property_id']),
            $validated['event_type'],
            $validated['headline'],
            isset($validated['expires_at']) ? CarbonImmutable::parse($validated['expires_at']) : null,
            $request->user(),
        );

        return back()->with('status', "Alert issued and residents notified: {$alert->event_type}.");
    }

    /** Poll now rather than waiting for the hour. */
    public function poll(): RedirectResponse
    {
        // Drop the 60-minute cache first, or "poll now" would replay the last
        // answer and look broken.
        foreach (Property::distinct()->pluck('state') as $state) {
            $this->nws->forget((string) $state);
        }

        $result = $this->weather->poll();

        $message = sprintf(
            '%d new %s, %d residents notified.',
            $result['alerts'],
            $result['alerts'] === 1 ? 'alert' : 'alerts',
            $result['notified'],
        );

        return $result['errors'] === []
            ? back()->with('status', $message)
            : back()->with('error', $message.' The weather service could not be reached for: '
                .implode('; ', array_slice($result['errors'], 0, 3)));
    }
}
