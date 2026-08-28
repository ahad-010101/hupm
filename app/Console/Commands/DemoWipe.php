<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Take the demo portfolio back out, leaving a clean system.  [WP-01D]
 *
 * The counterpart to `hupm:demo-production`. What survives is everything that
 * is configuration rather than data:
 *
 *   - the admin accounts, so you are not locked out
 *   - `settings`, which is every gated client decision and its default — an
 *     unseeded install has no configuration at all
 *   - `content_pages` / `page_sections`, or the public site renders with no
 *     words on it
 *   - `countries` / `states` / `cities`, because reseeding them is 155,000
 *     rows and half a minute, and nothing in them is demo data
 *   - `housing_authorities`, which is reference data
 *   - `migrations`, obviously
 *
 * Everything else is emptied.
 *
 * Deletes rather than `migrate:fresh` on purpose. A fresh migrate would drop
 * the schema and take the settings, the site content and the address tables
 * with it, and you would then have to remember to reseed three separate things
 * before the site worked again. This leaves a system that is immediately
 * usable and genuinely empty of tenants.
 */
class DemoWipe extends Command
{
    protected $signature = 'hupm:demo-wipe
        {--force : Skip the confirmation prompt}
        {--keep-admin=* : Email addresses to preserve (default: every admin)}';

    protected $description = 'Remove all tenant, lease and financial data, keeping the admin and the configuration';

    /**
     * Emptied, in an order that would satisfy the foreign keys even without
     * the constraint check being lifted below. Kept in dependency order
     * anyway: if the disable ever fails, this fails loudly on a real
     * constraint rather than silently orphaning rows.
     */
    private const WIPE = [
        // Money, deepest first.
        'payment_allocations',
        'ledger_entries',
        'payments',
        'payment_profiles',
        'payment_arrangements',
        'recurring_payments',
        'lease_charge_schedules',
        'delinquency_events',
        // Operations.
        'maintenance_attachments',
        'maintenance_events',
        'maintenance_requests',
        'signature_events',
        'signature_requests',
        'consent_records',
        'documents',
        'notice_recipients',
        'notice_attachments',
        'notices',
        'notification_logs',
        // The portfolio.
        'leases',
        'units',
        'properties',
        'tenants',
        // Traces of it all.
        'audit_logs',
        'exception_acknowledgements',
        'weather_alerts',
        'job_runs',
        'imports',
        'counters',
        // Runtime state that would otherwise reference deleted rows.
        'sessions',
        'password_reset_tokens',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
    ];

    /** Left alone. Configuration and reference data, not demo data. */
    private const KEEP = [
        'users',            // filtered below rather than emptied
        'settings',
        'content_pages',
        'page_sections',
        'housing_authorities',
        'countries',
        'states',
        'cities',
        'timezones',
        'currencies',
        'languages',
        'migrations',
    ];

    public function handle(): int
    {
        $keepEmails = $this->option('keep-admin');

        $survivors = DB::table('users')
            ->when($keepEmails, fn ($q) => $q->whereIn('email', $keepEmails))
            ->when(! $keepEmails, fn ($q) => $q->where('role', 'admin'))
            ->pluck('email', 'id');

        if ($survivors->isEmpty()) {
            $this->components->error('No account would survive this. Refusing — that would lock you out.');
            $this->line($keepEmails
                ? '  None of the addresses given matched a user.'
                : '  No user has role=admin. Pass --keep-admin=you@example.com to name one.');

            return self::FAILURE;
        }

        $this->components->warn('This permanently deletes every tenant, lease, payment and ledger entry.');
        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Accounts kept</>', $survivors->implode(', '));
        $this->components->twoColumnDetail('Tenants to delete', (string) DB::table('tenants')->count());
        $this->components->twoColumnDetail('Ledger entries to delete', (string) DB::table('ledger_entries')->count());
        $this->components->twoColumnDetail('Kept intact', 'settings, site content, address tables');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Delete all of it?', false)) {
            $this->components->info('Nothing was deleted.');

            return self::SUCCESS;
        }

        /*
         | Constraint checks off for the duration.
         |
         | The order above would satisfy them anyway, but a schema gains
         | tables, and a wipe that half-completes because somebody added a
         | foreign key last month is worse than one that runs to the end.
        */
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $emptied = 0;

        try {
            foreach (self::WIPE as $table) {
                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                // TRUNCATE, so AUTO_INCREMENT restarts and the next demo run
                // produces the same ids as the last one.
                DB::table($table)->truncate();
                $emptied++;
            }

            $removed = DB::table('users')->whereNotIn('id', $survivors->keys())->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->deleteStoredFiles();

        $this->newLine();
        $this->components->twoColumnDetail('Tables emptied', (string) $emptied);
        $this->components->twoColumnDetail('User accounts removed', (string) $removed);
        $this->components->twoColumnDetail('Tenants remaining', (string) DB::table('tenants')->count());
        $this->components->twoColumnDetail('Settings remaining', (string) DB::table('settings')->count());
        $this->components->twoColumnDetail('Countries remaining', (string) DB::table('countries')->count());
        $this->newLine();
        $this->components->info('Clean. Sign in with an account listed above.');
        $this->components->warn('Run `php artisan optimize:clear` if anything looks stale — the cache table was emptied.');

        return self::SUCCESS;
    }

    /**
     * The uploaded files that belonged to those rows.
     *
     * Emptying `documents` and `maintenance_attachments` leaves the files
     * themselves on disk with nothing pointing at them — invisible, counted
     * against the account's quota forever, and still readable by anyone who
     * later gets a path. Demo runs generate them repeatedly.
     *
     * Scoped to the two directories the application writes uploads into.
     * Nothing else under storage/app/private is touched.
     */
    private function deleteStoredFiles(): void
    {
        foreach (['documents', 'maintenance'] as $directory) {
            if (Storage::disk('local')->exists($directory)) {
                Storage::disk('local')->deleteDirectory($directory);
                $this->components->twoColumnDetail('Deleted uploads', "storage/app/private/{$directory}");
            }
        }
    }
}
