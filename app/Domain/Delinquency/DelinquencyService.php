<?php

namespace App\Domain\Delinquency;

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplate;
use App\Models\Lease;
use App\Models\RecurringPayment;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Management Review.  [FR-DEL-01/02/03, BR-10…BR-14, WP-24]
 *
 * The one rule that shapes everything here: **the system must never
 * permanently refuse all payment** (BR-12). Management Review turns off the
 * tenant's own online payment and their autopay — and leaves admin-recorded
 * payment working, at all times, deliberately. That is what resolves
 * contradiction C2, and it is why `PaymentRecordingService` has no delinquency
 * check anywhere in it.
 *
 * **Entering is automatic; leaving is not** (BR-14). Nothing in this class or
 * the job above it releases an account, not even paying the balance in full.
 * A person decides, and records why.
 */
class DelinquencyService
{
    public const STATE_CURRENT = 'current';

    public const STATE_REVIEW = 'management_review';

    public function __construct(
        private readonly BalanceCalculator $balances,
        private readonly NotificationService $notifications,
        private readonly BusinessCalendar $calendar,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** [GATE Q-6] Days after the due date, portfolio-wide. */
    public function triggerDay(): int
    {
        return max(0, $this->settings->int('delinquency.trigger_day', 5));
    }

    /**
     * Should this lease be under review today?
     *
     * The trigger counts from the **contractual due date**, not from the
     * lease's grace period (BR-10, and Q-6 makes the count configurable).
     * Those are different clocks on purpose: grace governs whether a *fee* is
     * charged, this governs whether an account gets *looked at*.
     *
     * See `entersReviewInsideGrace()` for the case where that distinction
     * becomes a problem.
     */
    public function shouldEnterReview(Lease $lease, ?CarbonImmutable $asOf = null): bool
    {
        $asOf = $this->normalise($asOf ?? $this->calendar->today());

        if ($lease->status !== Lease::STATUS_ACTIVE) {
            return false;
        }

        if ($lease->delinquency_state === self::STATE_REVIEW) {
            return false;
        }

        // The account balance, not a single period: BR-10 is about an account
        // carrying a balance, unlike the per-period fee rule.
        //
        // [WP-40, Q-12] Arrears rather than the whole balance — the security
        // deposit is excluded. Review suspends online payment (BR-11), so
        // triggering on an unpaid deposit would lock a tenant out of the only
        // route they have to pay it: a trap, not a policy. Rent and fees
        // trigger it exactly as before.
        if (! $this->balances->arrearsBalance($lease->tenant_id)->isPositive()) {
            return false;
        }

        return $asOf->greaterThanOrEqualTo($this->triggerFor($lease, $asOf));
    }

    /**
     * Put an account under review.  [FR-DEL-01, AC-DEL-01]
     *
     * @param  User|null  $actor  null when the daily job did it
     */
    public function enterReview(Lease $lease, string $reason, ?User $actor = null): void
    {
        if ($lease->delinquency_state === self::STATE_REVIEW) {
            return;
        }

        $balance = $this->balances->tenantBalance($lease->tenant_id);

        DB::transaction(function () use ($lease, $reason, $actor, $balance) {
            $this->recordTransition($lease, self::STATE_CURRENT, self::STATE_REVIEW, $reason, $balance, $actor);

            $lease->forceFill([
                'delinquency_state' => self::STATE_REVIEW,
                'delinquency_since' => $this->calendar->today()->toDateString(),
            ])->save();

            // BR-11. Suspended, not cancelled: the tenant did not choose this
            // and an admin may undo it.
            RecurringPayment::where('lease_id', $lease->id)
                ->where('status', RecurringPayment::STATUS_ACTIVE)
                ->update([
                    'status' => RecurringPayment::STATUS_SUSPENDED,
                    'suspended_reason' => 'Account entered Management Review',
                    'updated_at' => now(),
                ]);
        });

        $this->notify($lease, $balance);

        $this->audit->record('delinquency.review.entered', $lease, [
            'reason' => $reason,
            'balance' => $balance->toDecimalString(),
            'by' => $actor?->id,
        ]);
    }

    /**
     * Let an account out.  [FR-DEL-03, AC-DEL-05, AC-DEL-06]
     *
     * A manual act with a mandatory reason, and it **does not resume autopay**.
     * Restarting a standing debit on someone who has just been through this
     * without them saying so is how an account goes overdrawn.
     */
    public function release(Lease $lease, string $reason, User $actor): void
    {
        if (trim($reason) === '') {
            // AC-DEL-05. Enforced here as well as in the request, because the
            // reason is the record of why somebody was let out.
            throw new InvalidArgumentException('Releasing an account requires a reason.');
        }

        if ($lease->delinquency_state !== self::STATE_REVIEW) {
            throw new InvalidArgumentException('That account is not under review.');
        }

        $balance = $this->balances->tenantBalance($lease->tenant_id);

        DB::transaction(function () use ($lease, $reason, $actor, $balance) {
            $this->recordTransition($lease, self::STATE_REVIEW, self::STATE_CURRENT, $reason, $balance, $actor);

            $lease->forceFill([
                'delinquency_state' => self::STATE_CURRENT,
                'delinquency_since' => null,
            ])->save();

            // Autopay is pointedly NOT reactivated (AC-DEL-06). It stays
            // suspended until a person turns it back on.
        });

        $this->audit->record('delinquency.review.released', $lease, [
            'reason' => $reason,
            'balance_at_release' => $balance->toDecimalString(),
            'by' => $actor->id,
            'autopay_resumed' => false,
        ]);
    }

    /**
     * May autopay debit this lease?
     *
     * The predicate WP-16's job will consult. It lives here rather than there
     * because the reason a debit is refused is a delinquency fact, and
     * duplicating it in the job would let the two drift.
     */
    public function autopayPermitted(Lease $lease): bool
    {
        return $lease->status === Lease::STATUS_ACTIVE
            && $lease->delinquency_state !== self::STATE_REVIEW;
    }

    /**
     * Leases whose own grace period outlasts the review trigger.
     *
     * These enter Management Review — and lose the ability to pay online —
     * while still inside the grace their lease grants them, and before any late
     * fee is due. Two clocks that were never reconciled: BR-10 sets a
     * portfolio-wide day, BR-07 puts grace on the lease.
     *
     * Surfaced rather than silently corrected. Changing either clock is the
     * client's decision, and it belongs with Q-6.
     *
     * @return Collection<int, Lease>
     */
    public function leasesTriggeringInsideGrace()
    {
        return Lease::query()
            ->where('status', Lease::STATUS_ACTIVE)
            ->where('grace_period_days', '>', $this->triggerDay())
            ->with(['tenant:id,first_name,last_name'])
            ->get();
    }

    private function triggerFor(Lease $lease, CarbonImmutable $asOf): CarbonImmutable
    {
        $dueDate = $this->calendar->dueDateFor(
            $this->calendar->periodFor($asOf),
            min((int) $lease->rent_due_day, 28),
        );

        return $this->normalise($dueDate)->addDays($this->triggerDay());
    }

    private function recordTransition(
        Lease $lease,
        string $from,
        string $to,
        string $reason,
        Money $balance,
        ?User $actor,
    ): void {
        DB::table('delinquency_events')->insert([
            'lease_id' => $lease->id,
            'from_state' => $from,
            'to_state' => $to,
            'reason' => substr($reason, 0, 500),
            // A snapshot, not a live figure: what the balance was when somebody
            // acted is the question a dispute asks.
            'balance_at_event' => $balance->toDecimalString(),
            // NULL means the system did it, which is a meaningful difference
            // when explaining to a tenant why this happened at 02:30.
            'actor_user_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    private function notify(Lease $lease, Money $balance): void
    {
        $tenant = $lease->tenant;

        if (! $tenant) {
            return;
        }

        $this->notifications->send(
            NotificationTemplate::ManagementReview,
            $tenant->email,
            [
                'name' => $tenant->fullName(),
                'balance' => $balance->format(),
                // Without a number to call, "contact management" is not
                // actionable — and every message here has to be (UI §8).
                'phone' => $this->settings->string('company.phone'),
            ],
            tenantId: $tenant->id,
        );
    }

    /** Compared as business-timezone midnights throughout (D-07). */
    private function normalise(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d'), $this->calendar->timezone())->startOfDay();
    }
}
