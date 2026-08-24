<?php

namespace App\Domain\Reporting;

use App\Domain\Ledger\LedgerService;
use App\Models\Lease;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What was charged for one month, and how much of it came in.  [FR-ADM-02, AC-ADM-03]
 *
 * **The charged column is read from the ledger, never from the lease.** That is
 * the whole of AC-ADM-03: the report total has to equal the sum of charges
 * posted in that period. Taking the figure from `leases.total_contract_rent`
 * would be close enough to look right and wrong whenever a tenancy started
 * mid-month, ended mid-month, or carried a utility schedule — the cases where
 * somebody actually checks.
 */
class RentRollReport
{
    public function __construct(private readonly BusinessCalendar $calendar) {}

    public function build(?string $period = null): Report
    {
        $period ??= $this->calendar->currentPeriod();

        $charges = $this->chargesIn($period);
        $paid = $this->paidAgainst($period);

        $rows = [];
        $totalCharged = Money::zero();
        $totalTenant = Money::zero();
        $totalHa = Money::zero();
        $totalPaid = Money::zero();

        $leases = Lease::with(['tenant:id,first_name,last_name', 'unit:id,unit_number,property_id', 'unit.property:id,name'])
            ->whereIn('id', array_keys($charges))
            ->orderBy('id')
            ->get();

        foreach ($leases as $lease) {
            $row = $charges[$lease->id];
            $tenantCharged = $row['tenant'];
            $haCharged = $row['housing_authority'];
            $charged = $tenantCharged->plus($haCharged);
            $received = $paid[$lease->id] ?? Money::zero();

            $totalCharged = $totalCharged->plus($charged);
            $totalTenant = $totalTenant->plus($tenantCharged);
            $totalHa = $totalHa->plus($haCharged);
            $totalPaid = $totalPaid->plus($received);

            $rows[] = [
                'tenant' => $lease->tenant?->fullName() ?? '—',
                'unit' => $this->unitLabel($lease),
                'tenant_charged' => (string) $tenantCharged,
                'ha_charged' => (string) $haCharged,
                'charged' => (string) $charged,
                'received' => (string) $received,
                'outstanding' => (string) $charged->minus($received),
            ];
        }

        return new Report(
            key: 'rent-roll',
            title: 'Rent roll',
            subtitle: $this->calendar->dueDateFor($period, 1)->format('F Y'),
            columns: [
                ['key' => 'tenant', 'label' => 'Resident'],
                ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'tenant_charged', 'label' => 'Resident portion', 'money' => true],
                ['key' => 'ha_charged', 'label' => 'Authority portion', 'money' => true],
                ['key' => 'charged', 'label' => 'Charged', 'money' => true],
                ['key' => 'received', 'label' => 'Received', 'money' => true],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'money' => true, 'balance' => true],
            ],
            rows: $rows,
            totals: [
                'tenant' => 'Total',
                'unit' => count($rows).' leases',
                'tenant_charged' => (string) $totalTenant,
                'ha_charged' => (string) $totalHa,
                'charged' => (string) $totalCharged,
                'received' => (string) $totalPaid,
                'outstanding' => (string) $totalCharged->minus($totalPaid),
            ],
            notes: [
                'Charged is read from the ledger, so it reflects proration on a tenancy that '
                    .'started or ended mid-month rather than the headline rent on the lease.',
                'Received counts only money that has cleared. A payment still in transit is not '
                    .'collected, however recently it was submitted.',
            ],
        );
    }

    /**
     * Charges posted in the period, per lease and per payer.
     *
     * @return array<int, array{tenant: Money, housing_authority: Money}>
     */
    private function chargesIn(string $period): array
    {
        $rows = DB::table('ledger_entries')
            ->select('lease_id', 'payer', DB::raw('SUM(amount) as total'))
            ->where('period', $period)
            ->whereIn('type', ['charge', 'adjustment'])
            ->whereIn('status', LedgerService::BALANCE_AFFECTING)
            ->groupBy('lease_id', 'payer')
            ->get();

        $byLease = [];

        foreach ($rows as $row) {
            $leaseId = (int) $row->lease_id;

            $byLease[$leaseId] ??= ['tenant' => Money::zero(), 'housing_authority' => Money::zero()];
            $byLease[$leaseId][$row->payer] = Money::fromString((string) ($row->total ?: '0'));
        }

        return $byLease;
    }

    /**
     * Money that actually met those charges.
     *
     * Read from the allocations rather than from payments in the month: a
     * payment made in August against July's rent belongs to July's rent roll,
     * which is the question this report is asked.
     *
     * @return array<int, Money>
     */
    private function paidAgainst(string $period): array
    {
        $rows = DB::table('payment_allocations as a')
            ->join('ledger_entries as e', 'e.id', '=', 'a.charge_entry_id')
            ->select('e.lease_id', DB::raw('SUM(a.amount) as total'))
            ->where('e.period', $period)
            // D-02: a reversed allocation is still on the record, and must not
            // count as money received.
            ->whereNull('a.reversed_at')
            ->groupBy('e.lease_id')
            ->get();

        $byLease = [];

        foreach ($rows as $row) {
            $byLease[(int) $row->lease_id] = Money::fromString((string) ($row->total ?: '0'));
        }

        return $byLease;
    }

    private function unitLabel(Lease $lease): string
    {
        $unit = $lease->unit;

        if (! $unit) {
            return '—';
        }

        return trim(sprintf('%s unit %s', $unit->property?->name ?? '', $unit->unit_number));
    }
}
