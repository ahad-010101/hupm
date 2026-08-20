<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a notice.  [D-23]
 *
 * Stored once and served to whoever is on the recipient list, rather than
 * copied into each resident's vault — the bytes are identical for all of them,
 * and three 10 MB attachments to twenty-six residents is 780 MB of the same
 * file on a host with a disk quota.
 */
class NoticeAttachment extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }
}
