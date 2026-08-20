<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Somebody has seen this."  [AC-ADM-02, D-25]
 *
 * A record of attention, not of money. Nothing here changes what happened to a
 * payment — it only takes the item off the panel so what remains is what still
 * needs doing.
 */
class ExceptionAcknowledgement extends Model
{
    /** Written through ExceptionFeed::acknowledge(), never mass-assigned (I-11). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }
}
