<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'unit_number' => (string) fake()->unique()->numberBetween(1, 9999),
            'bedrooms' => fake()->numberBetween(1, 4),
            'bathrooms' => fake()->randomElement(['1.0', '1.5', '2.0']),
            'status' => Unit::STATUS_VACANT,
        ];
    }

    public function occupied(): static
    {
        return $this->state(fn () => ['status' => Unit::STATUS_OCCUPIED]);
    }

    public function offMarket(): static
    {
        return $this->state(fn () => ['status' => Unit::STATUS_OFF_MARKET]);
    }
}
