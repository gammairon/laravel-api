<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Offer>
 */
class OfferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'property_id' => Property::factory(),
            'import_id' => null,
            'external_id' => 'offer-'.$this->faker->unique()->bothify('??-#####'),
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => $this->faker->numberBetween(20_000, 150_000),
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function soldOut(): static
    {
        return $this->state(fn () => ['available_units' => 0]);
    }
}
