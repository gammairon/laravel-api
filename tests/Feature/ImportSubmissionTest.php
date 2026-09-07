<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Supplier::factory()->create(['code' => 'supplier-a', 'name' => 'Supplier A']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'supplier' => 'supplier-a',
            'external_import_id' => 'import-2026-09-01-001',
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [
                [
                    'external_id' => 'offer-a-10001',
                    'property' => [
                        'code' => 'BCN-0001',
                        'name' => 'Apartment near Sagrada Familia',
                        'city' => 'Barcelona',
                    ],
                    'check_in' => '2026-10-10',
                    'check_out' => '2026-10-15',
                    'max_guests' => 4,
                    'price' => 72500,
                    'currency' => 'EUR',
                    'available_units' => 2,
                    'expires_at' => '2026-09-10T23:59:59Z',
                ],
            ],
        ], $overrides);
    }

    public function test_it_accepts_an_import_and_queues_the_processing(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/imports', $this->payload());

        $response->assertAccepted()
            ->assertJsonStructure(['data' => ['id', 'status']])
            ->assertJsonPath('data.status', ImportStatus::Pending->value);

        $this->assertDatabaseHas('imports', [
            'external_import_id' => 'import-2026-09-01-001',
            'status' => ImportStatus::Pending->value,
            'total_offers' => 1,
            'processed_offers' => 0,
        ]);

        Queue::assertPushed(ProcessImportJob::class, 1);
    }

    public function test_resending_the_same_import_creates_no_duplicate_and_queues_nothing(): void
    {
        Queue::fake();

        $first = $this->postJson('/api/imports', $this->payload())->assertAccepted();
        $second = $this->postJson('/api/imports', $this->payload())->assertAccepted();

        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'The repeated submission must return the existing import.'
        );

        $this->assertSame(1, Import::count());
        Queue::assertPushed(ProcessImportJob::class, 1);
    }

    public function test_the_same_external_import_id_from_another_supplier_is_a_separate_import(): void
    {
        Queue::fake();
        Supplier::factory()->create(['code' => 'supplier-b', 'name' => 'Supplier B']);

        $this->postJson('/api/imports', $this->payload())->assertAccepted();
        $this->postJson('/api/imports', $this->payload(['supplier' => 'supplier-b']))->assertAccepted();

        $this->assertSame(2, Import::count());
        Queue::assertPushed(ProcessImportJob::class, 2);
    }

    public function test_it_rejects_an_unknown_supplier(): void
    {
        Queue::fake();

        $this->postJson('/api/imports', $this->payload(['supplier' => 'supplier-zzz']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier');

        Queue::assertNothingPushed();
    }

    public function test_it_validates_the_offer_structure(): void
    {
        Queue::fake();

        $payload = $this->payload();
        unset($payload['offers'][0]['price'], $payload['offers'][0]['property']['city']);

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offers.0.price', 'offers.0.property.city']);

        Queue::assertNothingPushed();
    }

    public function test_it_rejects_a_check_out_that_is_not_after_check_in(): void
    {
        $payload = $this->payload();
        $payload['offers'][0]['check_out'] = '2026-10-10';

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('offers.0.check_out');
    }

    public function test_it_rejects_duplicated_offer_ids_inside_one_batch(): void
    {
        $payload = $this->payload();
        $payload['offers'][] = $payload['offers'][0];

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('offers.0.external_id');
    }
}
