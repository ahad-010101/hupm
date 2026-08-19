<?php

namespace App\Models;

use App\Concerns\Immutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a ticket.  [FR-MNT-02, AC-MNT-05]
 *
 * Append-only, like the ledger and for the same reason: the history of a
 * repair is evidence. Who said the boiler was fixed, and when, is the question
 * that matters three months later when it is not.
 *
 * `visible_to_tenant` is false for the internal notes an admin writes to
 * themselves — and always false for anything touching vendor cost (AC-MNT-09).
 */
class MaintenanceEvent extends Model
{
    use Immutable;

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'visible_to_tenant' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function scopeVisibleToTenant(Builder $query): Builder
    {
        return $query->where('visible_to_tenant', true);
    }
}
