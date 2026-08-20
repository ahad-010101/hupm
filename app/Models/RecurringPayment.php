<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing instruction to debit a tenant monthly.  [FR-PAY-03]
 *
 * The debit job itself is WP-16. What exists here is the record and its
 * suspension, because **delinquency has to be able to stop autopay the moment
 * an account enters Management Review** (BR-11), and that cannot wait for the
 * job that reads it.
 *
 * `suspended` is deliberately distinct from `cancelled`: suspension is
 * something the system did and an admin may undo; cancellation is something the
 * tenant chose. Collapsing them would make "did they cancel, or did we stop
 * it?" unanswerable.
 *
 * Release from Management Review does **not** resume this (AC-DEL-06). Nothing
 * anywhere sets `active` except an explicit human action.
 */
class RecurringPayment extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = ['*'];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function paymentProfile(): BelongsTo
    {
        return $this->belongsTo(PaymentProfile::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
