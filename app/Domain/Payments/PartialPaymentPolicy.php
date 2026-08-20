<?php

namespace App\Domain\Payments;

use App\Domain\Ledger\BalanceCalculator;
use App\Models\Lease;
use App\Support\BusinessCalendar;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * May this tenant pay this amount?  [FR-PAY-01 validation, GAP-1, BR-19]
 *
 * The four policies come from the lease, not from a global setting, because
 * they are terms of a tenancy: one resident may be on a payment arrangement
 * while their neighbour is full-only.
 *
 * This answers a yes/no with a *reason*, because every rejection here is shown
 * to a tenant and every error message has to say what to do next (UI §8). "Not
 * allowed" is not an answer anyone can act on.
 */
class PartialPaymentPolicy
{
    public function __construct(
        private readonly BalanceCalculator $balances,
        private readonly BusinessCalendar $calendar,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array{allowed: bool, reason: ?string}
     */
    public function check(Lease $lease, Money $amount): array
    {
        if (! $amount->isPositive()) {
            return $this->no('Enter an amount greater than zero.');
        }

        $balance = $this->balances->tenantBalance($lease->tenant_id);

        // Paying the whole balance is always permitted — no policy exists to
        // stop someone clearing their account.
        if ($amount->equals($balance)) {
            return $this->yes();
        }

        if ($amount->greaterThan($balance)) {
            return $this->settings->string('payments.overpayment_behaviour', 'credit_forward') === 'credit_forward'
                ? $this->yes()
                : $this->no('That is more than you owe. Enter '.$balance->format().' or less.');
        }

        // [GATE C1/Q-1] "Full ledger required" is undefined in the source
        // (FR-ARR-01). Shipped interpretation: a part payment is blocked until
        // an admin has marked this resident's ledger reviewed for the current
        // period. Applies to every policy below, because the flag is a
        // condition on part payment rather than a policy of its own.
        if ($lease->ledger_review_required && ! $this->ledgerReviewedThisPeriod($lease)) {
            return $this->no(
                'Part payments on this account need the office to review it first. '
                .'Please contact us and we will sort it out with you.'
            );
        }

        return match ($lease->partial_payment_policy) {
            'partial_allowed' => $this->checkMinimum($lease, $amount),
            'before_due_only' => $this->checkBeforeDue($lease, $amount),
            'under_arrangement_only' => $this->checkArrangement($lease, $amount),
            // full_only, and anything unrecognised. Defaulting to the strictest
            // reading is the safe direction for a value we do not understand.
            default => $this->no(
                'This account is set to pay the full balance of '.$balance->format().'. '
                .'Please contact the office if you need to arrange something different.'
            ),
        };
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function checkMinimum(Lease $lease, Money $amount): array
    {
        if ($this->policyExpired($lease)) {
            return $this->no(
                'The arrangement allowing part payments has ended. '
                .'Please contact the office to renew it.'
            );
        }

        $minimum = $lease->partial_minimum_amount;

        if ($minimum && $amount->lessThan($minimum)) {
            return $this->no('The smallest part payment on this account is '.$minimum->format().'.');
        }

        return $this->yes();
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function checkBeforeDue(Lease $lease, Money $amount): array
    {
        $dueDate = $this->calendar->dueDateFor(
            $this->calendar->today()->format('Y-m'),
            min((int) $lease->rent_due_day, 28),
        );

        if ($this->calendar->today()->greaterThan($dueDate)) {
            return $this->no(
                'Part payments are only accepted before the '.$dueDate->format('jS')
                .' of the month. Please pay the full balance or contact the office.'
            );
        }

        return $this->checkMinimum($lease, $amount);
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function checkArrangement(Lease $lease, Money $amount): array
    {
        $hasArrangement = DB::table('payment_arrangements')
            ->where('lease_id', $lease->id)
            ->where('status', 'active')
            ->exists();

        if (! $hasArrangement) {
            return $this->no(
                'Part payments on this account need an agreed arrangement. '
                .'Please contact the office to set one up.'
            );
        }

        return $this->checkMinimum($lease, $amount);
    }

    /**
     * Has an admin reviewed this ledger for the period we are in?
     *
     * The period, not a timestamp: the interpretation is "reviewed for the
     * current period", so a review done in July does not license a part payment
     * in August. Recording it as a period means nothing has to clear a flag
     * every month, and no call site has to redo the arithmetic (D-24).
     */
    private function ledgerReviewedThisPeriod(Lease $lease): bool
    {
        return $lease->ledger_reviewed_period === $this->calendar->currentPeriod();
    }

    private function policyExpired(Lease $lease): bool
    {
        return $lease->partial_policy_expires_on !== null
            && $this->calendar->today()->greaterThan(
                $lease->partial_policy_expires_on->startOfDay()
            );
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function yes(): array
    {
        return ['allowed' => true, 'reason' => null];
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function no(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
