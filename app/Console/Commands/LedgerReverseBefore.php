<?php

namespace App\Console\Commands;

use App\Domain\Ledger\LedgerService;
use App\Models\LedgerEntry;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reverse everything before a month, rather than deleting it.  [WP-52]
 *
 * The history is real: these tenancies run back to 2007 and the rent was
 * genuinely charged. It was also **settled outside this system**, in whatever
 * kept the books at the time, so carrying it as outstanding would show every
 * resident owing years of rent they do not owe.
 *
 * **Reversed, not deleted — which is the whole point.** I-3 says ledger rows
 * are immutable and corrections are reversing entries, never edits or deletes.
 * Deleting makes the balance right and the record false: nobody can later ask
 * what was charged in March 2019 or why it stopped counting. A reversal leaves
 * both rows standing, each with its reason, and nets them to zero. It is also
 * what the client would otherwise do by hand, one entry at a time.
 *
 * Each reversal is posted on the **original entry's date**, so every historical
 * period nets to zero on its own and the opening month stands alone in the
 * reports rather than carrying thousands of offsetting lines.
 *
 * Idempotent by construction: `LedgerService::reverse()` refuses an entry that
 * already has a reversal, so a second run finds nothing to do (I-8).
 */
class LedgerReverseBefore extends Command
{
    protected $signature = 'hupm:ledger-reverse-before
        {period : Reverse everything before this month, as YYYY-MM}
        {--reason= : The reason recorded on every reversal}
        {--dry-run : Show what would be reversed, change nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Post a reversing entry for every ledger row before a given month';

    private const DEFAULT_REASON = 'Settled outside this system before go-live; '
        .'carried here as history rather than as an amount owed.';

    public function handle(LedgerService $ledger, AuditLogger $audit): int
    {
        $period = trim((string) $this->argument('period'));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            $this->components->error("'{$period}' is not a month. Use YYYY-MM, for example 2026-10.");

            return self::FAILURE;
        }

        $reason = trim((string) ($this->option('reason') ?: self::DEFAULT_REASON));
        $cutoff = CarbonImmutable::parse($period.'-01')->toDateString();

        // A reversal is itself an entry before the cutoff, so it must never be
        // a candidate — and an entry already reversed is already settled.
        $targets = LedgerEntry::query()
            ->whereDate('posted_on', '<', $cutoff)
            ->where('type', '!=', 'reversal')
            ->whereNotIn('id', LedgerEntry::query()->whereNotNull('reverses_entry_id')->select('reverses_entry_id'))
            ->orderBy('id');

        $total = (clone $targets)->count();

        $this->components->info("Reversing everything posted before {$period}");
        $this->line(sprintf('  %-26s %s', 'entries to reverse', number_format($total)));
        $this->line(sprintf('  %-26s %s', 'already reversed', number_format(
            LedgerEntry::whereDate('posted_on', '<', $cutoff)->whereNotNull('reverses_entry_id')->count()
        )));
        $this->line(sprintf('  %-26s %s', 'entries from '.$period.' on', number_format(
            LedgerEntry::whereDate('posted_on', '>=', $cutoff)->count()
        )));
        $this->line('  reason: '.$reason);

        if ($total === 0) {
            $this->components->info('Nothing to reverse.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run. Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && $this->ask('Type the word REVERSE to continue') !== 'REVERSE') {
            $this->components->info('Stopped. Nothing was changed.');

            return self::FAILURE;
        }

        $done = 0;
        $failed = [];
        $bar = $this->output->createProgressBar($total);

        // Chunked by id rather than loaded whole: 3,400 entries plus their
        // reversals is not a set to hold in memory on shared hosting. Each
        // reversal is its own transaction inside LedgerService, so one bad row
        // is skipped and reported rather than losing the run.
        (clone $targets)->chunkById(200, function ($entries) use ($ledger, $reason, &$done, &$failed, $bar) {
            foreach ($entries as $entry) {
                try {
                    $ledger->reverse($entry, $reason, CarbonImmutable::parse($entry->posted_on->toDateString()));
                    $done++;
                } catch (Throwable $e) {
                    $failed[] = ['entry_id' => $entry->id, 'error' => $e->getMessage()];
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $audit->record('ledger.reversed_before', null, [
            'period' => $period,
            'reversed' => $done,
            'failed' => count($failed),
            'reason' => $reason,
        ]);

        if ($failed !== []) {
            $this->components->warn(count($failed).' entr(ies) could not be reversed:');

            foreach (array_slice($failed, 0, 5) as $f) {
                $this->line('  #'.$f['entry_id'].' — '.$f['error']);
            }
        }

        $this->components->info("Reversed {$done} entr(ies). Both sides stay on the ledger; they net to zero.");

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
