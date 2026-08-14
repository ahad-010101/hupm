<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A door within a property.  [FR-REG-01]
 *
 * `status` is operational rather than derived: a unit can be `vacant` with an
 * ended lease still attached, and `off_market` while perfectly habitable. It is
 * therefore set by an admin, not computed from the lease — WP-07 keeps the two
 * consistent when a lease starts or ends.
 */
class Unit extends Model
{
    use HasFactory;

    public const STATUS_OCCUPIED = 'occupied';

    public const STATUS_VACANT = 'vacant';

    public const STATUS_OFF_MARKET = 'off_market';

    protected $fillable = [
        'unit_number',
        'bedrooms',
        'bathrooms',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'bedrooms' => 'integer',
            // DECIMAL(3,1) — half-baths are real. This is not money, so a
            // string cast keeps it exact without involving App\Support\Money.
            'bathrooms' => 'string',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /** RESTRICT on leases: a unit with any tenancy history stays. */
    public function isDeletable(): bool
    {
        return ! $this->leases()->exists();
    }
}
