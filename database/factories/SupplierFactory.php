<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'supplier-'.$this->faker->unique()->lexify('????'),
            'name' => $this->faker->company(),
        ];
    }
}
