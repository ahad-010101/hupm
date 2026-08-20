<?php

namespace App\Domain\Fees;

use App\Domain\Ledger\BalanceCalculator;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Which late fees are due, and for how much.  [FR-FEE-01, BR-06…BR-09]
 *
 * Decides; does not write. Kept apart from LateFeeService because "what does
 * this lease owe in fees today" is a question worth being able to ask without
 * charging anybody — an admin previewing, a test, a dry run before the client
 * turns automation on.
 *
 * Three rules do all the work:
 *
 *   BR-06 / I-7  Fees attach to the **tenant** obligation and never to the
 *                Housing Authority portion. A subsidised tenant whose $500
 *                share is unpaid is late; the authority's $700 has nothing to
 *                do with it, and vice versa (AC-FEE-01, AC-FEE-04).
 *   BR-07        Every parameter comes from the lease. There is no
 *                portfolio-wide fee anywhere in this class.
 *   BR-08        Cumulative fees for a period stop at the lease maximum.
 */
class LateFeeCalculator
{
    public function __construct(
        private readonly BalanceCalculator $balances,
        private readonly BusinessCalendar $calendar,
    ) {}

    /**
     * Fees this lease has earned but not yet been charged.
     *
     * @return list<object{key:string, period:string, amount:Money, postedOn:CarbonImmutable, kind:string, description:string}>
     */
    public function due(Lease $lease, ?CarbonImmutable $asOf = null): array
    {
        $asOf = $this->normalise($asOf ?? $this->calendar->today());

        if ($lease->status !== Lease::STATUS_ACTIVE) {
            return [];
        }

        $flat = $lease->late_fee_flat ?? Money::zero();
        $daily = $lease->late_fee_daily ?? Money::zero();

        if (! $flat->isPositive() && ! $daily->isPositive()) {
            // A lease with no fee configured is not an oversight to correct.
            return [];
        }

        $dueDay = min((int) $lease->rent_due_day, 28);
        $graceDays = (int) $lease->grace_period_days;
        $existing = $this->existingKeys($lease);
        $fees = [];

        // Only the TENANT payer is ever consulted (BR-06). The authority's
        // outstanding balance is not read anywhere in this method.
        foreach ($this->balances->outstandingByPeriod($lease->tenant_id, 'tenant') as $period => $outstanding) {
            $graceExpiry = $this->calendar->graceExpiry(
                $this->calendar->dueDateFor($period, $dueDay),
                $graceDays,
            );

            if ($asOf->lessThanOrEqualTo($this->normalise($graceExpiry))) {
                // Still within grace. AC-FEE-04 is this line: paid or not, a
                // period inside its grace window is not late.
                continue;
            }

            $alreadyCharged = $this->feesChargedFor($lease, $period);
            $room = $this->headroom($lease, $alreadyCharged);

            if ($room !== null && ! $room->isPositive()) {
                // AC-FEE-02. The cap is reached; nothing further for this period.
                continue;
            }

            foreach ($this->candidates($lease, $period, $graceExpiry, $asOf, $flat, $daily) as $candidate) {
                if (in_array($candidate->key, $existing, true)) {
                    // BR-09. Already charged — the key is the idempotency.
                    continue;
                }

                $amount = $room === null ? $candidate->amount : Money::min($candidate->amount, $room);

                if (! $amount->isPositive()) {
                    break;
                }

                $candidate->amount = $amount;
                $fees[] = $candidate;

                if ($room !== null) {
                    $room = $room->minus($amount);

                    if (! $room->isPositive()) {
                        break;
                    }
                }
            }
        }

        return $fees;
    }

    /**
     * The flat fee, then one row per elapsed day.
     *
     * Days are generated from grace expiry to today rather than from "yesterday
     * to today", so a job that did not run for three days charges the three days
     * it missed rather than losing them. The fee is owed under the lease, not
     * under our cron (the same reasoning as AC-CHG-04).
     *
     * @return list<object{key:string, period:string, amount:Money, postedOn:CarbonImmutable, kind:string, description:string}>
     */
    private function candidates(
        Lease $lease,
        string $period,
        CarbonImmutable $graceExpiry,
        CarbonImmutable $asOf,
        Money $flat,
        Money $daily,
    ): array {
        $month = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->format('F Y');
        $candidates = [];

        if ($flat->isPositive()) {
            $candidates[] = (object) [
                // Exactly the format DB §A3 records for this key (D-01).
                'key' => "{$lease->id}:latefee_flat:{$period}",
                'period' => $period,
                'amount' => $flat,
                'postedOn' => $this->normalise($graceExpiry)->addDay(),
                'kind' => 'flat',
                'description' => "Late fee — {$month}",
            ];
        }

        if (! $daily->isPositive()) {
            return $candidates;
        }

        $day = $this->normalise($graceExpiry)->addDay();

        // Bounded at a year: a lease left unpaid for longer is a legal matter,
        // not a loop that should keep running every night.
        for ($i = 0; $i < 366 && $day->lessThanOrEqualTo($asOf); $i++) {
            $candidates[] = (object) [
                'key' => "{$lease->id}:latefee_daily:{$day->toDateString()}",
                'period' => $period,
                'amount' => $daily,
                'postedOn' => $day,
                'kind' => 'daily',
                'description' => "Daily late fee — {$month} ({$day->format('j M')})",
            ];

            $day = $day->addDay();
        }

        return $candidates;
    }

    /**
     * How much more may be charged for this period.  [BR-08, AC-FEE-02]
     *
     * NULL means the lease sets no maximum. That is the spec's meaning of an
     * empty `late_fee_max`, and it is worth saying out loud that an uncapped
     * lease with a daily fee accrues without limit — see the note in the plan.
     */
    private function headroom(Lease $lease, Money $alreadyCharged): ?Money
    {
        $max = $lease->late_fee_max;

        if (! $max instanceof Money || ! $max->isPositive()) {
            return null;
        }

        return $max->minus($alreadyCharged);
    }

    /** What has already been charged in fees for one period. */
    private function feesChargedFor(Lease $lease, string $period): Money
    {
        $total = LedgerEntry::query()
            ->where('lease_id', $lease->id)
            ->where('period', $period)
            ->where('category', 'late_fee')
            ->where('payer', 'tenant')
            ->whereIn('status', ['posted', 'cleared'])
            ->sum('amount');

        return Money::fromString((string) ($total ?: '0'));
    }

    /** @return list<string> */
    private function existingKeys(Lease $lease): array
    {
        return LedgerEntry::query()
            ->where('lease_id', $lease->id)
            ->where('category', 'late_fee')
            ->whereNotNull('charge_key')
            ->pluck('charge_key')
            ->all();
    }

    /**
     * Every date compared as a business-timezone midnight (D-07).
     *
     * Mixing a UTC midnight with a Georgia one puts the same calendar day five
     * hours apart, which is how WP-10 briefly stopped posting anything at all.
     */
    private function normalise(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d'), $this->calendar->timezone())->startOfDay();
    }
}
