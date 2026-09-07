<?php

namespace Database\Factories;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Reservation>
 */
class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'client_reference' => 'web-order-'.$this->faker->unique()->bothify('########'),
            'customer_name' => $this->faker->name(),
            'customer_email' => $this->faker->safeEmail(),
            'units' => 1,
            'price' => $this->faker->numberBetween(20_000, 150_000),
            'currency' => 'EUR',
        ];
    }
}
