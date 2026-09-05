<?php

namespace App\Domain\Charges;

use App\Domain\Ledger\LedgerService;
use App\Models\ChargeBatch;
use App\Models\ChargeType;
use App\Models\Lease;
use App\Models\LeaseChargeSchedule;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Charging many leases at once.  [WP-41, FR-CHG-01]
 *
 * Two timings, one entry point, because to the admin they are one decision made
 * on one screen:
 *
 *   **once** — post now, to every selected lease, in one transaction, recorded
 *   as a `ChargeBatch` so the whole thing can be taken back in one act.
 *
 *   **monthly** — write a `lease_charge_schedule` per lease and let the existing
 *   nightly job post it from then on. No second engine: `ChargePostingService`
 *   has done this since WP-10 and is untouched by this class.
 *
 * Everything here goes through `LedgerService` (I-2) and nothing edits or
 * deletes a ledger row (I-3).
 */
class BulkChargeService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Post a one-off charge to each lease, now.
     *
     * @param  Collection<int, Lease>  $leases
     */
    public function postOnce(
        Collection $leases,
        ?ChargeType $type,
        string $category,
        string $description,
        Money $amount,
        string $payer,
        ?User $actor = null,
    ): ChargeBatch {
        $this->assertPostable($leases, $amount);

        return DB::transaction(function () use ($leases, $type, $category, $description, $amount, $payer, $actor) {
            $batch = new ChargeBatch;
            $batch->forceFill([
                'charge_type_id' => $type?->id,
                // Copied, so the batch still reads correctly after the type is
                // edited or retired.
                'description' => $description,
                'amount' => $amount->toDecimalString(),
                'payer' => $payer,
                'lease_count' => $leases->count(),
                'total_amount' => $amount->times($leases->count())->toDecimalString(),
                'posted_by_user_id' => $actor?->id,
                'posted_at' => now(),
            ])->save();

            foreach ($leases as $lease) {
                $this->ledger->postCharge(
                    $lease,
                    $category,
                    $payer,
                    $amount,
                    $description,
                    // The batch id is the whole association (see ChargeBatch::entries).
                    "{$lease->id}:batch{$batch->id}",
                    $this->calendar->today(),
                );
            }

            $this->audit->record('charge.batch.posted', $batch, [
                'description' => $description,
                'amount' => $amount->toDecimalString(),
                'payer' => $payer,
                'lease_count' => $leases->count(),
                'total' => $batch->total_amount->toDecimalString(),
            ]);

            return $batch;
        });
    }

    /**
     * Take a whole batch back.  [I-3]
     *
     * Reversing entries, never deletes: every original stays on the ledger
     * beside the row that cancels it, which is the only way a resident asking
     * "what was that £25 in September" gets a true answer.
     *
     * Idempotent on the batch: a second attempt is refused rather than posting
     * a second set of reversals, which would charge everybody again.
     */
    public function reverseBatch(ChargeBatch $batch, string $reason, ?User $actor = null): int
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reversing a batch requires a reason.');
        }

        if ($batch->isReversed()) {
            throw new InvalidArgumentException('That batch has already been reversed.');
        }

        return DB::transaction(function () use ($batch, $reason, $actor) {
            $reversed = 0;

            foreach ($batch->entries()->get() as $entry) {
                // An entry already reversed individually is skipped rather than
                // reversed twice — the ledger would balance, but the resident
                // would see two credits for one charge.
                if (LedgerEntry::where('reverses_entry_id', $entry->id)->exists()) {
                    continue;
                }

                $this->ledger->reverse($entry, $reason);
                $reversed++;
            }

            $batch->forceFill([
                'reversed_at' => now(),
                'reversed_by_user_id' => $actor?->id,
                'reversed_reason' => $reason,
            ])->save();

            $this->audit->record('charge.batch.reversed', $batch, [
                'reason' => $reason,
                'entries_reversed' => $reversed,
            ]);

            return $reversed;
        });
    }

    /**
     * Set a monthly charge on each lease, or update it where one already exists.
     *
     * `updateOrCreate` on (lease_id, charge_type_id) is what makes re-running
     * the screen at a new price an amendment rather than a second $25 line on
     * somebody's ledger every month forever. The unique index behind it means
     * that holds even with two admins pressing the button at once.
     *
     * @param  Collection<int, Lease>  $leases
     * @return array{created: int, updated: int}
     */
    public function scheduleMonthly(
        Collection $leases,
        ChargeType $type,
        string $category,
        string $description,
        Money $amount,
        string $payer,
        ?User $actor = null,
    ): array {
        $this->assertPostable($leases, $amount);

        return DB::transaction(function () use ($leases, $type, $category, $description, $amount, $payer, $actor) {
            $created = 0;
            $updated = 0;

            foreach ($leases as $lease) {
                $existing = LeaseChargeSchedule::where('lease_id', $lease->id)
                    ->where('charge_type_id', $type->id)
                    ->first();

                LeaseChargeSchedule::updateOrCreate(
                    ['lease_id' => $lease->id, 'charge_type_id' => $type->id],
                    [
                        'category' => $category,
                        'description' => $description,
                        'amount' => $amount->toDecimalString(),
                        'payer' => $payer,
                        // Re-running the screen also revives a schedule somebody
                        // had switched off, which is what "charge these people
                        // monthly" plainly means.
                        'active' => true,
                    ],
                );

                $existing ? $updated++ : $created++;
            }

            $this->audit->record('charge.schedule.applied', $type, [
                'description' => $description,
                'amount' => $amount->toDecimalString(),
                'payer' => $payer,
                'created' => $created,
                'updated' => $updated,
                'by' => $actor?->id,
            ]);

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * Stop a monthly charge without touching anything already posted.
     *
     * The counterpart to reversing a batch. Deactivating is not an undo — the
     * charges already on residents' ledgers were real and stay — it simply ends
     * the arrangement from the next run.
     */
    public function deactivateSchedules(ChargeType $type, ?User $actor = null): int
    {
        $count = LeaseChargeSchedule::where('charge_type_id', $type->id)
            ->where('active', true)
            ->update(['active' => false, 'updated_at' => now()]);

        $this->audit->record('charge.schedule.deactivated', $type, [
            'schedules_stopped' => $count,
            'by' => $actor?->id,
            'note' => 'Charges already posted are unaffected.',
        ]);

        return $count;
    }

    /** @param Collection<int, Lease> $leases */
    private function assertPostable(Collection $leases, Money $amount): void
    {
        if ($leases->isEmpty()) {
            throw new InvalidArgumentException('Choose at least one resident to charge.');
        }

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A charge must be more than zero.');
        }
    }
}
