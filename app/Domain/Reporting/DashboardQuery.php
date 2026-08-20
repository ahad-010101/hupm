<?php

namespace App\Domain\Reporting;

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Models\Lease;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\Payment;
use App\Models\SignatureRequest;
use App\Models\Unit;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The admin dashboard's numbers and panels.  [FR-ADM-01, UI §3.7, API-ADM-01]
 *
 * Everything here is derived at read time from the ledger (I-1). There is no
 * summary table, no nightly rollup and nothing cached: a dashboard figure that
 * disagreed with the ledger screen it links to would be worse than no figure,
 * because the reader has no way to tell which one is lying.
 *
 * Money crosses into the props as decimal strings, never numbers (I-10).
 */
class DashboardQuery
{
    public function __construct(
        private readonly BalanceCalculator $balances,
        private readonly BusinessCalendar $calendar,
    ) {}

    /**
     * Occupancy, money in, money out, and how many accounts are behind.
     *
     * @return array<string, mixed>
     */
    public function kpis(DashboardFilters $filters): array
    {
        $units = $this->unitCounts($filters);
        $collected = $this->collectedThisPeriod($filters);
        $outstanding = $this->outstanding($filters);

        return [
            'occupied' => $units['occupied'],
            'vacant' => $units['vacant'],
            'units_total' => $units['total'],
            // Integer arithmetic, rounded half up, because this file is on a
            // money path and the architecture test forbids round()/floor() on
            // one (I-10). Occupancy is not money, but a percentage is exactly
            // the sort of "just fix it up" division that teaches the next
            // person here that a float is fine.
            'occupancy_percent' => $units['total'] > 0
                ? intdiv($units['occupied'] * 200 + $units['total'], $units['total'] * 2)
                : null,

            'period' => $this->calendar->currentPeriod(),
            'collected_total' => (string) $collected['total'],
            'collected_tenant' => (string) $collected['tenant'],
            'collected_ha' => (string) $collected['housing_authority'],

            'outstanding_tenant' => (string) $outstanding['tenant'],
            'outstanding_ha' => (string) $outstanding['housing_authority'],
            'past_due_count' => $outstanding['past_due_count'],
        ];
    }

    /**
     * The five panels below the KPI row, in the order UI §3.7 fixes them.
     *
     * @return array<string, mixed>
     */
    public function panels(DashboardFilters $filters): array
    {
        return [
            'past_due' => $this->pastDueAccounts($filters),
            'tickets' => $this->openTickets($filters),
            'expiring_leases' => $this->expiringLeases($filters),
            'awaiting_signature' => $this->awaitingSignature($filters),
            'recent_payments' => $this->recentPayments($filters),
        ];
    }

    /**
     * Is there a portfolio yet?  [UI §7 "Empty portfolio"]
     *
     * "Admin dashboard shows a setup checklist rather than zeroed widgets." A
     * grid of $0.00 on day one reads as a broken system rather than an empty
     * one, and the person looking at it needs to know what to do next, not what
     * nothing looks like.
     *
     * @return array{empty: bool, steps: list<array{label: string, done: bool, href: string, hint: string}>}
     */
    public function setupChecklist(): array
    {
        $properties = DB::table('properties')->count();
        $units = DB::table('units')->count();
        $tenants = DB::table('tenants')->count();
        $leases = DB::table('leases')->where('status', Lease::STATUS_ACTIVE)->count();

        return [
            // Leases are the test, not properties: a portfolio with buildings
            // and nobody in them still has no money to report on.
            'empty' => $leases === 0,
            'steps' => [
                [
                    'label' => 'Add your properties',
                    'done' => $properties > 0,
                    'href' => '/admin/properties/create',
                    'hint' => 'Addresses and ownership. Units hang off these.',
                ],
                [
                    'label' => 'Add the units',
                    'done' => $units > 0,
                    'href' => '/admin/properties',
                    'hint' => 'A unit number is only unique within its property.',
                ],
                [
                    'label' => 'Add the residents',
                    'done' => $tenants > 0,
                    'href' => '/admin/tenants/create',
                    'hint' => 'Contact details first; portal invitations can wait.',
                ],
                [
                    'label' => 'Create the leases',
                    'done' => $leases > 0,
                    'href' => '/admin/leases/create',
                    'hint' => 'Rent split, due day and grace. Charges post from these.',
                ],
                [
                    'label' => 'Import the opening balances',
                    'done' => DB::table('ledger_entries')->exists(),
                    'href' => '/admin/import',
                    'hint' => 'Bring what is owed across from Rent Manager.',
                ],
            ],
        ];
    }

