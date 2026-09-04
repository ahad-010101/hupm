<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
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

    /**
     * The two methods a tenant can start online.  [WP-39]
     *
     * The `method` enum carries more than these — cheque, money order, cash,
     * bank transfer, ha_remittance — but those are admin-recorded and never
     * reach the gateway. These are the two the portal offers.
     */
    public const METHOD_ECHECK = 'echeck';

    public const METHOD_CARD = 'card';

    /** Writes go through a domain service (I-11); nothing mass-assigns a payment. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            // NULL for every eCheck and for every payment written before WP-39,
            // so this reads back as null and not as zero. Use fee() rather than
            // the attribute wherever arithmetic follows.
            'convenience_fee' => MoneyCast::class,
            'submitted_at' => 'datetime',
            'settled_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    /**
     * The convenience fee as money rather than as a maybe.  [WP-39]
     *
     * NULL and zero mean the same thing here — no fee — and every caller wants
     * a Money it can add. Keeping the `?? zero` in one place stops it being
     * forgotten in the one place it matters.
     */
    public function fee(): Money
    {
        return $this->convenience_fee ?? Money::zero();
    }

    /**
     * What of this payment went to rent, as opposed to the fee.  [D-28]
     *
     * `amount` is the gateway total. This is the other half of that sentence,
     * and having it named stops the subtraction being open-coded.
     */
    public function rentPortion(): Money
    {
        return $this->amount->minus($this->fee());
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
