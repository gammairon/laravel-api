<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Import>
 */
class ImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'external_import_id' => 'import-'.$this->faker->unique()->bothify('####-###'),
            'sent_at' => now()->subMinutes(5),
            'status' => ImportStatus::Pending,
            'total_offers' => 0,
            'processed_offers' => 0,
            'payload' => [],
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ImportStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
