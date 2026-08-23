<?php

namespace App\Domain\Charges;

use App\Domain\Ledger\LedgerService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplate;
use App\Domain\Payments\AllocationService;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Support\BusinessCalendar;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts rent and recurring charges for a lease.  [FR-CHG-01]
 *
 * Writes nothing itself — every row goes through LedgerService (I-2). What
 * lives here is the question of *which* charges are due and *how much* they
 * are, which is a different question from how a ledger row is written.
 *
 * Catch-up is not a special mode. The service asks "which periods should this
 * lease have charges for by now?" and posts the ones that are missing, so a
 * run after a two-day host outage does the same thing as an ordinary run
 * (AC-CHG-04). Idempotency comes from `charge_key` (D-01), which makes that
 * safe by construction rather than by careful bookkeeping.
 */
class ChargePostingService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly BalanceOfPeriods $periods,
        private readonly BusinessCalendar $calendar,
        private readonly Settings $settings,
        private readonly NotificationService $notifications,
        private readonly AllocationService $allocations,
    ) {}

    /**
     * Post everything outstanding for one lease.
     *
     * Wrapped in a single transaction: a lease either gets its whole period —
     * tenant rent, HA rent, every utility schedule — or none of it. A partial
     * period is worse than a missed one, because the next run would see the
     * period as already handled and never complete it.
     *
     * `$notify` exists for **backfill**: replaying months that have already
     * happened must not email a resident that last March's rent is due. The
     * nightly job leaves it alone; the demo seeder and WP-08's opening-balance
     * import pass false. Defaulting to true means a caller has to ask for
     * silence rather than accidentally get it.
     *
     * @return int the number of entries posted
     */
    public function postFor(Lease $lease, ?CarbonImmutable $asOf = null, bool $notify = true): int
    {
        $asOf ??= $this->calendar->today();

        if ($lease->status !== Lease::STATUS_ACTIVE) {
            // AC-REG-07: an ended lease posts nothing from its effective date.
            return 0;
        }

        return DB::transaction(function () use ($lease, $asOf, $notify) {
            $posted = 0;

            foreach ($this->periods->duePeriodsFor($lease, $asOf) as $period) {
                $posted += $this->postPeriod($lease, $period, $notify);
            }

            if ($posted > 0) {
                // A credit on the account meets the charge it was meant for,
                // in the same transaction that created the charge (Q-8,
                // `credit_forward`). Left until later, the new charge would sit
                // outstanding beside a credit that covers it — and WP-23 would
                // put a late fee on rent the tenant has already paid.
                foreach (['tenant', 'housing_authority'] as $payer) {
                    $this->allocations->applyCreditsFor($lease->tenant_id, $payer);
                }
            }

            return $posted;
        });
    }

    /** @param object{period:string, postedOn:CarbonImmutable, prorated:bool, daysCharged:int, daysInPeriod:int} $period */
    private function postPeriod(Lease $lease, object $period, bool $notify = true): int
    {
        $existing = $this->existingKeysFor($lease, $period->period);
        $posted = 0;

        // 1. Tenant rent.
        $tenantKey = "{$lease->id}:rent:{$period->period}:tenant";

        if (! in_array($tenantKey, $existing, true)) {
            $amount = $this->prorate($lease->tenant_portion, $period);

            if ($amount->isPositive()) {
                $this->ledger->postCharge(
                    $lease, 'rent', 'tenant', $amount,
                    $this->describe('Rent', $period),
                    $tenantKey, $period->postedOn, $period->period,
                );
                $posted++;

                if ($notify) {
                    $this->queueRentDueEmail($lease, $amount, $period);
                }
            }
        }

        // 2. Housing authority rent. AC-CHG-02: a zero portion posts nothing —
        //    an entry for $0.00 is noise on a ledger someone has to read.
        $haKey = "{$lease->id}:rent:{$period->period}:housing_authority";

        if (! in_array($haKey, $existing, true)) {
            $amount = $this->prorate($lease->ha_portion, $period);

            if ($amount->isPositive()) {
                $this->ledger->postCharge(
                    $lease, 'rent', 'housing_authority', $amount,
                    $this->describe('Rent', $period),
                    $haKey, $period->postedOn, $period->period,
                );
                $posted++;
            }
        }

        // 3. Recurring schedules — utilities, pet fee, parking. Each gets its
        //    own charge_key, which is the second reason the specified unique
        //    index had to go: it allowed only one utility charge per period
        //    (D-01).
        foreach ($this->activeSchedules($lease) as $schedule) {
            $key = "{$lease->id}:sched{$schedule->id}:{$period->period}";

            if (in_array($key, $existing, true)) {
                continue;
            }

            $amount = Money::fromString((string) $schedule->amount);

            if (! $amount->isPositive()) {
                continue;
            }

            $this->ledger->postCharge(
                $lease, $schedule->category, $schedule->payer, $amount,
                $schedule->description,
                $key, $period->postedOn, $period->period,
            );
            $posted++;
        }

        return $posted;
    }

    /**
     * Proration for a mid-period start.  [AC-CHG-03, GATE Q-10]
     *
     * `charges.proration_method` is unanswered (Q-10) and ships as `daily`:
     * the month's rent times days occupied over days in the month. `none`
     * charges the full month regardless, which some landlords do.
     *
     * Money::prorate keeps this in integer cents — a float here would put the
     * rounding error directly into someone's rent.
     */
    private function prorate(Money $full, object $period): Money
    {
        if (! $period->prorated) {
            return $full;
        }

        return match ($this->settings->string('charges.proration_method', 'daily')) {
            'none' => $full,
            default => $full->prorate($period->daysCharged, $period->daysInPeriod),
        };
    }

    private function describe(string $label, object $period): string
    {
        $month = CarbonImmutable::createFromFormat('Y-m-d', $period->period.'-01')->format('F Y');

        return $period->prorated
            ? "{$label} — {$month} ({$period->daysCharged} of {$period->daysInPeriod} days)"
            : "{$label} — {$month}";
    }

    /** @return list<string> */
    private function existingKeysFor(Lease $lease, string $period): array
    {
        return LedgerEntry::query()
            ->where('lease_id', $lease->id)
            ->where('period', $period)
            ->whereNotNull('charge_key')
            ->pluck('charge_key')
            ->all();
    }

    /** @return Collection<int, object> */
    private function activeSchedules(Lease $lease)
    {
        return DB::table('lease_charge_schedules')
            ->where('lease_id', $lease->id)
            ->where('active', true)
            ->orderBy('id')
            ->get();
    }

    private function queueRentDueEmail(Lease $lease, Money $amount, object $period): void
    {
        $tenant = $lease->tenant;

        if (! $tenant) {
            return;
        }

        // A tenant with no address resolves to not_deliverable and surfaces to
        // admin rather than failing silently (AC-NTF-03, Q-4).
        $this->notifications->send(
            NotificationTemplate::RentDue,
            $tenant->email,
            [
                'name' => $tenant->fullName(),
                'amount' => $amount->format(),
                // Long-form dates in tenant communication (UI §8).
                'dueDate' => $period->postedOn->format('j F Y'),
                'url' => url('/portal'),
            ],
            tenantId: $tenant->id,
        );
    }
}
