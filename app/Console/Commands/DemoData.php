<?php

namespace App\Console\Commands;

use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorldSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * One command to get a working local system.  [WP-01D]
 *
 * Refuses outside local/testing, the same as the seeder it calls — a
 * --fresh here drops every table, and the guard is worth having twice.
 */
class DemoData extends Command
{
    protected $signature = 'hupm:demo {--fresh : Drop all tables and rebuild from scratch}';

    protected $description = 'Seed a realistic local portfolio (25 properties, 27 units, 26 tenants)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('hupm:demo is local-only. Refusing to run in '.app()->environment().'.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->components->info('Rebuilding the database from scratch.');
            Artisan::call('migrate:fresh', ['--force' => true], $this->output);
        }

        // Countries, states and cities for the address dropdowns (D-19).
        // ~30 seconds and 150,000 rows, so only when it is actually missing —
        // a --fresh rebuild drops it, an ordinary re-seed does not.
        if (DB::table('countries')->doesntExist()) {
            $this->components->info('Seeding world address data (this takes about half a minute).');
            $this->call('db:seed', ['--class' => WorldSeeder::class, '--force' => true]);
        }

        $this->callSilently('db:seed', ['--class' => SettingsSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Admin login</>', 'admin@hupm.test / hupm-local-dev-2026');
        $this->components->twoColumnDetail('<fg=green>Tenant logins</>', 'see the tenants table — all use / hupm-local-dev-2026');
        $this->components->warn('Synthetic data. Not the client portfolio — WP-08 imports the real one.');

        return self::SUCCESS;
    }
}
