<?php

namespace App\Domain\Leases;

use App\Domain\Ledger\LedgerService;
use App\Models\Lease;
use App\Models\Unit;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lease creation, amendment and termination.  [FR-REG-02, FR-REG-03]
 *
 * A lease is where the money rules are configured, so every write goes through
 * here rather than through a controller — one place for the portion arithmetic,
 * the active-lease constraint and the unit-status side effect.
 */
class LeaseService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessCalendar $calendar,
        private readonly LedgerService $ledger,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException when the unit already has an active lease
     */
    public function create(array $attributes): Lease
    {
        return DB::transaction(function () use ($attributes) {
            $lease = $this->persist(new Lease, $attributes);

            $this->syncUnitStatus($lease);
            $this->audit->record('lease.created', $lease, $attributes);
            $this->postSecurityDeposit($lease);

            return $lease;
        });
    }

    /**
     * Charge the security deposit to the tenant.  [WP-40, Q-12 closed 2026-09-04]
     *
     * Only on creation, and only while `deposits.tracked_in_ledger` is on. That
     * gate is what makes "from now on, when new leases come" true: the 27
     * leases already loaded carry deposits their tenants paid years ago, and
     * posting those would invent debt for every one of them. Nothing is
     * backfilled, and the switch stays off until the historical load is done.
     *
     * Not on `update()` either. A lease edited to correct a typo in the deposit
     * figure must not raise a second charge, and an amendment is not a new
     * tenancy.
     *
     * **`period` is deliberately NULL.** The late-fee engine reads
     * `outstandingByPeriod()`, which requires a period — so a deposit is
     * invisible to it and can never accrue a late fee. That is not an accident
     * of the schema; it is the mechanism (I-7 is about payer, this is about
     * what kind of debt earns a fee).
     */
    private function postSecurityDeposit(Lease $lease): void
    {
        if (! $this->settings->bool('deposits.tracked_in_ledger', false)) {
            return;
        }

        $deposit = $lease->security_deposit;

        if (! $deposit instanceof Money || ! $deposit->isPositive()) {
            return;
        }

        $this->ledger->postCharge(
            $lease,
            'deposit',
            // Never the housing authority. A deposit is the resident's, and
            // the HA portion has nothing to do with it.
            'tenant',
            $deposit,
            'Security deposit',
            // Idempotent on the lease, so a retried transaction cannot charge
            // a deposit twice (D-01).
            "{$lease->id}:deposit",
            $this->calendar->today(),
        );
    }

    /** @param array<string, mixed> $attributes */
    public function update(Lease $lease, array $attributes): Lease
    {
        return DB::transaction(function () use ($lease, $attributes) {
            // forceFill, not fill: Lease is fully guarded (I-11) so every write
            // comes through this service. Applying the attributes first lets
            // recordChange() capture a real before/after diff.
            $lease->forceFill($attributes);
            $this->audit->recordChange('lease.updated', $lease);

            $lease = $this->persist($lease, $attributes);
            $this->syncUnitStatus($lease);

            return $lease;
        });
    }

    /**
     * End a lease.  [FR-REG-03, AC-REG-07]
     *
     * Charge posting stops from the effective date; existing ledger entries are
     * untouched and any outstanding balance remains. Ending a tenancy is not a
     * way to make a debt disappear — the ledger is immutable (I-3) and the
     * balance survives the lease.
     */
    public function terminate(Lease $lease, CarbonImmutable $effectiveDate, ?string $reason = null): Lease
    {
        return DB::transaction(function () use ($lease, $effectiveDate, $reason) {
            $lease->forceFill([
                'status' => Lease::STATUS_ENDED,
                // end_date carries the effective date so the charge job's date
                // window excludes it from the next run without a second column.
                'end_date' => $effectiveDate->toDateString(),
            ])->save();

            $this->audit->record('lease.terminated', $lease, [
                'effective_date' => $effectiveDate->toDateString(),
                'reason' => $reason,
            ]);

            $this->syncUnitStatus($lease);

            return $lease;
        });
    }

    /**
     * Persist, translating the database's active-lease constraint into a
     * field-level error.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persist(Lease $lease, array $attributes): Lease
    {
        $lease->forceFill($attributes);

        try {
            $lease->save();
        } catch (UniqueConstraintViolationException $e) {
            // D-03: `leases.active_unit_key` is a generated column carrying
            // unit_id only while status = 'active'. The FormRequest checks this
            // too, but two admins activating a lease on the same unit at the
            // same moment both pass validation and only the database can refuse
            // the second (AC-REG-04). This turns that refusal into a message.
            if (str_contains($e->getMessage(), 'active_unit_key')) {
                throw ValidationException::withMessages([
                    'unit_id' => 'That unit already has an active lease. End the existing lease first.',
                ]);
            }

            throw $e;
        }

        return $lease;
    }

    /**
     * Keep the unit's operational status honest.
     *
     * A unit is `occupied` while it has an active lease. `off_market` is an
     * admin's deliberate choice and is never overwritten — a unit taken out of
     * service for renovation should not silently become vacant.
     */
    private function syncUnitStatus(Lease $lease): void
    {
        $unit = Unit::find($lease->unit_id);

        if (! $unit || $unit->status === Unit::STATUS_OFF_MARKET) {
            return;
        }

        $hasActiveLease = Lease::where('unit_id', $unit->id)
            ->where('status', Lease::STATUS_ACTIVE)
            ->exists();

        $unit->update([
            'status' => $hasActiveLease ? Unit::STATUS_OCCUPIED : Unit::STATUS_VACANT,
        ]);
    }
}
