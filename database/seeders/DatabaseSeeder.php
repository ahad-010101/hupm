<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            // Must run first: everything downstream reads configuration from here.
            SettingsSeeder::class,
            HousingAuthoritySeeder::class,
            // The public site's shipped copy (WP-36). Runs everywhere, including
            // production: without it a fresh install has a site with no words on
            // it. Idempotent, and it never overwrites a page somebody has since
            // edited.
            ContentSeeder::class,
            // Refuses to run outside local/testing.
            AdminUserSeeder::class,
        ]);
    }
}
