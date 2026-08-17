<?php

namespace App\Domain\Payments\Orders;

use App\Domain\Payments\AllocationOrder;
use Illuminate\Support\Collection;

/**
 * Rent first, then everything else — oldest within each group.
 *
 * The alternative answer to Q-1, and the one with teeth. Under
 * `oldest_charge_first` a tenant who pays part of what they owe can have their
 * money absorbed by fees while the rent stays outstanding, and rent
 * outstanding is the figure a Georgia dispossessory turns on. Applying to rent
 * first means a partial payment reduces the amount that could be sued for;
 * applying to fees first means it does not.
 *
 * That is a decision for the client and their attorney, not for this codebase.
 * Both orders exist, both are tested, and the setting chooses.
 */
class RentBeforeFees implements AllocationOrder
{
    public function key(): string
    {
        return 'rent_before_fees';
    }

    public function label(): string
    {
        return 'Rent first, then fees and other charges';
    }

    public function sort(Collection $charges): Collection
    {
        return $charges
            ->sortBy(fn (object $charge) => [
                $charge->category === 'rent' ? 0 : 1,
                $charge->posted_on,
                $charge->id,
            ])
            ->values();
    }
}
