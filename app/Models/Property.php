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
        'street_address',
        'city',
        'state',
        'zip',
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

    public function fullAddress(): string
    {
        return "{$this->street_address}, {$this->city}, {$this->state} {$this->zip}";
    }
}
