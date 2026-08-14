<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An NWS alert issued against a property's ZIP.
 *
 * Minimal for now — WP-21 builds the hourly poll and the notification. Exists
 * here so Property::weatherAlerts() resolves.
 *
 * Deduplication is a database constraint, not application logic: the unique
 * index on (property_id, nws_alert_id) is what guarantees a tenant is never
 * emailed twice for one storm (AC-NTF-07).
 */
class WeatherAlert extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'expires_at' => 'datetime',
            'notified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
