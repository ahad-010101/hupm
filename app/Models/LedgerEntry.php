<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Concerns\Immutable;
use App\Domain\Ledger\LedgerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A row in the single financial table.
 *
 * INVARIANT I-2: only App\Domain\Ledger\LedgerService may write this table.
 * An architecture test enforces that; this model is deliberately not fillable,
 * so a controller cannot mass-assign its way past the service (I-11).
 *
 * INVARIANT I-3: immutable except `status`. A correction is a reversing entry
 * pointing at the original through `reverses_entry_id`, never an edit and never
 * a delete (BR-04). What was posted is a matter of record even when it was
 * wrong — especially when it was wrong.
 */
class LedgerEntry extends Model
{
    use Immutable;

    /** No mass assignment. Construction goes through LedgerService. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'posted_on' => 'date',
        ];
    }

    /**
     * Settlement moves a payment entry pending → cleared; a return moves it
     * cleared → returned. Nothing else about a posted entry may change.
     */
    protected function mutableAttributes(): array
    {
        return ['status'];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The entry this one reverses, if any (AC-LED-08). */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /** The reversal posted against this entry, if one exists. Both stay visible. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** BR-05: only these statuses move a balance. */
    public function scopeAffectingBalance(Builder $query): Builder
    {
        return $query->whereIn('status', LedgerService::BALANCE_AFFECTING);
    }

    public function affectsBalance(): bool
    {
        return in_array($this->status, LedgerService::BALANCE_AFFECTING, true);
    }
}
