<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('(404) ###-####'),
            'status' => 'active',
        ];
    }

    /** No email address, so no portal account is possible (Q-4, AC-AUTH-06). */
    public function withoutEmail(): static
    {
        return $this->state(fn () => ['email' => null]);
    }
}
