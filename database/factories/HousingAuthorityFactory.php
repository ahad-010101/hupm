<?php

namespace Database\Factories;

use App\Models\HousingAuthority;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HousingAuthority>
 */
class HousingAuthorityFactory extends Factory
{
    protected $model = HousingAuthority::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->city().' Housing Authority',
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => fake()->numerify('(404) ###-####'),
            'remittance_type' => HousingAuthority::REMITTANCE_PER_TENANT, // [GATE Q-2]
        ];
    }

    public function lumpSum(): static
    {
        return $this->state(fn () => ['remittance_type' => HousingAuthority::REMITTANCE_LUMP_SUM]);
    }
}
