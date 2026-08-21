<?php

namespace App\Domain\Reporting;

use App\Domain\Ledger\BalanceCalculator;
use App\Models\Lease;
use App\Models\Tenant;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Everything a tenant's own dashboard needs, and nothing else.  [FR-POR-01]
 *
 * **INVARIANT I-4 lives here rather than in the view.** The Housing Authority
 * portion is not filtered out downstream — it is never put in. Assembling the
 * payload in one class means AC-POR-01 has a single place to be true, and a
 * test can assert against the serialised props rather than hoping a component
 * remembered to omit something.
 *
 * That is also why `ha_portion` is not read anywhere below, even though the
 * lease carries it: not reading it is the guarantee. Filtering it later would
 * only be a habit.
 */
class TenantAccountSummary
{
    public function __construct(
        private readonly BalanceCalculator $balances,
        private readonly BusinessCalendar $calendar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Tenant $tenant, ?Lease $lease): array
    {
        return [
            'tenant' => [
                'name' => $tenant->fullName(),
                'first_name' => $tenant->first_name,
            ],
            'lease' => $lease ? $this->leaseSummary($lease) : null,
            'address' => $lease ? $this->address($lease) : null,
        ];
    }

    /**
     * Balance and pending, kept apart.
     *
     * UI §3.1 is emphatic and the reason is concrete: ACH takes 2–5 business
     * days, and a tenant who cannot see their payment in flight pays a second
     * time. One combined figure would be arithmetically defensible and
     * practically expensive.
     *
     * `unconfirmed` is the slice of `pending` the gateway has never heard of —
     * an attempt somebody started and did not finish. It changes the wording
     * and nothing else: the money still counts as pending, so nobody is invited
     * to pay a second time, but they are not told "nothing more to do" about a
     * payment that was never submitted.
     *
     * @return array{balance: ?string, pending: ?string, unconfirmed: ?string, error: ?string}
     */
    public function balances(Tenant $tenant): array
    {
        try {
            return [
                'balance' => (string) $this->balances->tenantBalance($tenant->id),
                'pending' => (string) $this->balances->pendingPayments($tenant->id),
                'unconfirmed' => (string) $this->balances->unconfirmedPayments($tenant->id),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            // UI §3.1: never render a possibly-wrong balance. A figure someone
            // might pay against has to be right or absent — there is no useful
            // third option, and the pay action goes with it.
            report($e);

            return [
                'balance' => null,
                'pending' => null,
                'unconfirmed' => null,
                'error' => 'We could not work out your balance just now. '
                    .'Please refresh, or contact the office and we will tell you what is owed.',
            ];
        }
    }

    /**
     * When the next rent is due, and where that leaves them today.
     *
     * Deliberately neutral wording throughout (UI §8). "Past due" is a fact;
     * anything sharper is a letter from a lawyer, not a dashboard.
     *
     * @return array<string, mixed>|null
     */
    public function dueStatus(?Lease $lease, Money $balance): ?array
    {
        if (! $lease) {
            return null;
        }

        $today = $this->calendar->today();
        $dueDay = min((int) $lease->rent_due_day, 28);
        $graceDays = (int) $lease->grace_period_days;

        $thisPeriod = $this->calendar->dueDateFor($this->calendar->currentPeriod(), $dueDay);
        $nextDue = $today->lessThanOrEqualTo($thisPeriod)
            ? $thisPeriod
            : $this->calendar->dueDateFor(
                $today->addMonthNoOverflow()->format('Y-m'),
                $dueDay,
            );

        $graceExpiry = $this->calendar->graceExpiry($thisPeriod, $graceDays);

        return [
            // Long form in the tenant portal, always (UI §8).
            'next_due_on' => $nextDue->format('j F Y'),
            'next_due_iso' => $nextDue->toDateString(),
            'state' => $this->state($balance, $today, $thisPeriod, $graceExpiry),
            'grace_ends_on' => $graceDays > 0 ? $graceExpiry->format('j F Y') : null,
            'days_past_due' => $this->calendar->isPastDue($thisPeriod, $graceDays)
                ? $this->calendar->daysPastDue($thisPeriod, $graceDays)
                : 0,
        ];
    }

    private function state(
        Money $balance,
        CarbonImmutable $today,
        CarbonImmutable $dueDate,
        CarbonImmutable $graceExpiry,
    ): string {
        if (! $balance->isPositive()) {
            // Zero or a credit. AC-POR-02: a zero balance is shown as zero, the
            // next due date is shown, and nothing invites a payment.
            return $balance->isNegative() ? 'in_credit' : 'paid_up';
        }

        if ($today->lessThanOrEqualTo($dueDate)) {
            return 'due';
        }

        return $today->lessThanOrEqualTo($graceExpiry) ? 'within_grace' : 'past_due';
    }

    /** @return array<string, mixed> */
    private function leaseSummary(Lease $lease): array
    {
        return [
            'starts_on' => $lease->start_date?->format('j F Y'),
            'expires_on' => $lease->end_date?->format('j F Y'),
            // The tenant's own portion. The contract rent and the authority
            // portion are not the tenant's business and are not sent (BR-02).
            'monthly_rent' => (string) $lease->tenant_portion,
            'rent_due_day' => (int) $lease->rent_due_day,
            'in_management_review' => $lease->delinquency_state === 'management_review',
        ];
    }

    private function address(Lease $lease): ?string
    {
        $unit = $lease->unit;
        $property = $unit?->property;

        if (! $property) {
            return null;
        }

        return trim(sprintf(
            '%s, unit %s, %s, %s %s',
            $property->street_address,
            $unit->unit_number,
            $property->city,
            $property->state,
            $property->postal_code,
        ), ' ,');
    }
}
