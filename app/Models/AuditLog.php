<?php

namespace App\Models;

use App\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;

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
}
