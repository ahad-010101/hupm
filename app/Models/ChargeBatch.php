<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bulk one-off posting.  [WP-41]
 *
 * The batch holds no money. Each charge is an ordinary ledger row keyed
 * `{lease}:batch{id}`; this records that they went out together, by whom, and
 * whether they were taken back.
 *
 * It exists so a mistake is one action to undo rather than twenty-six.
 */
class ChargeBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'charge_type_id',
        'description',
        'amount',
        'payer',
        'lease_count',
        'total_amount',
        'posted_by_user_id',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'total_amount' => MoneyCast::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /**
     * The ledger rows this batch created.
     *
     * A query rather than a relation: the association lives in the shape of
     * `charge_key` (`{lease}:batch{id}`), and no Eloquent relation can express
     * a suffix match. Adding a `charge_batch_id` column to `ledger_entries`
     * would be the alternative, and the ledger is deliberately hard to add
     * columns to — it is the one table where a second source of truth about
     * money is worst.
     *
     * The `%` is anchored on both sides by the literal `:batch` and the exact
     * id, so batch 1 cannot match batch 12.
     *
     * @return Builder<LedgerEntry>
     */
    public function entries()
    {
        return LedgerEntry::query()->where('charge_key', 'like', '%:batch'.$this->id);
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
