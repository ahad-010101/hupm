<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agreed plan to clear arrears.  [FR-ARR-02, BR-19]
 *
 * The money columns are **snapshots taken when the agreement was written**, not
 * live figures. `remaining_balance` is a term of the agreement — what the
 * parties agreed was outstanding on the day they signed — and it deliberately
 * does not track the ledger afterwards. Recomputing it would quietly rewrite a
 * signed document, and it would breach I-1 into the bargain.
 */
class PaymentArrangement extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_SIGNATURE = 'pending_signature';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DEFAULTED = 'defaulted';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'total_owed' => MoneyCast::class,
            'amount_accepted' => MoneyCast::class,
            'remaining_balance' => MoneyCast::class,
            'schedule_json' => 'array',
            'late_fees_continue' => 'boolean',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** An arrangement a tenant may actually pay under. */
    public function isLive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
