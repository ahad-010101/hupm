<?php

use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| BusinessCalendar  [WP-02 DoD, DEVIATION D-07]
|--------------------------------------------------------------------------
|
| In 2026 US daylight saving begins 8 March (02:00 EST → 03:00 EDT) and ends
| 1 November (02:00 EDT → 01:00 EST). EST is UTC-5, EDT is UTC-4.
|
| The bug being guarded against is not exotic: the scheduler runs every minute,
| so a charge or delinquency job routinely fires while the UTC date and the
| Georgia date disagree. Evaluated in UTC, every date-sensitive rule is wrong
| for the last four or five hours of each Georgia day.
|
*/

beforeEach(function () {
    $this->calendar = app(BusinessCalendar::class);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('defaults to America/New_York', function () {
    expect($this->calendar->timezone())->toBe('America/New_York');
});

it('resolves the business date, not the UTC date, late in the evening', function () {
    // 23:30 in Georgia on 7 March — but already the 8th in UTC.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-08T04:30:00Z'));

    expect($this->calendar->today()->toDateString())->toBe('2026-03-07')
        ->and(CarbonImmutable::now('UTC')->toDateString())->toBe('2026-03-08');
});

it('handles the spring-forward boundary in both directions', function (string $utc, string $expectedDate, string $expectedLocalTime) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse($utc));

    expect($this->calendar->today()->toDateString())->toBe($expectedDate)
        ->and($this->calendar->now()->format('H:i'))->toBe($expectedLocalTime);
})->with([
    // Still EST (UTC-5): 23:30 on the 7th.
    'before the jump' => ['2026-03-08T04:30:00Z', '2026-03-07', '23:30'],
    // Last moment of EST: 01:30 on the 8th.
    'minutes before 2am' => ['2026-03-08T06:30:00Z', '2026-03-08', '01:30'],
    // Now EDT (UTC-4): 03:30 — 02:00–02:59 never existed locally.
    'after the jump' => ['2026-03-08T07:30:00Z', '2026-03-08', '03:30'],
]);

it('handles the fall-back boundary, including the repeated hour', function (string $utc, string $expectedDate, string $expectedLocalTime) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse($utc));

    expect($this->calendar->today()->toDateString())->toBe($expectedDate)
        ->and($this->calendar->now()->format('H:i'))->toBe($expectedLocalTime);
})->with([
    // First pass through 01:30, still EDT (UTC-4).
    'first 1:30am, EDT' => ['2026-11-01T05:30:00Z', '2026-11-01', '01:30'],
    // Second pass through 01:30, now EST (UTC-5). Same wall clock, one hour later.
    'second 1:30am, EST' => ['2026-11-01T06:30:00Z', '2026-11-01', '01:30'],
    // 23:30 on 1 November while UTC has already rolled to the 2nd.
    'late evening, UTC ahead' => ['2026-11-02T04:30:00Z', '2026-11-01', '23:30'],
]);

it('computes the rent due date for a period', function () {
    expect($this->calendar->dueDateFor('2026-09', 1)->toDateString())->toBe('2026-09-01')
        ->and($this->calendar->dueDateFor('2026-09', 15)->toDateString())->toBe('2026-09-15')
        // rent_due_day is capped at 28 in the schema so February always exists.
        ->and($this->calendar->dueDateFor('2026-02', 28)->toDateString())->toBe('2026-02-28');
});

it('expires grace at the end of the last grace day', function () {
    $due = $this->calendar->dueDateFor('2026-09', 1);

    // Due on the 1st with five grace days: the 6th is the first late day.
    expect($this->calendar->graceExpiry($due, 5)->toDateString())->toBe('2026-09-06');
});

it('treats the grace day itself as not yet past due', function () {
    $due = $this->calendar->dueDateFor('2026-09', 1);

    // Noon in Georgia on the 6th — the last day of grace.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06T16:00:00Z'));
    expect($this->calendar->isPastDue($due, 5))->toBeFalse()
        ->and($this->calendar->daysPastDue($due, 5))->toBe(0);

    // Noon on the 7th — now late by one day.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07T16:00:00Z'));
    expect($this->calendar->isPastDue($due, 5))->toBeTrue()
        ->and($this->calendar->daysPastDue($due, 5))->toBe(1);
});

it('does not tip into past due early just because UTC has rolled over', function () {
    $due = $this->calendar->dueDateFor('2026-09', 1);

    // 20:30 on the 6th in Georgia; already the 7th in UTC. Evaluated in UTC this
    // would post a late fee a day early — to every tenant, every month.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07T00:30:00Z'));

    expect(CarbonImmutable::now('UTC')->toDateString())->toBe('2026-09-07')
        ->and($this->calendar->today()->toDateString())->toBe('2026-09-06')
        ->and($this->calendar->isPastDue($due, 5))->toBeFalse();
});

it('reports the period a date belongs to in local terms', function () {
    // 19:30 on 30 September in Georgia; 1 October in UTC.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01T00:30:00Z'));

    expect($this->calendar->currentPeriod())->toBe('2026-09');
});

it('counts the days in a period', function () {
    expect($this->calendar->daysInPeriod('2026-02'))->toBe(28)
        ->and($this->calendar->daysInPeriod('2024-02'))->toBe(29)
        ->and($this->calendar->daysInPeriod('2026-09'))->toBe(30);
});

it('judges "future" against the Georgia calendar', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07T00:30:00Z')); // 6 Sept, 20:30 local

    expect($this->calendar->isFuture(CarbonImmutable::parse('2026-09-07T12:00:00Z')))->toBeTrue()
        // Still "today" locally, so not a future date — a payment dated now must
        // not be rejected as future-dated.
        ->and($this->calendar->isFuture(CarbonImmutable::parse('2026-09-06T16:00:00Z')))->toBeFalse();
});
