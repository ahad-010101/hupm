<?php

namespace App\Domain\Leases;

use App\Models\Lease;
use App\Models\Unit;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
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

            return $lease;
        });
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