    /** @return array{occupied:int, vacant:int, total:int} */
    private function unitCounts(DashboardFilters $filters): array
    {
        $units = Unit::query()
            ->when($filters->propertyId, fn ($q, $id) => $q->where('property_id', $id))
            ->count();

        $occupied = Unit::query()
            ->when($filters->propertyId, fn ($q, $id) => $q->where('property_id', $id))
            ->whereIn('id', function ($sub) use ($filters) {
                $sub->select('unit_id')->from('leases')->where('status', Lease::STATUS_ACTIVE);

                $filters->applyToLeases($sub);
            })
            ->count();

        return ['occupied' => $occupied, 'vacant' => max(0, $units - $occupied), 'total' => $units];
    }

    /**
     * Money that actually arrived this month.
     *
     * By **posting date**, not by the period the charge belonged to: "collected
     * this month" is a cash question, and rent paid in August for July is money
     * collected in August. Only balance-affecting statuses count, so a pending
     * ACH is not collected and a returned one stops being collected (I-6).
     *
     * @return array{total: Money, tenant: Money, housing_authority: Money}
     */
    private function collectedThisPeriod(DashboardFilters $filters): array
    {
        $start = $this->calendar->today()->startOfMonth();
        $end = $this->calendar->today()->endOfMonth();

        $rows = DB::table('ledger_entries')
            ->select('payer', DB::raw('SUM(amount) as total'))
            ->where('type', 'payment')
            ->whereIn('status', LedgerService::BALANCE_AFFECTING)
            ->whereBetween('posted_on', [$start->toDateString(), $end->toDateString()])
            ->when($filters->propertyId || $filters->tenantId || $filters->housingAuthorityId,
                fn ($q) => $q->whereIn('lease_id', function ($sub) use ($filters) {
                    $sub->select('id')->from('leases');

                    $filters->applyToLeases($sub);
                }))
            ->groupBy('payer')
            ->get();

        $byPayer = ['tenant' => Money::zero(), 'housing_authority' => Money::zero()];

        foreach ($rows as $row) {
            // Payments are stored negative; collections are read as positive.
            $byPayer[$row->payer] = Money::fromString((string) ($row->total ?: '0'))->absolute();
        }

        return [
            'total' => $byPayer['tenant']->plus($byPayer['housing_authority']),
            'tenant' => $byPayer['tenant'],
            'housing_authority' => $byPayer['housing_authority'],
        ];
    }

    /**
     * What the portfolio is owed, and by how many accounts.
     *
     * @return array{tenant: Money, housing_authority: Money, past_due_count: int}
     */
    private function outstanding(DashboardFilters $filters): array
    {
        $leases = $this->scopedActiveLeases($filters)->get();
        $tenantBalances = $this->balances->balancesByTenant('tenant');
        $haBalances = $this->balances->balancesByTenant('housing_authority');

        $tenantTotal = Money::zero();
        $haTotal = Money::zero();
        $pastDue = 0;

        foreach ($leases as $lease) {
            $tenantOwed = $tenantBalances[$lease->tenant_id] ?? Money::zero();
            $tenantTotal = $tenantTotal->plus($tenantOwed);
            $haTotal = $haTotal->plus($haBalances[$lease->tenant_id] ?? Money::zero());

            if ($tenantOwed->isPositive() && $this->isPastDue($lease)) {
                $pastDue++;
            }
        }

        return ['tenant' => $tenantTotal, 'housing_authority' => $haTotal, 'past_due_count' => $pastDue];
    }

    /**
     * Accounts behind, worst first.
     *
     * @return list<array<string, mixed>>
     */
    private function pastDueAccounts(DashboardFilters $filters): array
    {
        $balances = $this->balances->balancesByTenant('tenant');

        return $this->scopedActiveLeases($filters)
            ->with(['tenant:id,first_name,last_name,phone', 'unit:id,unit_number,property_id', 'unit.property:id,name'])
            ->get()
            ->map(function (Lease $lease) use ($balances) {
                $owed = $balances[$lease->tenant_id] ?? Money::zero();

                return [
                    'lease_id' => $lease->id,
                    'tenant_id' => $lease->tenant_id,
                    'tenant' => $lease->tenant?->fullName(),
                    'phone' => $lease->tenant?->phone,
                    'unit' => $this->unitLabel($lease),
                    'balance' => (string) $owed,
                    'days_past_due' => $this->daysPastDue($lease),
                    'in_review' => $lease->delinquency_state === 'management_review',
                    'href' => "/admin/ledger/{$lease->tenant_id}",
                    'owed' => $owed,
                ];
            })
            ->filter(fn (array $row) => $row['owed']->isPositive() && $row['days_past_due'] > 0)
            ->sortByDesc('days_past_due')
            ->take(10)
            ->map(fn (array $row) => array_diff_key($row, ['owed' => null]))
            ->values()
            ->all();
    }

