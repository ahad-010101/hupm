<?php

namespace App\Domain\Fees;

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplate;
use App\Models\Lease;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Posting late fees.  [FR-FEE-01, TDD §8 PostLateFees, WP-23]
 *
 * **Ships switched off.** `fees.automation_enabled` defaults to false and stays
 * false until the client confirms an attorney has reviewed the fee language.
 * That is a settings row rather than a code branch on purpose: turning it on is
 * then a decision someone records, not a deployment — and the code that will
 * run in anger is the code that has been running in tests all along.
 *
 * The one rule worth stating twice: **a late fee never touches the Housing
 * Authority portion** (BR-06, I-7). The authority is not late; it pays on its
 * own schedule under the HAP contract, and charging it a fee would be both
 * wrong and unrecoverable.
 */
class LateFeeService
{
    public function __construct(
        private readonly LateFeeCalculator $calculator,
        private readonly LedgerService $ledger,
        private readonly BalanceCalculator $balances,
        private readonly NotificationService $notifications,
        private readonly BusinessCalendar $calendar,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** Is automation switched on? [GATE — attorney review] */
    public function isEnabled(): bool
    {
        return $this->settings->bool('fees.automation_enabled', false);
    }

    /**
     * Post everything owed for one lease.
     *
     * One transaction per lease: a half-charged period would look handled to
     * the next run and never complete, the same reasoning as WP-10's posting.
     *
     * @return int fees posted
     */
    public function postFor(Lease $lease, ?CarbonImmutable $asOf = null): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $due = $this->calculator->due($lease, $asOf);

        if ($due === []) {
            return 0;
        }

        $posted = DB::transaction(function () use ($lease, $due) {
            $count = 0;

            foreach ($due as $fee) {
                $this->ledger->postCharge(
                    $lease,
                    'late_fee',
                    // BR-06. Not a parameter anywhere in this class.
                    'tenant',
                    $fee->amount,
                    $fee->description,
                    $fee->key,
                    $fee->postedOn,
                    $fee->period,
                );

                $count++;
            }

            return $count;
        });

        if ($posted > 0) {
            $this->notify($lease, $due);

            $this->audit->record('fees.late.posted', $lease, [
                'fees' => $posted,
                'total' => Money::sum(array_map(fn ($f) => $f->amount, $due))->toDecimalString(),
                'periods' => array_values(array_unique(array_map(fn ($f) => $f->period, $due))),
            ]);
        }

        return $posted;
    }

    /**
     * What a lease would be charged, without charging it.
     *
     * The gate means this code will sit unused for a while. An admin — or the
     * attorney doing the review — needs to be able to see what it would do
     * before anyone agrees to switch it on.
     *
     * @return list<object>
     */
    public function preview(Lease $lease, ?CarbonImmutable $asOf = null): array
    {
        return $this->calculator->due($lease, $asOf);
    }

    /**
     * One email per lease per run, not one per fee.
     *
     * A month of daily fees is thirty ledger rows and one thing that happened.
     * Thirty emails would be the system shouting at somebody who already knows
     * they are behind.
     *
     * @param  list<object>  $due
     */
    private function notify(Lease $lease, array $due): void
    {
        $tenant = $lease->tenant;

        if (! $tenant) {
            return;
        }

        $total = Money::sum(array_map(fn ($f) => $f->amount, $due));
        $graceExpiry = $this->calendar->graceExpiry(
            $this->calendar->dueDateFor(
                $due[0]->period,
                min((int) $lease->rent_due_day, 28),
            ),
            (int) $lease->grace_period_days,
        );

        $this->notifications->send(
            NotificationTemplate::LateFeePosted,
            $tenant->email,
            [
                'name' => $tenant->fullName(),
                'fee' => $total->format(),
                // Long form in tenant communication (UI §8).
                'date' => $this->calendar->today()->format('j F Y'),
                'graceEnd' => $graceExpiry->format('j F Y'),
                'balance' => $this->balances->tenantBalance($tenant->id)->format(),
                'url' => url('/portal/pay'),
            ],
            tenantId: $tenant->id,
        );
    }
}
