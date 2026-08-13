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
            // Refuses to run outside local/testing.
            AdminUserSeeder::class,
        ]);
    }
}
