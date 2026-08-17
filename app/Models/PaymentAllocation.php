<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Concerns\Immutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which payment paid which charge.  [FR-LED-03, DB §A3]
 *
 * A join table rather than implied arithmetic, so the waterfall is auditable:
 * you can point at a charge and say which payment cleared it, and at a payment
 * and say where it went. "The balance is $300" is not an answer a tenant can
 * check; "your $200 paid January's fee and $150 of February's rent" is.
 *
 * Immutable except `reversed_at` (D-02). A returned payment un-allocates by
 * stamping that timestamp, never by deleting the row — the allocation is
 * evidence of what happened and has to survive being undone.
 */
class PaymentAllocation extends Model
{
    use Immutable;

    /** Writes go through AllocationService only. */
    protected $guarded = ['*'];

    /** The table carries `created_at` alone; there is nothing to update. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'reversed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** The one thing about an allocation that may change. */
    protected function mutableAttributes(): array
    {
        return ['reversed_at'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function chargeEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'charge_entry_id');
    }

    /** Allocations that still count. A reversed one is history, not money. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
