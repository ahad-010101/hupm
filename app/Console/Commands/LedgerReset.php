<?php

namespace App\Console\Commands;

use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Empty the ledger, keeping the portfolio.  [WP-50]
 *
 * Asked for before go-live: the leases, tenants, units and properties are the
 * real ones, but the money on them is test data and has to start at zero.
 *
 * **Both sides go, and that is the point.** A balance is `SUM(amount)` over
 * ledger rows at read time (I-1), so the only way to make one zero is to remove
 * the rows. Deleting the payments alone would leave every resident owing their
 * full arrears with nothing recorded as paid — worse than either outcome, and
 * the mistake this command exists to prevent somebody making by hand.
 *
 * **It breaks I-3 deliberately and openly.** Ledger rows are immutable and
 * corrections are reversing entries, never deletes. That rule protects a
 * *real* ledger; it is not a reason to carry test data into production for
 * ever. So this is a named, audited, confirmed operation rather than somebody
 * typing DELETE into tinker at eleven at night — and the audit row it writes
 * is deliberately not deleted, so the wipe itself leaves a trace.
 *
 * What it does NOT touch:
 *   - tenants, leases, units, properties — the portfolio is real
 *   - `lease_charge_schedules` — what to charge is lease configuration, not
 *     money. Rent starts again from the next scheduled run.
 *   - `payment_profiles` — gateway tokens. Deleting the rows would orphan
 *     real profiles at Authorize.Net and make every resident re-enter their
 *     bank details. They hold no account numbers to begin with (I-5).
 *   - `notification_logs` — what was sent was sent; rewriting that history
 *     would be a lie about what residents received.
 */
class LedgerReset extends Command
{
    protected $signature = 'hupm:ledger-reset
        {--dry-run : Show what would go, change nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete every ledger entry, payment and allocation, keeping tenants and leases';

    /**
     * Deepest first, so the foreign keys are satisfied without lifting the
     * constraint check. If an order is ever wrong this fails loudly on a real
     * constraint rather than silently orphaning rows.
     */
    private const CLEAR = [
        'payment_allocations',
        'ledger_entries',
        'payments',
        // Derived from balances that will no longer exist. Left behind, a
        // lease stays in Management Review against nothing.
        'delinquency_events',
        // An agreement about arrears. With the arrears gone it would still
        // satisfy `under_arrangement_only`, licensing part payments against a
        // balance nobody owes.
        'payment_arrangements',
    ];

    /** Reported so the operator can see them, never touched. */
    private const KEPT = [
        'tenants', 'leases', 'units', 'properties',
        'lease_charge_schedules', 'payment_profiles', 'notification_logs',
        'recurring_payments',
    ];

    public function handle(AuditLogger $audit): int
    {
        $counts = [];

        foreach (self::CLEAR as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        $this->components->info('Rows that would be deleted');

        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %-24s %s', $table, number_format($count)));
        }

        $this->newLine();
        $this->components->info('Kept, for comparison');

        foreach (self::KEPT as $table) {
            $this->line(sprintf('  %-24s %s', $table, number_format(DB::table($table)->count())));
        }

        // The two lease columns that decide whether a resident can pay at all.
        // Resetting the ledger without them leaves people blocked from paying
        // a balance of zero, which is the worst outcome available here.
        $inReview = DB::table('leases')->where('delinquency_state', 'management_review')->count();
        $reviewGated = DB::table('leases')->where('ledger_review_required', true)->count();

        $this->newLine();
        $this->line(sprintf('  %-24s %s → current', 'leases in review', $inReview));

        if ($reviewGated > 0) {
            $this->components->warn(
                $reviewGated.' lease(s) have `ledger_review_required` set. That flag is a policy '
                .'about the tenancy, not about the ledger, so it is left alone — but the period '
                .'marker is cleared, so an admin must mark those ledgers reviewed again before '
                .'those residents can make a part payment.'
            );
        }

        if (array_sum($counts) === 0 && $inReview === 0) {
            $this->components->info('Nothing to do — the ledger is already empty.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->components->info('Dry run. Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->components->warn('This cannot be undone. Ledger rows have no soft delete and no reversal path.');

            if ($this->ask('Type the word ERASE to continue') !== 'ERASE') {
                $this->components->info('Stopped. Nothing was changed.');

                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($counts) {
            // `ledger_entries.reverses_entry_id` points at another row in the
            // same table: a correction is a reversing entry, never an edit
            // (I-3). A bulk delete therefore fails on its own foreign key —
            // MySQL will not drop a parent while a child still references it,
            // and every row here is both. Dropping the pointer first is safe
            // precisely because all of them are going.
            //
            // Found on production, where 129 reversing entries existed. The
            // first version of this command had no such rows in its fixtures
            // and passed every test.
            DB::table('ledger_entries')
                ->whereNotNull('reverses_entry_id')
                ->update(['reverses_entry_id' => null]);

            foreach (self::CLEAR as $table) {
                DB::table($table)->delete();
            }

            // Back to the default the leases table declares. A resident with a
            // zero balance who still cannot pay online is a support call
            // nobody will connect to this command a week later.
            DB::table('leases')
                ->where('delinquency_state', '!=', 'current')
                ->update(['delinquency_state' => 'current']);

            // The review referred to a ledger that no longer exists.
            DB::table('leases')
                ->whereNotNull('ledger_reviewed_period')
                ->update(['ledger_reviewed_period' => null, 'ledger_reviewed_at' => null]);

            unset($counts);
        });

        // Written after the transaction and never cleared by it: a wipe that
        // left no trace would be indistinguishable from data loss.
        $audit->record('ledger.reset', null, [
            'deleted' => $counts,
            'leases_returned_to_current' => $inReview,
            'note' => 'Ledger emptied before go-live. Tenants, leases and the portfolio kept.',
        ]);

        $this->newLine();
        $this->components->info('Done. Every balance is now $0.00 and the audit log records this.');
        $this->line('  Rent resumes from the next scheduled charge run (01:00 daily).');

        return self::SUCCESS;
    }
}
