<?php

namespace App\Domain\Reporting;

use App\Domain\Ledger\BalanceCalculator;
use App\Models\Lease;
use App\Support\Money;

/**
 * Who owes which share of the rent.  [FR-ADM-02]
 *
 * The obligation split across every active lease: what the resident is
 * contracted to pay, what the housing authority is, and what each currently
 * owes. The two portions must sum to the contract rent on every row — that is
 * AC-REG-03 enforced at the lease, and this report is where a break in it would
 * finally be visible across the whole portfolio.
 *
 * This is an **admin and owner** report. Nothing here may be rendered to a
 * resident: the housing authority portion is the one figure a tenant must never
 * see (I-4), and a report that names both is the obvious way to leak it.
 */
class SectionEightReport
{
    public function __construct(private readonly BalanceCalculator $balances) {}

    public function build(): Report
    {
        $tenantBalances = $this->balances->balancesByTenant('tenant');
        $haBalances = $this->balances->balancesByTenant('housing_authority');

        $rows = [];
        $totalContract = Money::zero();
        $totalTenant = Money::zero();
        $totalHa = Money::zero();
        $totalTenantOwed = Money::zero();
        $totalHaOwed = Money::zero();
        $mismatches = 0;

        $leases = Lease::with(['tenant:id,first_name,last_name', 'unit:id,unit_number,property_id', 'unit.property:id,name', 'housingAuthority:id,name'])
            ->where('status', Lease::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        foreach ($leases as $lease) {
            $contract = $lease->total_contract_rent;
            $tenantPortion = $lease->tenant_portion;
            $haPortion = $lease->ha_portion;

            // AC-REG-03 is enforced when a lease is saved. Checking it again
            // here costs nothing and is the only place a historic break would
            // surface, since a lease created before the rule cannot be caught
            // by the rule.
            if (! $tenantPortion->plus($haPortion)->equals($contract)) {
                $mismatches++;
            }

            $totalContract = $totalContract->plus($contract);
            $totalTenant = $totalTenant->plus($tenantPortion);
            $totalHa = $totalHa->plus($haPortion);

            $tenantOwed = $tenantBalances[$lease->tenant_id] ?? Money::zero();
            $haOwed = $haBalances[$lease->tenant_id] ?? Money::zero();

            $totalTenantOwed = $totalTenantOwed->plus($tenantOwed);
            $totalHaOwed = $totalHaOwed->plus($haOwed);

            $rows[] = [
                'tenant' => $lease->tenant?->fullName() ?? '—',
                'unit' => $this->unitLabel($lease),
                'authority' => $lease->housingAuthority?->name ?? 'Not subsidised',
                'contract' => (string) $contract,
                'tenant_portion' => (string) $tenantPortion,
                'ha_portion' => (string) $haPortion,
                'tenant_owed' => (string) $tenantOwed,
                'ha_owed' => (string) $haOwed,
            ];
        }

        $notes = [
            'The resident portion and the authority portion sum to the contract rent on every '
                .'lease. Anything else is a data fault, not a rounding difference.',
            'Amounts owed are computed from the ledger at the moment this report was opened. '
                .'They are two separate obligations, and money never moves between them.',
        ];

        if ($mismatches > 0) {
            $notes[] = "⚠ {$mismatches} lease(s) have portions that do not sum to the contract rent. "
                .'These need correcting on the lease before the next rent run.';
        }

        return new Report(
            key: 'section-8',
            title: 'Section 8 obligation split',
            subtitle: count($rows).' active leases',
            columns: [
                ['key' => 'tenant', 'label' => 'Resident'],
                ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'authority', 'label' => 'Authority'],
                ['key' => 'contract', 'label' => 'Contract rent', 'money' => true],
                ['key' => 'tenant_portion', 'label' => 'Resident portion', 'money' => true],
                ['key' => 'ha_portion', 'label' => 'Authority portion', 'money' => true],
                ['key' => 'tenant_owed', 'label' => 'Resident owes', 'money' => true, 'balance' => true],
                ['key' => 'ha_owed', 'label' => 'Authority owes', 'money' => true, 'balance' => true],
            ],
            rows: $rows,
            totals: [
                'tenant' => 'Total',
                'unit' => count($rows).' leases',
                'authority' => '',
                'contract' => (string) $totalContract,
                'tenant_portion' => (string) $totalTenant,
                'ha_portion' => (string) $totalHa,
                'tenant_owed' => (string) $totalTenantOwed,
                'ha_owed' => (string) $totalHaOwed,
            ],
            notes: $notes,
        );
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
