<?php

use App\Support\Money;

/*
|--------------------------------------------------------------------------
| Money  [WP-02 DoD, INVARIANT I-10]
|--------------------------------------------------------------------------
*/

it('round-trips every DECIMAL(10,2) form exactly', function (string $input, int $minor, string $output) {
    $money = Money::fromString($input);

    expect($money->minor)->toBe($minor)
        ->and($money->toDecimalString())->toBe($output);
})->with([
    ['0.00', 0, '0.00'],
    ['0', 0, '0.00'],
    ['300', 30000, '300.00'],
    ['300.5', 30050, '300.50'],
    ['300.50', 30050, '300.50'],
    ['1234.56', 123456, '1234.56'],
    ['1,234.56', 123456, '1234.56'],
    ['$1,234.56', 123456, '1234.56'],
    ['-42.07', -4207, '-42.07'],
    ['0.01', 1, '0.01'],
    ['99999999.99', 9999999999, '99999999.99'],
]);

it('refuses input it would have to round', function () {
    // Silent rounding is how money disappears. A third decimal place means the
    // caller's assumption is wrong, not that we should guess.
    expect(fn () => Money::fromString('10.005'))->toThrow(InvalidArgumentException::class);
});

it('rejects malformed and out-of-range amounts', function (string $input) {
    expect(fn () => Money::fromString($input))->toThrow(InvalidArgumentException::class);
})->with(['', 'abc', '1.2.3', '--5', '100000000.00']);

it('adds and subtracts without drift across many operations', function () {
    // The canonical float failure: 0.1 + 0.2 !== 0.3.
    $tenCents = Money::fromString('0.10');
    $twentyCents = Money::fromString('0.20');

    expect($tenCents->plus($twentyCents)->toDecimalString())->toBe('0.30');

    // A thousand additions of a third of a dollar must land exactly.
    $total = Money::zero();
    for ($i = 0; $i < 1000; $i++) {
        $total = $total->plus(Money::fromString('0.33'));
    }
    expect($total->toDecimalString())->toBe('330.00');
});

it('never creates or loses a cent when allocating', function (int $minor, array $ratios) {
    $money = Money::fromMinor($minor);
    $parts = $money->allocate($ratios);

    $sum = array_sum(array_map(fn (Money $m) => $m->minor, $parts));

    expect($sum)->toBe($minor)
        ->and($parts)->toHaveCount(count($ratios));
})->with(function () {
    // Property-based: deterministic seed so a failure is reproducible.
    mt_srand(20260813);
    $cases = [];

    // The awkward ones first — indivisible amounts across equal parts.
    $cases[] = [100, [1, 1, 1]];      // 100 / 3
    $cases[] = [1, [1, 1]];           // one cent, two ways
    $cases[] = [-100, [1, 1, 1]];     // a credit splits like a charge
    $cases[] = [0, [1, 1]];
    $cases[] = [99999999_99, [7, 11, 13]];

    for ($i = 0; $i < 200; $i++) {
        $minor = mt_rand(-500_000, 500_000);
        $parts = mt_rand(2, 6);
        $ratios = [];
        for ($p = 0; $p < $parts; $p++) {
            $ratios[] = mt_rand(1, 50);
        }
        $cases[] = [$minor, $ratios];
    }

    return $cases;
});

it('allocates the remainder to the earliest parts', function () {
    // Deterministic order matters: allocating a payment across charges must be
    // reproducible, not dependent on hash order (Q-1).
    $parts = Money::fromString('1.00')->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m) => $m->toDecimalString(), $parts))
        ->toBe(['0.34', '0.33', '0.33']);
});

it('prorates with half-up rounding', function (string $amount, int $numerator, int $denominator, string $expected) {
    expect(Money::fromString($amount)->prorate($numerator, $denominator)->toDecimalString())->toBe($expected);
})->with([
    // 15 days of a 30-day month.
    ['900.00', 15, 30, '450.00'],
    // 10 of 31 days: 290.3225… rounds to 290.32.
    ['900.00', 10, 31, '290.32'],
    // Exactly half a cent rounds up.
    ['0.01', 1, 2, '0.01'],
    ['0.03', 1, 2, '0.02'],
]);

it('formats with the symbol and two decimals always', function (string $input, string $expected) {
    expect(Money::fromString($input)->format())->toBe($expected);
})->with([
    ['0', '$0.00'],
    ['5', '$5.00'],
    ['300.5', '$300.50'],
    ['1234.56', '$1,234.56'],
    ['1234567.89', '$1,234,567.89'],
    ['-300', '-$300.00'],
]);

it('serialises to a string so no float reaches the frontend', function () {
    $encoded = json_encode(['amount' => Money::fromString('300.00')]);

    expect($encoded)->toBe('{"amount":"300.00"}');
});

it('reports sign correctly, since a negative balance is a credit', function () {
    expect(Money::fromString('-1.00')->isNegative())->toBeTrue()
        ->and(Money::fromString('1.00')->isPositive())->toBeTrue()
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and(Money::zero()->isNegative())->toBeFalse();
});

it('sums a list exactly, which is how a balance is computed', function () {
    $entries = [
        Money::fromString('300.00'),   // rent charge
        Money::fromString('25.00'),    // late fee
        Money::fromString('-300.00'),  // payment
    ];

    expect(Money::sum($entries)->toDecimalString())->toBe('25.00');
});

it('refuses an amount larger than the column can hold', function () {
    expect(fn () => Money::fromMinor(10_000_000_000))->toThrow(InvalidArgumentException::class);
});
