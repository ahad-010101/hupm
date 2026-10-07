<?php

namespace App\Console\Commands;

use App\Domain\Ledger\LedgerService;
use App\Domain\Payments\AllocationService;
use App\Support\AuditLogger;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Make a month the opening period, and clear behind it.  [WP-51]
 *
 * `BalanceOfPeriods` answers "what is due" from each lease's start date. That
 * is right, and it is also why the first nightly run against the real
 * portfolio posted **3,474 rent charges reaching back to 2007** — the
 * tenancies genuinely run that far, and this system was not keeping the books
 * for any of it.
 *
 * Two halves that must happen together, which is why they are one command:
 *
 *   1. Delete everything dated before the boundary.
 *   2. Set `charges.post_from_period`, so tonight's run does not rebuild it.
 *
 * Doing only the first is the trap. The ledger would look right until 01:00
 * and be wrong again by morning, with nothing to connect the two.
 *
 * **It does not touch the leases.** A start date is a fact about a tenancy and
 * not ours to rewrite to make a report tidier. The boundary lives in a setting
 * precisely so the lease data stays true.
 */
class LedgerOpenFrom extends Command
{
    protected $signature = 'hupm:ledger-open-from
        {period : The first month to keep, as YYYY-MM}
        {--dry-run : Show what would go, change nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Set the opening month and delete every ledger entry and payment before it';

    public function handle(
        Settings $settings,
        AuditLogger $audit,
        LedgerService $ledger,
        AllocationService $allocations,
    ): int
    {
        $period = trim((string) $this->argument('period'));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            $this->components->error("'{$period}' is not a month. Use YYYY-MM, for example 2026-10.");

            return self::FAILURE;
        }

        $boundary = CarbonImmutable::parse($period.'-01')->startOfDay();

        if ($boundary->isFuture()) {
            $this->components->error('That month has not started yet. Rent for it would be deleted the moment it posted.');

            return self::FAILURE;
        }

        $cutoff = $boundary->toDateString();

        $entries = DB::table('ledger_entries')->whereDate('posted_on', '<', $cutoff);
        $payments = DB::table('payments')->where('submitted_at', '<', $boundary);

        $counts = [
            'ledger_entries' => (clone $entries)->count(),
            'payments' => (clone $payments)->count(),
        ];

        $keeping = DB::table('ledger_entries')->whereDate('posted_on', '>=', $cutoff)->count();
        $earliest = DB::table('ledger_entries')->min('posted_on');

        $this->components->info("Opening month: {$period}");
        $this->line(sprintf('  %-22s %s', 'earliest entry now', $earliest ?: '—'));
        $this->line(sprintf('  %-22s %s', 'entries before '.$period, number_format($counts['ledger_entries'])));
        $this->line(sprintf('  %-22s %s', 'payments before '.$period, number_format($counts['payments'])));
        $this->line(sprintf('  %-22s %s', 'entries kept', number_format($keeping)));

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->components->info('Dry run. Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->components->warn('This cannot be undone. Ledger rows have no soft delete and no reversal path.');

            if ($this->ask('Type the word ERASE to continue') !== 'ERASE') {
                $this->components->info('Stopped. Nothing was changed.');

                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($boundary, $ledger, $allocations) {
            // [I-2] Through the owning services. LedgerService is the only
            // class permitted to write `ledger_entries`, AllocationService the
            // only one permitted to write `payment_allocations`, and an
            // architecture test enforces both.
            //
            // Allocations first: `charge_entry_id` references `ledger_entries`
            // with RESTRICT, so one left standing blocks its charge. The
            // self-referencing `reverses_entry_id` is handled inside
            // eraseEntries(), where the rest of that table's rules live.
            $allocations->eraseAllocations($boundary);
            $ledger->eraseEntries($boundary);

            DB::table('payments')->where('submitted_at', '<', $boundary)->delete();
            DB::table('delinquency_events')->where('created_at', '<', $boundary)->delete();
        });

        // Set AFTER the delete. If the delete fails the boundary never moves,
        // and a re-run finds the same state rather than a half-applied one.
        $settings->set('charges.post_from_period', $period);

        $audit->record('ledger.opening_period.set', null, [
            'period' => $period,
            'deleted' => $counts,
            'note' => 'Earlier months belong to the previous system; charges no longer post before this.',
        ]);

        $this->newLine();
        $this->components->info("Done. {$period} is now the opening month.");
        $this->line('  Balances show this month onwards, and the nightly run will not go back behind it.');

        return self::SUCCESS;
    }
}
