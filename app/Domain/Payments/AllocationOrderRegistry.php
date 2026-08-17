<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Orders\NewestChargeFirst;
use App\Domain\Payments\Orders\OldestChargeFirst;
use App\Domain\Payments\Orders\RentBeforeFees;
use App\Support\Settings;
use InvalidArgumentException;

/**
 * Resolves `payment.allocation_order` to an AllocationOrder.  [Q-1]
 */
class AllocationOrderRegistry
{
    /** @var list<class-string<AllocationOrder>> */
    private const ORDERS = [
        OldestChargeFirst::class,
        RentBeforeFees::class,
        NewestChargeFirst::class,
    ];

    public const DEFAULT = 'oldest_charge_first';

    public function __construct(private readonly Settings $settings) {}

    /** The order currently configured. */
    public function current(): AllocationOrder
    {
        return $this->make($this->settings->string('payment.allocation_order', self::DEFAULT));
    }

    /**
     * **Throws on an unrecognised key rather than falling back to the default.**
     *
     * A typo in the settings table must not quietly change how money is applied.
     * Falling back would apply every payment by a rule nobody chose, silently,
     * for as long as it took someone to notice — and the way it would be
     * noticed is a tenant disputing their balance. Failing at the first payment
     * is a support ticket; failing open is a reconciliation.
     */
    public function make(string $key): AllocationOrder
    {
        foreach (self::ORDERS as $class) {
            /** @var AllocationOrder $order */
            $order = new $class;

            if ($order->key() === $key) {
                return $order;
            }
        }

        throw new InvalidArgumentException(sprintf(
            "'%s' is not a known payment allocation order. Expected one of: %s.",
            $key,
            implode(', ', self::keys()),
        ));
    }

    /**
     * Every selectable order, for a settings screen and for validation.
     *
     * @return array<string, string> key => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::ORDERS as $class) {
            /** @var AllocationOrder $order */
            $order = new $class;
            $options[$order->key()] = $order->label();
        }

        return $options;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::options());
    }
}
