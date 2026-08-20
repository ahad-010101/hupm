<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One resident, one notice, one delivery outcome.  [AC-NTF-04, AC-NTF-05]
 *
 * A row per recipient rather than a count on the notice, because "we sent it to
 * everyone" is not an answer to "did Mrs Pouros get it". The statuses mirror
 * `notification_logs` so a bounce means the same thing in both places —
 * including `not_deliverable`, which is a resident with no email address on
 * file rather than a failure (Q-4, AC-NTF-03).
 */
class NoticeRecipient extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
