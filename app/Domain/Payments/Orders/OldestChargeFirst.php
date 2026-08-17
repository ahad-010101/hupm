<?php

namespace App\Domain\Payments\Orders;

use App\Domain\Payments\AllocationOrder;
use Illuminate\Support\Collection;

/**
 * Oldest charge first — the shipped default for Q-1.
 *
 * The conventional rule: money pays down the oldest thing owed, whatever it is.
 * It is what AC-LED-05 describes, and it is the order that keeps a ledger
 * readable — charges clear in the order they appeared, so the outstanding list
 * is always a contiguous tail rather than a scatter.
 *
 * The tie-break is the entry id, which is insertion order and therefore stable.
 * Rent, a utility and a fee can all carry the same `posted_on`; without a
 * second key MySQL is free to return them in any order and the same payment
 * would land differently on two runs.
 */
class OldestChargeFirst implements AllocationOrder
{
    public function key(): string
    {
        return 'oldest_charge_first';
    }

    public function label(): string
    {
        return 'Oldest charge first';
    }

    public function sort(Collection $charges): Collection
    {
        return $charges->sortBy(fn (object $charge) => [$charge->posted_on, $charge->id])->values();
    }
}
