<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named reason to charge a resident.  [WP-41, FR-CHG-01]
 *
 * "Garbage collection", "Pest control", "Lawn maintenance". Picking one fills
 * in the wording and the amount; both stay editable, because the type is a
 * starting point and not a rule.
 *
 * The values are **copied** into a schedule or a batch, never joined at posting
 * time. Editing this row changes what the next one is created with and leaves
 * every existing schedule alone — a resident's monthly charge must not change
 * because somebody edited a dropdown.
 */
class ChargeType extends Model
{
    use HasFactory;

    /** The two the ledger will accept from a hand-posted charge. */
    public const CATEGORIES = [
        'utility' => 'Utility — water, gas, refuse',
        'other' => 'Other — maintenance, fees, anything else',
    ];

    protected $fillable = [
        'name',
        'category',
        'default_description',
        'default_amount',
        'payer',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'default_amount' => MoneyCast::class,
            'active' => 'boolean',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LeaseChargeSchedule::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ChargeBatch::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Whether this type may be removed outright.
     *
     * Mirrors Property and Unit: anything that has actually been used is
     * permanent, and the way to take it out of circulation is `active = false`.
     * Deleting a type that billed somebody would leave a ledger row nobody can
     * explain.
     */
    public function isDeletable(): bool
    {
        return ! $this->schedules()->exists() && ! $this->batches()->exists();
    }
}
