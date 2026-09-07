<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertySearchTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplierA;

    private Supplier $supplierB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplierA = Supplier::factory()->create(['code' => 'supplier-a']);
        $this->supplierB = Supplier::factory()->create(['code' => 'supplier-b']);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function search(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/properties?'.http_build_query(array_merge([
            'city' => 'Barcelona',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
        ], $query)));
    }

    public function test_it_returns_the_cheapest_offer_per_property(): void
    {
        $property = Property::factory()->create(['code' => 'BCN-0001', 'city' => 'Barcelona']);

        Offer::factory()->create([
            'supplier_id' => $this->supplierA->id,
            'property_id' => $property->id,
            'price' => 72500,
        ]);
        $cheapest = Offer::factory()->create([
            'supplier_id' => $this->supplierB->id,
            'property_id' => $property->id,
            'price' => 64000,
            'available_units' => 3,
        ]);

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('data.0.best_offer.id', $cheapest->id)
            ->assertJsonPath('data.0.best_offer.supplier', 'supplier-b')
            ->assertJsonPath('data.0.best_offer.price', 64000)
            ->assertJsonPath('data.0.best_offer.currency', 'EUR')
            ->assertJsonPath('data.0.best_offer.available_units', 3);
    }

    public function test_it_orders_properties_by_their_best_price(): void
    {
        $expensive = Property::factory()->create(['code' => 'BCN-0002', 'city' => 'Barcelona']);
        $cheap = Property::factory()->create(['code' => 'BCN-0003', 'city' => 'Barcelona']);

        Offer::factory()->create([
            'supplier_id' => $this->supplierA->id,
            'property_id' => $expensive->id,
            'price' => 90000,
        ]);
        Offer::factory()->create([
            'supplier_id' => $this->supplierA->id,
            'property_id' => $cheap->id,
            'price' => 30000,
        ]);

        $this->search()
            ->assertOk()
            ->assertJsonPath('data.0.code', 'BCN-0003')
            ->assertJsonPath('data.1.code', 'BCN-0002');
    }

    public function test_it_ignores_expired_sold_out_and_too_small_offers(): void
    {
        $property = Property::factory()->create(['code' => 'BCN-0001', 'city' => 'Barcelona']);
        $base = [
            'supplier_id' => $this->supplierA->id,
            'property_id' => $property->id,
        ];

        Offer::factory()->expired()->create($base + ['price' => 10000]);
        Offer::factory()->soldOut()->create($base + ['price' => 11000]);
        Offer::factory()->create($base + ['price' => 12000, 'max_guests' => 1]);
        $only = Offer::factory()->create($base + ['price' => 50000]);

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $only->id)
            ->assertJsonPath('data.0.best_offer.price', 50000);
    }

    public function test_it_filters_by_city_and_dates(): void
    {
        $barcelona = Property::factory()->create(['code' => 'BCN-0001', 'city' => 'Barcelona']);
        $madrid = Property::factory()->create(['code' => 'MAD-0001', 'city' => 'Madrid']);

        Offer::factory()->create([
            'supplier_id' => $this->supplierA->id,
            'property_id' => $barcelona->id,
        ]);
        Offer::factory()->create([
            'supplier_id' => $this->supplierA->id,
            'property_id' => $madrid->id,
        ]);

        $this->search()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'BCN-0001');
        $this->search(['city' => 'Madrid'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'MAD-0001');
        // Without a city both properties are returned.
        $this->search(['city' => null])->assertJsonCount(2, 'data');
        // Different dates match nothing.
        $this->search(['check_in' => '2026-11-01', 'check_out' => '2026-11-05'])->assertJsonCount(0, 'data');
    }

    public function test_it_paginates_at_the_database_level(): void
    {
        foreach (range(1, 5) as $i) {
            $property = Property::factory()->create([
                'code' => sprintf('BCN-%04d', $i),
                'city' => 'Barcelona',
            ]);

            Offer::factory()->create([
                'supplier_id' => $this->supplierA->id,
                'property_id' => $property->id,
                'price' => $i * 10000,
            ]);
        }

        $first = $this->search(['per_page' => 2, 'page' => 1])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('links.prev', null);

        $this->assertNotNull($first->json('links.next'));

        $second = $this->search(['per_page' => 2, 'page' => 2])
            ->assertOk()
            ->assertJsonPath('data.0.code', 'BCN-0003');

        $this->assertNotNull($second->json('links.prev'));
        $this->assertNotNull($second->json('links.next'));
    }

    public function test_it_validates_the_search_parameters(): void
    {
        $this->getJson('/api/properties')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['check_in', 'check_out', 'guests']);

        $this->search(['check_out' => '2026-10-10'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('check_out');
    }
}
