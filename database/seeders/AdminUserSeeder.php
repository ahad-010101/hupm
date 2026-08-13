<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * One admin account, for local development only.
 *
 * Refuses to run outside a local or testing environment. A seeded account with
 * a known password on a production box is a straightforward way to lose a
 * system that holds tenant PII and moves money — WP-04 issues real admin
 * accounts through the invite flow, with the password set by the user.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('AdminUserSeeder skipped: not a local environment.');

            return;
        }

        DB::table('users')->updateOrInsert(
            ['email' => 'admin@hupm.test'],
            [
                'tenant_id' => null,
                'name' => 'Local Admin',
                'password' => Hash::make('hupm-local-dev-2026'),
                'role' => 'admin',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
