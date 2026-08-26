<?php

namespace App\Models;

use App\Concerns\Immutable;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An audit row. Append-only, no exceptions (AC-AUD-02).
 *
 * Read through this model; write through App\Support\AuditLogger, which uses
 * the query builder so audit writes cannot themselves fire model events.
 */
class AuditLog extends Model
{
    use Immutable;

    public $timestamps = false; // created_at only, set on insert

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The person who acted, or null for the system.
     *
     * Read-side only — {@see AuditLogger} writes `user_id` through
     * the query builder and never touches this. The foreign key is
     * `nullOnDelete`, so a deleted admin leaves their rows behind as
     * system-attributed rather than taking the trail with them.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
