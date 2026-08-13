<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('correct-horse-battery-staple'),
            'role' => User::ROLE_TENANT,
            'status' => User::STATUS_ACTIVE,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_ADMIN, 'tenant_id' => null]);
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_OWNER, 'tenant_id' => null]);
    }

    /** A tenant user with a real tenant record behind it. */
    public function tenant(?Tenant $tenant = null): static
    {
        return $this->state(fn () => [
            'role' => User::ROLE_TENANT,
            'tenant_id' => ($tenant ?? Tenant::factory()->create())->id,
        ]);
    }

    /** Provisioned but has not set a password yet (FR-AUTH-02). */
    public function invited(): static
    {
        return $this->state(fn () => [
            'status' => User::STATUS_INVITED,
            'password' => null,
            'email_verified_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_SUSPENDED]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
