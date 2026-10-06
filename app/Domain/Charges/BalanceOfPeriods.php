<?php

namespace App\Domain\Charges;

use App\Models\Lease;
use App\Support\BusinessCalendar;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use RuntimeException;

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
    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly Settings $settings,
    ) {}

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

        // [WP-51] The opening period: the month this system became the record.
        //
        // These leases run back to 2007, and "what is due" taken literally
        // means every month since — 3,474 rows of rent nobody is going to
        // collect, against a system that was not keeping the books at the
        // time. The earlier periods belong to whatever did.
        //
        // A floor rather than a rewritten `start_date`, because a lease's
        // start date is a fact about a tenancy and not ours to edit. Proration
        // still keys on the real start, so a lease beginning mid-month inside
        // the opening period is charged from the day they moved in.
        $floor = $this->openingPeriodStart($tz);

        if ($floor !== null && $floor->greaterThan($cursor)) {
            $cursor = $floor;
        }

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

    /**
     * The first month this system is the record for, or null for all of time.
     *
     * Refuses a malformed value rather than ignoring it. Treating a typo as
     * "no floor" would quietly back-post every month since 2007 on the next
     * nightly run, which is exactly the outcome the setting exists to prevent
     * — and the job catches this per lease and reports it (AC-CHG-04).
     */
    private function openingPeriodStart(string $tz): ?CarbonImmutable
    {
        $value = trim($this->settings->string('charges.post_from_period', ''));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            throw new RuntimeException(
                "charges.post_from_period is '{$value}', which is not a YYYY-MM month. "
                .'Refusing to post rather than guessing at the boundary.'
            );
        }

        return CarbonImmutable::parse($value.'-01', $tz)->startOfDay();
    }
}
