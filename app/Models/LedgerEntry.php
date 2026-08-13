<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A row in the single financial table.
 *
 * INVARIANT I-2: only App\Domain\Ledger\LedgerService may write this table.
 * An architecture test enforces that; this model is deliberately not fillable,
 * so a controller cannot mass-assign its way past the service (I-11).
 *
 * INVARIANT I-3: immutable except `status`. A correction is a reversing entry
 * pointing at the original through `reverses_entry_id`, never an edit and never
 * a delete (BR-04). What was posted is a matter of record even when it was
 * wrong — especially when it was wrong.
 */
class LedgerEntry extends Model
{
    use Immutable;

    /** No mass assignment. Construction goes through LedgerService. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'posted_on' => 'date',
        ];
    }

    /**
     * Settlement moves a payment entry pending → cleared; a return moves it
     * cleared → returned. Nothing else about a posted entry may change.
     */
    protected function mutableAttributes(): array
    {
        return ['status'];
    }
}
