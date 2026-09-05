<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring non-rent charge on one lease.  [FR-CHG-01, WP-10]
 *
 * The table has existed since WP-10 and the nightly job has always posted from
 * it; what it never had until WP-41 was a way to create one. `ChargePostingService`
 * still reads it through the query builder, so this model is for the admin side
 * only and changes nothing about how charges post.
 *
 * The columns here are **authoritative**, not a cache of the charge type. A
 * schedule copies its type's values at creation and keeps them, so editing the
 * type later cannot alter what a resident is already being billed.
 */
class LeaseChargeSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'lease_id',
        'charge_type_id',
        'category',
        'description',
        'amount',
        'payer',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'active' => 'boolean',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
