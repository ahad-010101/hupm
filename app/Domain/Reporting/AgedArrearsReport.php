<?php

namespace App\Domain\Reporting;

use App\Domain\Ledger\LedgerService;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How old the money owed is.  [FR-ADM-02]
 *
 * Buckets of 0–30 / 31–60 / 61–90 / 90+ days, aged from the day each charge was
 * posted. Age is what turns "they owe $600" into a decision: a resident one
 * cycle behind is a phone call, and one four cycles behind is a different
 * conversation entirely.
 *
 * **Credits are shown, never netted into a bucket.** A resident carrying a
 * credit has no arrears to age, and folding their negative balance into the
 * 0–30 column would quietly reduce what the portfolio is owed by residents who
 * are genuinely behind. The buckets add up to gross arrears; the credit line
 * sits beneath them and the two reconcile to the net figure the dashboard
 * shows.
 */
class AgedArrearsReport
{
    /** @var list<array{key: string, label: string, from: int, to: ?int}> */
    private const BUCKETS = [
        ['key' => 'b0', 'label' => '0–30 days', 'from' => 0, 'to' => 30],
        ['key' => 'b31', 'label' => '31–60 days', 'from' => 31, 'to' => 60],
        ['key' => 'b61', 'label' => '61–90 days', 'from' => 61, 'to' => 90],
        ['key' => 'b90', 'label' => '90+ days', 'from' => 91, 'to' => null],
    ];

    public function __construct(private readonly BusinessCalendar $calendar) {}

    public function build(): Report
    {
        $today = $this->calendar->today();
        $rows = [];
        $totals = array_fill_keys(array_column(self::BUCKETS, 'key'), Money::zero());
        $grandTotal = Money::zero();
        $creditTotal = Money::zero();

        foreach ($this->outstandingByTenant() as $tenantId => $tenant) {
            $buckets = array_fill_keys(array_column(self::BUCKETS, 'key'), Money::zero());
            $owed = Money::zero();

            foreach ($tenant['charges'] as $charge) {
                // `posted_on` is a DATE column and comes back as a string. It
                // is already a business date — it was written as one — so it is
                // parsed, not converted from UTC (D-07).
                $postedOn = CarbonImmutable::parse($charge['posted_on']);
                $age = (int) $postedOn->diffInDays($today);
                $key = $this->bucketFor($age);

                $buckets[$key] = $buckets[$key]->plus($charge['outstanding']);
                $owed = $owed->plus($charge['outstanding']);
            }

            $credit = $tenant['credit'];
            $creditTotal = $creditTotal->plus($credit);

            if (! $owed->isPositive() && ! $credit->isPositive()) {
                continue;
            }

            foreach ($buckets as $key => $amount) {
                $totals[$key] = $totals[$key]->plus($amount);
            }

            $grandTotal = $grandTotal->plus($owed);

            $rows[] = [
                'tenant' => $tenant['name'],
                'b0' => (string) $buckets['b0'],
                'b31' => (string) $buckets['b31'],
                'b61' => (string) $buckets['b61'],
                'b90' => (string) $buckets['b90'],
                'owed' => (string) $owed,
                'credit' => (string) $credit,
                'net' => (string) $owed->minus($credit),
            ];
        }

        usort($rows, fn (array $a, array $b) => Money::fromString($b['net'])->compareTo(Money::fromString($a['net'])));

        return new Report(
            key: 'aged-arrears',
            title: 'Aged arrears',
            subtitle: 'Resident obligations as at '.$today->format('j F Y'),
            columns: [
                ['key' => 'tenant', 'label' => 'Resident'],
                ['key' => 'b0', 'label' => '0–30 days', 'money' => true],
                ['key' => 'b31', 'label' => '31–60 days', 'money' => true],
                ['key' => 'b61', 'label' => '61–90 days', 'money' => true],
                ['key' => 'b90', 'label' => '90+ days', 'money' => true],
                ['key' => 'owed', 'label' => 'Total owed', 'money' => true],
                ['key' => 'credit', 'label' => 'Credit held', 'money' => true],
                ['key' => 'net', 'label' => 'Net', 'money' => true, 'balance' => true],
            ],
            rows: $rows,
            totals: [
                'tenant' => 'Total',
                'b0' => (string) $totals['b0'],
                'b31' => (string) $totals['b31'],
                'b61' => (string) $totals['b61'],
                'b90' => (string) $totals['b90'],
                'owed' => (string) $grandTotal,
                'credit' => (string) $creditTotal,
                'net' => (string) $grandTotal->minus($creditTotal),
            ],
            notes: [
                'Buckets add up to the total owed. Credits held are listed separately and '
                    .'subtracted once at the end, so a resident in credit never reduces what '
                    .'somebody else is behind.',
                'Age runs from the day each charge was posted, resolved in '
                    .$this->calendar->timezone().'.',
                'Housing authority obligations are excluded. That is a separate account under '
                    .'the HAP contract, not resident arrears.',
            ],
        );
    }

