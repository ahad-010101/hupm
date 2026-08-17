<?php

namespace App\Domain\Payments\Orders;

use App\Domain\Payments\AllocationOrder;
use Illuminate\Support\Collection;

/**
 * Newest charge first — pay the current period before the arrears.
 *
 * Some landlords apply payments this way so the current month always reads as
 * settled and the arrears stay visible as a distinct, older block. It is the
 * mirror image of the default, which makes it the cheapest proof that Q-1 is
 * genuinely a setting: the same payment over the same charges has to produce a
 * visibly different allocation with no code change.
 */
class NewestChargeFirst implements AllocationOrder
{
    public function key(): string
    {
        return 'newest_charge_first';
    }

    public function label(): string
    {
        return 'Newest charge first';
    }

    public function sort(Collection $charges): Collection
    {
        return $charges->sortByDesc(fn (object $charge) => [$charge->posted_on, $charge->id])->values();
    }
}
