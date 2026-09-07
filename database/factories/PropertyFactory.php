<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('???-####')),
            'name' => 'Apartment '.$this->faker->streetName(),
            'city' => $this->faker->randomElement(['Barcelona', 'Madrid', 'Lisbon', 'Rome']),
        ];
    }
}
