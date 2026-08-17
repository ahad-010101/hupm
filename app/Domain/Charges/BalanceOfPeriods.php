<?php

namespace App\Domain\Charges;

use App\Models\Lease;
use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;

/**
 * Which periods a lease should have been charged for by a given date.
 *
 * Separated from the posting itself because "what is due" is a calendar
 * question and "what gets written" is a ledger question, and the calendar
 * question is the one with all the awkward cases: a lease that starts
 * mid-month, a run that was missed for two days, a due day that falls before
 * the tenancy began.
 *
 * Every date resolves through BusinessCalendar in the company timezone (D-07).
 * Evaluated in UTC, a job running at 01:00 would decide "today" is the
 * previous day in Georgia for five hours out of every twenty-four.
 */
class BalanceOfPeriods
{
    public function __construct(private readonly BusinessCalendar $calendar) {}

    /**
     * Periods this lease owes a charge for, oldest first.
     *
     * Includes everything from the lease's first period up to `asOf`, so a run
     * after a missed day catches up without a separate code path — the ordinary
     * answer to "what is due" already includes what was due yesterday
     * (AC-CHG-04).
     *
     * @return list<object{period:string, postedOn:CarbonImmutable, prorated:bool, daysCharged:int, daysInPeriod:int}>
     */
    public function duePeriodsFor(Lease $lease, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= $this->calendar->today();

        // Every date is normalised to midnight in the BUSINESS timezone before
        // anything is compared. Mixing a UTC midnight with a Georgia midnight
        // makes the same calendar day look five hours apart — which reads as
        // "not due yet" for every charge, every run. Comparing dates as dates
        // removes the class of bug rather than one instance of it.
        $tz = $this->calendar->timezone();
        $asOf = CarbonImmutable::parse($asOf->format('Y-m-d'), $tz)->startOfDay();
        $start = CarbonImmutable::parse($lease->start_date->format('Y-m-d'), $tz)->startOfDay();
        $end = CarbonImmutable::parse($lease->end_date->format('Y-m-d'), $tz)->startOfDay();
        $dueDay = min((int) $lease->rent_due_day, 28);

        $periods = [];
        $cursor = $start->startOfMonth();

        // A lease running past `asOf` simply stops there; one that ended stops
        // at its end date. Charges never post beyond either.
        $horizon = $asOf->lessThan($end) ? $asOf : $end;

        while ($cursor->lessThanOrEqualTo($horizon)) {
            $period = $cursor->format('Y-m');
            $dueDate = $this->calendar->dueDateFor($period, $dueDay);
            $daysInPeriod = $cursor->daysInMonth;

            // The tenancy may begin after the due day, in which case this
            // period is partial and is charged from the day they move in.
            $prorated = $start->greaterThan($dueDate) && $start->isSameMonth($cursor);
            $postedOn = $prorated ? $start : $dueDate;

            // Plain arithmetic rather than diffInDays: Carbon 3 returns a
            // SIGNED difference, and the wrong operand order produced negative
            // days here — which made the prorated amount negative, which
            // postCharge then declined to post at all. A charge that silently
            // does not happen is the worst possible failure for this job.
            //
            // Move-in on the 15th of a 31-day month is charged for 17 days:
            // the 15th through the 31st, inclusive of both.
            $daysCharged = $prorated
                ? $daysInPeriod - $start->day + 1
                : $daysInPeriod;

            // Not yet due, or the tenancy had not begun. Either way, nothing to
            // post — and because the loop runs oldest-first, nothing after this
            // is due either.
            if ($postedOn->greaterThan($asOf)) {
                break;
            }

            if ($postedOn->greaterThanOrEqualTo($start) && $postedOn->lessThanOrEqualTo($end)) {
                $periods[] = (object) [
                    'period' => $period,
                    'postedOn' => $postedOn,
                    'prorated' => $prorated,
                    'daysCharged' => (int) $daysCharged,
                    'daysInPeriod' => $daysInPeriod,
                ];
            }

            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $periods;
    }
}
