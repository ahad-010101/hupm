<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Put the demo portfolio on a live server, for showing the client.  [WP-01D]
 *
 * `hupm:demo` refuses outside local, and that guard is correct — it can drop
 * every table. This is the deliberate exception: a separate command, so the
 * decision to put synthetic data on a production box is something somebody
 * typed on purpose rather than a flag they forgot was set.
 *
 * Three things it does that `hupm:demo` does not need to:
 *
 *   1. **Leaves the mail driver alone** — operator decision, 28 Aug 2026. An
 *      earlier version forced it to `log` for the duration, because the seeder
 *      drives the real services and 26 invented addresses produce 26 bounces
 *      on a freshly verified domain. That was overridden deliberately: mail
 *      stays on whatever is configured. Worth knowing rather than rediscovering
 *      if the Resend domain reputation is ever queried.
 *   2. **Refuses if the ledger already has entries.** Once real money is
 *      recorded, synthetic tenants cannot be cleanly removed: ledger rows are
 *      immutable (I-3), so a correction is a reversing entry, never a delete.
 *      Past that point this command is not recoverable and will not run.
 *   3. **Leaves APP_ENV alone.** Flipping it to `local` to get past the guard
 *      also turns on developer error pages, which on a public site means a
 *      stack trace carrying queries — and this application's queries carry
 *      money and names.
 *
 * Undo with `hupm:demo-wipe`, which keeps the admin account, the settings, the
 * public site content and the address tables.
 */
class DemoProduction extends Command
{
    protected $signature = 'hupm:demo-production {--force : Skip the confirmation prompt}';

    protected $description = 'Seed the demo portfolio on a live server, for a client walkthrough';

    public function handle(): int
    {
        $ledger = DB::table('ledger_entries')->count();

        if ($ledger > 0) {
            $this->components->error("The ledger holds {$ledger} entries. Refusing.");
            $this->line('  Ledger rows are immutable (I-3) — a correction is a reversing entry, never');
            $this->line('  a delete. Once real money is recorded, synthetic tenants cannot be taken');
            $this->line('  back out, so this command will not put them in.');

            return self::FAILURE;
        }

        $tenants = DB::table('tenants')->count();

        if ($tenants > 0) {
            $this->components->warn("There are already {$tenants} tenant rows.");
        }

        $this->components->warn('This writes SYNTHETIC tenants, leases and payments to '.app()->environment().'.');
        $this->line('  The mail driver is left exactly as configured (operator decision, 28 Aug 2026).');
        $this->line('  Undo with: php artisan hupm:demo-wipe');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Seed demo data here?', false)) {
            $this->components->info('Nothing was written.');

            return self::SUCCESS;
        }

        /*
         | DemoDataSeeder THROWS outside local — it does not merely skip:
         |
         |     throw new RuntimeException('DemoDataSeeder must never run
         |     outside local or testing.');
         |
         | That guard is right and stays exactly as it is. This command is the
         | considered decision it asks for, so it reports the environment as
         | local for the seconds the seeder runs and restores it in a `finally`
         | — whether the seeder succeeded or threw.
        */
        $envWas = Config::get('app.env');

        try {
            Config::set('app.env', 'local');
            app()->detectEnvironment(fn () => 'local');

            $seeder = app(DemoDataSeeder::class);
            $seeder->setCommand($this);
            $seeder->run();
        } finally {
            Config::set('app.env', $envWas);
            app()->detectEnvironment(fn () => $envWas);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Properties', (string) DB::table('properties')->count());
        $this->components->twoColumnDetail('Units', (string) DB::table('units')->count());
        $this->components->twoColumnDetail('Tenants', (string) DB::table('tenants')->count());
        $this->components->twoColumnDetail('Ledger entries', (string) DB::table('ledger_entries')->count());
        $this->newLine();
        $this->components->warn('Synthetic data on a live server. Run hupm:demo-wipe before any real tenant is entered.');

        return self::SUCCESS;
    }
}
