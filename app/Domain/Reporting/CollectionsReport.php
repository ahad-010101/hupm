<?php

namespace App\Domain\Reporting;

use App\Domain\Ledger\LedgerService;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What was charged against what came in, month by month.  [FR-ADM-02]
 *
 * Twelve months back, so a collection rate has something to be read against.
 * A single month's figure answers nothing — 94% is good or bad entirely
 * depending on whether last month was 99% or 88%.
 *
 * Counted by **posting date**: rent paid in August for July is money collected
 * in August, which is the question a cash-flow report is asked. The rent roll
 * asks the other question — how much of July's rent was ever met — and reads
 * from the allocations instead.
 */
class CollectionsReport
{
    private const MONTHS = 12;

    public function __construct(private readonly BusinessCalendar $calendar) {}

    public function build(): Report
    {
        $today = $this->calendar->today();
        $from = $today->startOfMonth()->subMonths(self::MONTHS - 1);

        $charged = $this->totalsByPeriod('charge', $from);
        $collected = $this->collectedByMonth($from);

        $rows = [];
        $totalCharged = Money::zero();
        $totalCollected = Money::zero();

        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $from->addMonthsNoOverflow($i);
            $period = $month->format('Y-m');

            $due = $charged[$period] ?? Money::zero();
            $in = $collected[$period] ?? Money::zero();

            if ($due->isZero() && $in->isZero()) {
                continue;
            }

            $totalCharged = $totalCharged->plus($due);
            $totalCollected = $totalCollected->plus($in);

            $rows[] = [
                'month' => $month->format('F Y'),
                'charged' => (string) $due,
                'collected' => (string) $in,
                'shortfall' => (string) $due->minus($in),
                'rate' => $this->rate($in, $due),
            ];
        }

        return new Report(
            key: 'collections',
            title: 'Collections',
            subtitle: 'Twelve months to '.$today->format('F Y'),
            columns: [
                ['key' => 'month', 'label' => 'Month'],
                ['key' => 'charged', 'label' => 'Charged', 'money' => true],
                ['key' => 'collected', 'label' => 'Collected', 'money' => true],
                ['key' => 'shortfall', 'label' => 'Shortfall', 'money' => true],
                ['key' => 'rate', 'label' => 'Rate'],
            ],
            rows: $rows,
            totals: [
                'month' => 'Total',
                'charged' => (string) $totalCharged,
                'collected' => (string) $totalCollected,
                'shortfall' => (string) $totalCharged->minus($totalCollected),
                'rate' => $this->rate($totalCollected, $totalCharged),
            ],
            notes: [
                'Charged counts both obligations — the resident portion and the housing '
                    .'authority portion — because a collection rate that ignored the subsidy '
                    .'would describe a fifth of the rent roll.',
                'Collected counts money on the day it cleared, not the day it was submitted. '
                    .'The current month will always look low until its payments settle.',
            ],
        );
    }

    /**
     * @return array<string, Money>
     */
    private function totalsByPeriod(string $type, CarbonImmutable $from): array
    {
        $rows = DB::table('ledger_entries')
            ->select('period', DB::raw('SUM(amount) as total'))
            ->whereIn('type', $type === 'charge' ? ['charge', 'adjustment'] : [$type])
            ->whereIn('status', LedgerService::BALANCE_AFFECTING)
            ->whereNotNull('period')
            ->where('period', '>=', $from->format('Y-m'))
            ->groupBy('period')
            ->get();

        $byPeriod = [];

        foreach ($rows as $row) {
            $byPeriod[$row->period] = Money::fromString((string) ($row->total ?: '0'));
        }

        return $byPeriod;
    }

    /**
     * Payments by the month they cleared, keyed the same way as the charges.
     *
     * A payment carries the period of the charge it was made for, if any, so
     * this reads `posted_on` instead — the date the money landed.
     *
     * @return array<string, Money>
     */
    private function collectedByMonth(CarbonImmutable $from): array
    {
        $rows = DB::table('ledger_entries')
            ->select(DB::raw("DATE_FORMAT(posted_on, '%Y-%m') as month"), DB::raw('SUM(amount) as total'))
            ->where('type', 'payment')
            ->whereIn('status', LedgerService::BALANCE_AFFECTING)
            ->where('posted_on', '>=', $from->toDateString())
            ->groupBy('month')
            ->get();

        $byMonth = [];

        foreach ($rows as $row) {
            // Stored negative; presented as money received.
            $byMonth[$row->month] = Money::fromString((string) ($row->total ?: '0'))->absolute();
        }

        return $byMonth;
    }

    /**
     * Collected as a percentage of charged.
     *
     * Integer arithmetic on minor units. A percentage is exactly the place a
     * float slips into a money path, and this class is on one (I-10).
     */
    private function rate(Money $collected, Money $charged): string
    {
        if (! $charged->isPositive()) {
            return '—';
        }

        return intdiv($collected->minor * 200 + $charged->minor, $charged->minor * 2).'%';
    }
}
