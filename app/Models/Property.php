<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A building or address.  [FR-REG-01]
 *
 * No soft deletes — DB §A4 applies those to tenants and vendors only. A
 * property with units cannot be deleted at all (RESTRICT), which in practice
 * means any property in use is permanent.
 */
class Property extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'country_code',
        'street_address',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'county',
        'notes',
    ];

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function weatherAlerts(): HasMany
    {
        return $this->hasMany(WeatherAlert::class);
    }

    /** A property may only be removed while it has no units (DB §A4, RESTRICT). */
    public function isDeletable(): bool
    {
        return ! $this->units()->exists();
    }

    /**
     * A single-line address.
     *
     * Country is appended only when it is not the US: printing "United States"
     * on twenty-five Atlanta addresses is noise, and its absence is the signal
     * that nothing unusual is going on.
     */
    public function fullAddress(): string
    {
        $parts = array_filter([
            $this->street_address,
            $this->address_line_2,
            $this->city,
            trim("{$this->state} {$this->postal_code}"),
            $this->country_code === 'US' ? null : $this->country_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * WP-21 polls the National Weather Service, which covers the United States
     * only. A property outside it has no alerts available — the reason has to
     * be visible rather than looking like a bug in the job.
     */
    public function supportsWeatherAlerts(): bool
    {
        return $this->country_code === 'US';
    }
}
