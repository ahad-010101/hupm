<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * WP-34 — the final grep for bank data, across the database and the logs. [I-5]
 *
 * The architecture tests already forbid a *column* named `account_number`,
 * `routing`, `card_number`, `cvv`, `iban` or `sort_code`. This asks the harder
 * question: is a bank number sitting in a column that is perfectly innocent by
 * name — a ticket description, an adjustment reason, an audit context, a
 * notice body. That is not a schema property, so no architecture test can see
 * it; it depends on what people typed.
 *
 * It is how the number actually gets in. Nobody adds a `routing_number`
 * column. An administrator pastes "returned, acct 900012345678 rtg 061000052"
 * into an adjustment description because that is what the bank's email said,
 * and it is now in the ledger forever, because ledger rows are immutable
 * (I-3).
 *
 * Run it at go-live (WP-35) and after any support episode involving a return.
 * Exits non-zero on a finding, so it can gate a deploy.
 *
 *   php artisan hupm:bank-data-sweep
 *   php artisan hupm:bank-data-sweep --logs-only
 */
class BankDataSweep extends Command
{
    protected $signature = 'hupm:bank-data-sweep
        {--logs-only : Skip the database and read only storage/logs}
        {--context=60 : Characters of surrounding text to show for a finding}';

    protected $description = 'Search the database and logs for anything shaped like a bank account or routing number [I-5]';

    /**
     * An ABA routing number is exactly nine digits; US account numbers run
     * from about seven to seventeen. So "seven or more consecutive digits" is
     * the net, and it is deliberately wide — I-5 admits no exception, and the
     * cost of a false positive is one person reading one line.
     */
    private const BANK_NUMBER = '/\d{7,}/';

    /**
     * Columns whose contents are digits by nature and are not bank data.
     *
     * Listed as `table.column` and kept short on purpose: every entry here is
     * a place this sweep has been told not to look, so each one needs to be
     * obviously safe. A gateway transaction id is Authorize.Net's own
     * reference and carries no account information.
     */
    private const EXEMPT = [
        'payments.gateway_transaction_id',
        'payments.idempotency_key',
        'ledger_entries.charge_key',
        'sessions.payload',
        'cache.value',
        'cache_locks.owner',
        'jobs.payload',
        'failed_jobs.payload',
        'job_batches.options',
        'migrations.migration',
    ];

    public function handle(): int
    {
        $findings = [];

        if (! $this->option('logs-only')) {
            $findings = array_merge($findings, $this->sweepDatabase());
        }

        $findings = array_merge($findings, $this->sweepLogs());

        if ($findings === []) {
            $this->info('Nothing shaped like a bank account or routing number was found. [I-5]');

            return self::SUCCESS;
        }

        $this->error(count($findings).' possible bank number(s) found. I-5 admits no exception.');
        $this->newLine();

        foreach ($findings as $finding) {
            $this->line("  <fg=yellow>{$finding['where']}</>");
            $this->line('    '.$finding['excerpt']);
        }

        $this->newLine();
        $this->line('A ledger row cannot be edited (I-3). A correction is a reversing entry,');
        $this->line('and the original text stays — so fix the habit, not only the row.');

        return self::FAILURE;
    }

    /** @return list<array{where: string, excerpt: string}> */
    private function sweepDatabase(): array
    {
        $findings = [];
        $database = DB::getDatabaseName();

        $columns = DB::table('information_schema.columns')
            ->select('TABLE_NAME as table_name', 'COLUMN_NAME as column_name')
            ->where('TABLE_SCHEMA', $database)
            // Text-bearing columns only. A DECIMAL amount of 9000123.45 is a
            // sum of money, not an account number, and including numeric
            // columns would drown a real finding in every large figure.
            ->whereIn('DATA_TYPE', ['char', 'varchar', 'text', 'mediumtext', 'longtext', 'json'])
            ->orderBy('TABLE_NAME')
            ->orderBy('ORDINAL_POSITION')
            ->get();

        $this->line("Reading {$columns->count()} text columns in {$database}…");

        foreach ($columns as $column) {
            $qualified = "{$column->table_name}.{$column->column_name}";

            if (in_array($qualified, self::EXEMPT, true)) {
                continue;
            }

            $rows = DB::table($column->table_name)
                ->select('*')
                // REGEXP in MySQL, so the scan happens in the database rather
                // than pulling every row of every table into PHP. On 26
                // tenants either would do; on a restored production dump the
                // difference is the command finishing.
                ->whereRaw("`{$column->column_name}` REGEXP '[0-9]{7,}'")
                ->limit(50)
                ->get();

            foreach ($rows as $row) {
                $value = (string) ($row->{$column->column_name} ?? '');

                if (! preg_match(self::BANK_NUMBER, $value, $match, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $findings[] = [
                    'where' => $qualified.' (row '.($row->id ?? '?').')',
                    'excerpt' => $this->excerpt($value, $match[0][1]),
                ];
            }
        }

        return $findings;
    }

    /** @return list<array{where: string, excerpt: string}> */
    private function sweepLogs(): array
    {
        $findings = [];
        $directory = storage_path('logs');

        if (! File::isDirectory($directory)) {
            return [];
        }

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'log') {
                continue;
            }

            $handle = fopen($file->getPathname(), 'r');

            if ($handle === false) {
                continue;
            }

            $number = 0;

            // Line by line rather than file_get_contents: a fortnight of daily
            // logs on a busy day is larger than this command should assume it
            // can hold.
            while (($line = fgets($handle)) !== false) {
                $number++;

                if (! preg_match(self::BANK_NUMBER, $line, $match, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $findings[] = [
                    'where' => $file->getFilename().':'.$number,
                    'excerpt' => $this->excerpt(rtrim($line, "\r\n"), $match[0][1]),
                ];
            }

            fclose($handle);
        }

        return $findings;
    }

    /**
     * The text around a match, with the digits themselves masked.
     *
     * Printing the number to a terminal — and from there into a ticket, or a
     * screenshot, or a scrollback buffer — would be the sweep committing the
     * offence it is reporting.
     */
    private function excerpt(string $value, int $offset): string
    {
        $context = max(10, (int) $this->option('context'));
        $start = max(0, $offset - $context);

        $slice = mb_substr($value, $start, $context * 2);
        $slice = preg_replace(self::BANK_NUMBER, '[redacted]', $slice);

        return ($start > 0 ? '…' : '').trim((string) $slice).'…';
    }
}
