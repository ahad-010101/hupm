<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a DECIMAL(10,2) column to and from App\Support\Money.
 *
 * The boundary where money enters and leaves PHP. Laravel's built-in
 * `decimal:2` cast returns a string that then gets used in arithmetic and
 * quietly becomes a float; this returns a Money and refuses floats outright.
 *
 * @implements CastsAttributes<Money, Money>
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        // PDO hands back DECIMAL as a string, which is exactly what we want.
        return Money::fromString((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            // Catching this here rather than converting it is the point: a float
            // reaching this line means money was computed in floating point
            // somewhere upstream, and the damage is already done (I-10).
            throw new InvalidArgumentException(sprintf(
                'Refusing to store a float in %s::$%s. Use App\Support\Money.',
                $model::class,
                $key,
            ));
        }

        if ($value instanceof Money) {
            return $value->toDecimalString();
        }

        if (is_int($value) || is_string($value)) {
            // An int here means dollars, not minor units — that ambiguity is why
            // Money::fromMinor() is explicit. Parse through fromString() so the
            // same validation applies.
            return Money::fromString((string) $value)->toDecimalString();
        }

        throw new InvalidArgumentException(
            'Money attributes accept App\\Support\\Money or a decimal string, got '.get_debug_type($value).'.'
        );
    }
}
