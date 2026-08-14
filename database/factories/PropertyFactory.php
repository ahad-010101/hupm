<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->buildingNumber().' '.fake()->streetName(),
            'street_address' => fake()->buildingNumber().' '.fake()->streetName(),
            'city' => fake()->randomElement(['Atlanta', 'Decatur', 'Marietta', 'East Point']),
            'state' => 'GA',
            // Real five-digit Georgia ZIPs — ZIP drives weather targeting, so a
            // plausible one keeps WP-21 testable.
            'zip' => fake()->randomElement(['30310', '30030', '30060', '30344']),
            'county' => fake()->randomElement(['Fulton', 'DeKalb', 'Cobb']),
        ];
    }
}