    /**
     * Every unmet resident charge, and any credit sitting on the account.
     *
     * One query for the charges rather than one per resident: this is the only
     * report that has to look at every outstanding line in the portfolio, and
     * asking 26 times is the shape that becomes 260 later.
     *
     * @return array<int, array{name: string, charges: list<array{posted_on: string, outstanding: Money}>, credit: Money}>
     */
    private function outstandingByTenant(): array
    {
        $charges = DB::table('ledger_entries as e')
            ->leftJoin('payment_allocations as a', function ($join) {
                $join->on('a.charge_entry_id', '=', 'e.id')->whereNull('a.reversed_at');
            })
            ->join('tenants as t', 't.id', '=', 'e.tenant_id')
            ->where('e.payer', 'tenant')
            ->whereIn('e.type', ['charge', 'adjustment'])
            ->whereIn('e.status', LedgerService::BALANCE_AFFECTING)
            ->groupBy('e.id', 'e.tenant_id', 'e.posted_on', 'e.amount', 't.first_name', 't.last_name')
            ->orderBy('e.posted_on')
            ->get([
                'e.tenant_id',
                'e.posted_on',
                'e.amount',
                't.first_name',
                't.last_name',
                DB::raw('COALESCE(SUM(a.amount), 0) as allocated'),
            ]);

        $byTenant = [];

        foreach ($charges as $row) {
            $tenantId = (int) $row->tenant_id;
            $outstanding = Money::fromString((string) $row->amount)
                ->minus(Money::fromString((string) $row->allocated));

            $byTenant[$tenantId] ??= [
                'name' => trim("{$row->first_name} {$row->last_name}"),
                'charges' => [],
                'credit' => Money::zero(),
            ];

            if ($outstanding->isPositive()) {
                $byTenant[$tenantId]['charges'][] = [
                    'posted_on' => (string) $row->posted_on,
                    'outstanding' => $outstanding,
                ];
            } elseif ($outstanding->isNegative()) {
                // A credit adjustment posted against no charge of its own.
                $byTenant[$tenantId]['credit'] = $byTenant[$tenantId]['credit']->plus($outstanding->absolute());
            }
        }

        // Money paid beyond what was owed sits unallocated (Q-8 credit_forward)
        // and is a credit on the account rather than an overpaid charge.
        foreach ($this->unallocatedCredit() as $tenantId => $credit) {
            if (! isset($byTenant[$tenantId])) {
                continue;
            }

            $byTenant[$tenantId]['credit'] = $byTenant[$tenantId]['credit']->plus($credit);
        }

        return $byTenant;
    }

    /** @return array<int, Money> */
    private function unallocatedCredit(): array
    {
        $rows = DB::table('ledger_entries as e')
            ->leftJoin('payment_allocations as a', function ($join) {
                $join->on('a.payment_id', '=', 'e.payment_id')->whereNull('a.reversed_at');
            })
            ->where('e.payer', 'tenant')
            ->where('e.type', 'payment')
            ->whereIn('e.status', LedgerService::BALANCE_AFFECTING)
            ->groupBy('e.id', 'e.tenant_id', 'e.amount')
            ->get([
                'e.tenant_id',
                'e.amount',
                DB::raw('COALESCE(SUM(a.amount), 0) as allocated'),
            ]);

        $byTenant = [];

        foreach ($rows as $row) {
            // Payments are stored negative; what was paid is its absolute value.
            $paid = Money::fromString((string) $row->amount)->absolute();
            $spare = $paid->minus(Money::fromString((string) $row->allocated));

            if (! $spare->isPositive()) {
                continue;
            }

            $tenantId = (int) $row->tenant_id;
            $byTenant[$tenantId] = ($byTenant[$tenantId] ?? Money::zero())->plus($spare);
        }

        return $byTenant;
    }

    private function bucketFor(int $ageInDays): string
    {
        foreach (self::BUCKETS as $bucket) {
            if ($ageInDays >= $bucket['from'] && ($bucket['to'] === null || $ageInDays <= $bucket['to'])) {
                return $bucket['key'];
            }
        }

        // Anything older than the last named range belongs in it.
        return 'b90';
    }
}