    /** @return array{items: list<array<string, mixed>>, open_total: int, emergency_total: int} */
    private function openTickets(DashboardFilters $filters): array
    {
        $base = fn () => $filters->applyToTickets(Ticket::query());

        $items = $base()
            ->when(
                $filters->maintenanceStatus === null,
                fn ($q) => $q->whereNotIn('status', [Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELLED]),
            )
            ->with(['tenant:id,first_name,last_name', 'unit:id,unit_number,property_id', 'unit.property:id,name'])
            // Emergencies to the top, then oldest first: age is the thing that
            // turns an open ticket into a complaint.
            ->orderByDesc('is_emergency')
            ->orderBy('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'tenant' => $ticket->tenant?->fullName(),
                'unit' => $this->unitLabel($ticket),
                'category' => $ticket->category,
                'status' => $ticket->status,
                'is_emergency' => (bool) $ticket->is_emergency,
                'age_days' => $ticket->created_at
                    ? (int) $this->calendar->toBusinessDate($ticket->created_at)->diffInDays($this->calendar->today())
                    : null,
                'href' => "/admin/maintenance/{$ticket->id}",
            ])
            ->all();

        return [
            'items' => $items,
            'open_total' => $base()->whereNotIn('status', [Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELLED])->count(),
            'emergency_total' => $base()
                ->where('is_emergency', true)
                ->whereNotIn('status', [Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELLED])
                ->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function expiringLeases(DashboardFilters $filters): array
    {
        $today = $this->calendar->today();
        $horizon = $today->addDays($filters->expiryDays);

        return $this->scopedActiveLeases($filters)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$today->toDateString(), $horizon->toDateString()])
            ->with(['tenant:id,first_name,last_name', 'unit:id,unit_number,property_id', 'unit.property:id,name'])
            ->orderBy('end_date')
            ->limit(10)
            ->get()
            ->map(fn (Lease $lease) => [
                'lease_id' => $lease->id,
                'tenant' => $lease->tenant?->fullName(),
                'unit' => $this->unitLabel($lease),
                // Long form: this is a date somebody acts on, not a table cell
                // to scan past.
                'ends_on' => $lease->end_date?->format('j F Y'),
                'days_left' => $lease->end_date
                    ? (int) $today->diffInDays($this->calendar->toBusinessDate($lease->end_date))
                    : null,
                'href' => "/admin/leases/{$lease->id}",
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function awaitingSignature(DashboardFilters $filters): array
    {
        return SignatureRequest::query()
            ->whereIn('status', [SignatureRequest::STATUS_PENDING, SignatureRequest::STATUS_VIEWED])
            ->when($filters->tenantId, fn ($q, $id) => $q->where('tenant_id', $id))
            ->with(['tenant:id,first_name,last_name', 'document:id,title'])
            ->orderBy('sent_at')
            ->limit(10)
            ->get()
            ->map(fn (SignatureRequest $request) => [
                'id' => $request->id,
                'tenant' => $request->tenant?->fullName(),
                'document' => $request->document?->title,
                'status' => $request->status,
                'sent_on' => $request->sent_at?->format('j M Y'),
                'expires_on' => $request->expires_at?->format('j M Y'),
                'href' => '/admin/signatures',
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentPayments(DashboardFilters $filters): array
    {
        return $filters->applyToPayments(Payment::query())
            ->with('tenant:id,first_name,last_name')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'tenant' => $payment->tenant?->fullName(),
                'tenant_id' => $payment->tenant_id,
                'amount' => (string) $payment->amount,
                'method' => $payment->method,
                'payer' => $payment->payer,
                'status' => $payment->status,
                'received_on' => $payment->submitted_at?->format('j M Y'),
                'href' => $payment->tenant_id ? "/admin/ledger/{$payment->tenant_id}" : '/admin/payments',
            ])
            ->all();
    }

    /** @return Builder<Lease> */
    private function scopedActiveLeases(DashboardFilters $filters)
    {
        $query = Lease::query()->where('leases.status', Lease::STATUS_ACTIVE);

        $filters->applyToLeases($query);

        return $query;
    }

    private function isPastDue(Lease $lease): bool
    {
        return $this->daysPastDue($lease) > 0;
    }

    /**
     * Days past the grace period for the current month's rent.
     *
     * Through BusinessCalendar, in America/New_York (D-07). "Past due" on a
     * server running UTC is off by a day for five hours every night, which is
     * exactly the window a late fee or a delinquency flag lands in.
     */
    private function daysPastDue(Lease $lease): int
    {
        $due = $this->calendar->dueDateFor(
            $this->calendar->currentPeriod(),
            min((int) $lease->rent_due_day, 28),
        );

        return $this->calendar->daysPastDue($due, (int) $lease->grace_period_days);
    }

    private function unitLabel(Lease|Ticket $model): string
    {
        $unit = $model->unit;

        if (! $unit) {
            return 'unit unknown';
        }

        return trim(sprintf('%s unit %s', $unit->property?->name ?? '', $unit->unit_number));
    }
}
