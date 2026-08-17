<?php

namespace App\Domain\Payments;

use Illuminate\Support\Collection;

/**
 * The order a payment is applied to outstanding charges.  [Q-1]
 *
 * A strategy rather than a `match` inside AllocationService, because Q-1 is one
 * of the five blocking client questions and has Georgia dispossessory
 * implications: which charge a payment lands on changes how much *rent* is
 * shown as owed, which is the figure a dispossessory proceeding turns on. The
 * answer may arrive after this code ships, and when it does it must be a
 * configuration change rather than a rewrite of the money path.
 *
 * Implementations sort; they never decide amounts. How much lands on each
 * charge is AllocationService's business, and keeping the two apart is what
 * makes a new ordering a twenty-line class with no arithmetic in it.
 */
interface AllocationOrder
{
    /** The `payment.allocation_order` settings value that selects this order. */
    public function key(): string;

    /** Admin-facing description, so the configured rule can be shown, not guessed at. */
    public function label(): string;

    /**
     * Sort outstanding charges into the order they should be paid.
     *
     * Must be **total** — no ties left to insertion order. Two runs over the
     * same charges have to produce the same allocation, or the same payment
     * lands differently depending on how the rows came back from MySQL.
     *
     * @param  Collection<int, object{id:int, posted_on:string, category:string, outstanding:\App\Support\Money}>  $charges
     * @return Collection<int, object>
     */
    public function sort(Collection $charges): Collection;
}
