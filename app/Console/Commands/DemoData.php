<?php

namespace App\Console\Commands;

use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

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

        $this->callSilently('db:seed', ['--class' => SettingsSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Admin login</>', 'admin@hupm.test / password');
        $this->components->twoColumnDetail('<fg=green>Tenant logins</>', 'see the tenants table — all use / password');
        $this->components->warn('Synthetic data. Not the client portfolio — WP-08 imports the real one.');

        return self::SUCCESS;
    }
}
