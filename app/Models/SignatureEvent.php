<?php

namespace App\Models;

use App\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One link in the signature evidence chain.
 *
 * Fully immutable — no attribute may change and no row may be deleted. This
 * table is the entire legal weight behind a first-party e-signature: who
 * clicked what, on which exact document bytes, from which address, at which
 * millisecond. A chain that can be edited proves nothing.
 */
class SignatureEvent extends Model
{
    use Immutable;

    public $timestamps = false; // occurred_at is the only time, at millisecond precision

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
