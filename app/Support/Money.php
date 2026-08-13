<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact monetary amount, held as an integer number of minor units (cents).
 *
 * INVARIANT I-10: money is never a PHP float. Not in a constructor, not in an
 * intermediate calculation, not in a JSON cast, not in `format()`. 0.1 + 0.2 is
 * not 0.3 in binary floating point, and a ledger that is out by a cent is a
 * ledger nobody trusts. Every operation here is integer arithmetic or string
 * manipulation; an architecture test asserts no float cast exists on this path.
 *
 * Amounts are signed, matching `ledger_entries.amount`: charges positive,
 * payments and credits negative. That is what makes a balance a plain SUM.
 *
 * Immutable — every operation returns a new instance.
 */
final class Money implements JsonSerializable, Stringable
{
    /** DECIMAL(10,2) tops out here; guards against silent overflow. */
    private const MAX_MINOR = 9_999_999_999;

    private function __construct(
        public readonly int $minor,
    ) {
        if ($minor > self::MAX_MINOR || $minor < -self::MAX_MINOR) {
            throw new InvalidArgumentException(
                "Amount {$minor} exceeds what DECIMAL(10,2) can store."
            );
        }
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** The canonical constructor: an exact count of cents. */
    public static function fromMinor(int $minor): self
    {
        return new self($minor);
    }

    /**
     * Parse a decimal string such as '300.00', '-42.5' or '1,234.56'.
     *
     * This is the only entry point for values arriving from the database, a
     * form, or a CSV import. It deliberately rejects anything with more than
     * two decimal places rather than rounding: silent rounding is how money
     * disappears, and a third decimal place means the caller's assumption is
     * wrong, not that we should guess.
     */
    public static function fromString(string $value): self
    {
        $value = str_replace([',', ' ', '$'], '', trim($value));

        if (! preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("'{$value}' is not a valid monetary amount.");
        }

        $fraction = $m['fraction'] ?? '';

        if (strlen($fraction) > 2) {
            throw new InvalidArgumentException(
                "'{$value}' has more than two decimal places; refusing to round silently."
            );
        }

        $minor = (int) $m['whole'] * 100 + (int) str_pad($fraction, 2, '0');

        return new self($m['sign'] === '-' ? -$minor : $minor);
    }

    public function plus(self $other): self
    {
        return new self($this->minor + $other->minor);
    }

    public function minus(self $other): self
    {
        return new self($this->minor - $other->minor);
    }

    public function times(int $multiplier): self
    {
        return new self($this->minor * $multiplier);
    }

    public function negate(): self
    {
        return new self(-$this->minor);
    }

    public function absolute(): self
    {
        return new self(abs($this->minor));
    }

    /**
     * Split across the given integer ratios without creating or losing a cent.
     *
     * Largest-remainder: each part gets its floor share, then the leftover cents
     * are handed out one at a time from the first part. The sum of the results
     * always equals the original exactly — which is the property that matters
     * when allocating a payment across charges (Q-1) or prorating rent.
     *
     * @param  list<int>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('Cannot allocate across zero parts.');
        }

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new InvalidArgumentException('Allocation ratios must not be negative.');
            }
        }

        $totalRatio = array_sum($ratios);

        if ($totalRatio === 0) {
            throw new InvalidArgumentException('Allocation ratios must not sum to zero.');
        }

        // Work on the magnitude and re-apply the sign, so a negative amount
        // (a credit) distributes the same way a positive one does.
        $sign = $this->minor < 0 ? -1 : 1;
        $magnitude = abs($this->minor);

        $shares = [];
        $distributed = 0;

        foreach ($ratios as $ratio) {
            $share = intdiv($magnitude * $ratio, $totalRatio);
            $shares[] = $share;
            $distributed += $share;
        }

        $remainder = $magnitude - $distributed;

        for ($i = 0; $remainder > 0; $i = ($i + 1) % count($shares)) {
            $shares[$i]++;
            $remainder--;
        }

        return array_map(fn (int $share) => new self($sign * $share), $shares);
    }

    /**
     * Take numerator/denominator of this amount, rounding half up.
     *
     * Used for proration (Q-10 sets the convention). Kept separate from
     * allocate() because proration takes a single share of a whole rather than
     * splitting a whole into parts, so there is no remainder to redistribute.
     */
    public function prorate(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Cannot prorate by zero.');
        }

        $negatives = (int) ($this->minor < 0) + (int) ($numerator < 0) + (int) ($denominator < 0);
        $sign = $negatives % 2 === 1 ? -1 : 1;
        $product = abs($this->minor) * abs($numerator);
        $divisor = abs($denominator);

        // Half up, without touching a float: add half the divisor before
        // integer division.
        $result = intdiv($product * 2 + $divisor, $divisor * 2);

        return new self($sign * $result);
    }

    public function compareTo(self $other): int
    {
        return $this->minor <=> $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        return $this->minor < $other->minor;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    /** A negative balance is a credit. The tenant UI must render it as such. */
    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public static function min(self $a, self $b): self
    {
        return $a->minor <= $b->minor ? $a : $b;
    }

    public static function max(self $a, self $b): self
    {
        return $a->minor >= $b->minor ? $a : $b;
    }

    /** @param list<self> $items */
    public static function sum(array $items): self
    {
        return array_reduce($items, fn (self $carry, self $i) => $carry->plus($i), self::zero());
    }

    /** The database representation: '300.00'. Never a float. */
    public function toDecimalString(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $magnitude = abs($this->minor);

        return $sign.intdiv($magnitude, 100).'.'.str_pad((string) ($magnitude % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Display form: always the symbol, always two decimals.
     *
     * Built by string concatenation rather than number_format(), which takes a
     * float parameter and would put one on the money path.
     *
     * Note this renders a negative as "-$300.00". Rendering a credit as
     * "Credit $300.00" in green is a presentation decision that belongs to the
     * view layer, because it applies only to tenant-facing balances.
     */
    public function format(string $symbol = '$'): string
    {
        $magnitude = abs($this->minor);
        $whole = self::withThousandsSeparators(intdiv($magnitude, 100));
        $cents = str_pad((string) ($magnitude % 100), 2, '0', STR_PAD_LEFT);

        return ($this->minor < 0 ? '-' : '').$symbol.$whole.'.'.$cents;
    }

    /**
     * Thousands separators for an integer.
     *
     * number_format() would do this, but its first parameter is a float — using
     * it here would put a float on the money path and trip the architecture
     * test, for the sake of formatting.
     */
    private static function withThousandsSeparators(int $value): string
    {
        return strrev(implode(',', str_split(strrev((string) $value), 3)));
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    /** Serialises to a string for Inertia props, never to a float. */
    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }
}
