<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Every business date in the system resolves here.  [DEVIATION D-07]
 *
 * Timestamps are stored in UTC (APP_TIMEZONE=UTC). Business dates are not
 * timestamps: "rent is due on the 1st", "day 5 past due", "the grace period
 * expired", "that date is in the future" are all statements about the calendar
 * in Georgia, and they must be evaluated there.
 *
 * The failure this prevents: the scheduler runs every minute, so a charge or
 * delinquency job fires at 00:30 UTC — which is 19:30 or 20:30 the *previous
 * day* in New York. Evaluated naively, every date-sensitive rule in the system
 * is off by one for four to five hours out of every twenty-four, and the bug
 * only shows up in production at night.
 *
 * The specs never state a timezone; this is derived, and the company timezone
 * is a setting rather than a constant so a future property outside Georgia is
 * a configuration change.
 */
class BusinessCalendar
{
    public function __construct(private readonly Settings $settings) {}

    /** The operating timezone — `America/New_York` unless configured otherwise. */
    public function timezone(): string
    {
        return $this->settings->string('company.timezone', 'America/New_York');
    }

    /** Now, in the company timezone. */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /** Today's business date. The single most important method here. */
    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    /** Interpret a stored UTC timestamp as a business date. */
    public function toBusinessDate(DateTimeInterface $utc): CarbonImmutable
    {
        return CarbonImmutable::instance($utc)->setTimezone($this->timezone())->startOfDay();
    }

    /** Convert a business-local moment back to UTC for storage. */
    public function toUtc(DateTimeInterface $local): CarbonImmutable
    {
        return CarbonImmutable::instance($local)->setTimezone('UTC');
    }

    /**
     * The date rent falls due for a given period.
     *
     * rent_due_day is capped at 28 in the schema precisely so this never has to
     * decide what "the 31st of February" means.
     *
     * @param  string  $period  'YYYY-MM'
     */
    public function dueDateFor(string $period, int $rentDueDay): CarbonImmutable
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        return CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->timezone())
            ->addDays(min($rentDueDay, 28) - 1);
    }

    /**
     * The last day on which payment is still within the grace period.
     *
     * Inclusive: with a due date of the 1st and five grace days, the 6th is the
     * first late day, so grace expires at the end of the 5th.
     */
    public function graceExpiry(CarbonImmutable $dueDate, int $graceDays): CarbonImmutable
    {
        return $dueDate->startOfDay()->addDays($graceDays);
    }

    /** Whether a charge is past due as of today, honouring the grace period. */
    public function isPastDue(CarbonImmutable $dueDate, int $graceDays): bool
    {
        return $this->today()->greaterThan($this->graceExpiry($dueDate, $graceDays));
    }

    /** Whole days past the grace expiry; 0 while still within grace. */
    public function daysPastDue(CarbonImmutable $dueDate, int $graceDays): int
    {
        $expiry = $this->graceExpiry($dueDate, $graceDays);
        $today = $this->today();

        return $today->greaterThan($expiry) ? $expiry->diffInDays($today) : 0;
    }

    /** A date is "future" if it is after today in the company timezone. */
    public function isFuture(DateTimeInterface $date): bool
    {
        return $this->toBusinessDate($date)->greaterThan($this->today());
    }

    /** @param string $period 'YYYY-MM' */
    public function daysInPeriod(string $period): int
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        return CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->timezone())->daysInMonth;
    }

    /** The period string a date belongs to. */
    public function periodFor(DateTimeInterface $date): string
    {
        return $this->toBusinessDate($date)->format('Y-m');
    }

    /** The current period, in the company timezone. */
    public function currentPeriod(): string
    {
        return $this->today()->format('Y-m');
    }
}
