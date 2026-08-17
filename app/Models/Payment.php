<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A payment attempt.  [DB §A3 `payments`]
 *
 * **A payment row is not money in the ledger.** INVARIANT I-6: a payment reduces
 * a balance only on settlement, never at submission — ACH takes 2–5 business
 * days and can still be returned afterwards. The ledger entry carries the
 * money; this row carries what the gateway said about it.
 *
 * `amount` is always positive here. Direction lives in the ledger entry, which
 * stores a payment negative.
 */
class Payment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_FAILED = 'failed';

    public const STATUS_VOID = 'void';

    /** Writes go through a domain service (I-11); nothing mass-assigns a payment. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'submitted_at' => 'datetime',
            'settled_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The ledger row this payment produced. One payment, one entry. */
    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(LedgerEntry::class);
    }

    /** Which charges this payment paid (FR-LED-03). */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SETTLED;
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SETTLED);
    }
}
