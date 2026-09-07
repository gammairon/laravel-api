<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Throwable;

class ProcessImportJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function offerRow(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     */
    private function importFor(Supplier $supplier, array $offers, string $externalId = 'import-001'): Import
    {
        return Import::factory()->create([
            'supplier_id' => $supplier->id,
            'external_import_id' => $externalId,
            'payload' => $offers,
            'total_offers' => count($offers),
        ]);
    }

    public function test_it_creates_properties_and_offers_and_completes_the_import(): void
    {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $import = $this->importFor($supplier, [
            $this->offerRow(),
            $this->offerRow([
                'external_id' => 'offer-a-10002',
                'property' => ['code' => 'BCN-0002', 'name' => 'Loft Gracia', 'city' => 'Barcelona'],
                'price' => 51000,
            ]),
        ]);

        (new ProcessImportJob($import->id))->handle();

        $this->assertSame(2, Property::count());
        $this->assertSame(2, Offer::count());

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(2, $import->processed_offers);
        $this->assertNull($import->error);
        $this->assertNotNull($import->completed_at);

        $offer = Offer::where('external_id', 'offer-a-10001')->firstOrFail();
        $this->assertSame(72500, $offer->price);
        $this->assertSame('EUR', $offer->currency);
        $this->assertSame(2, $offer->available_units);
        $this->assertSame($import->id, $offer->import_id);
        $this->assertSame('2026-10-10', $offer->check_in->toDateString());
    }

    public function test_an_offer_seen_in_a_later_import_is_updated_not_duplicated(): void
    {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);

        $first = $this->importFor($supplier, [$this->offerRow()], 'import-001');
        (new ProcessImportJob($first->id))->handle();

        $second = $this->importFor(
            $supplier,
            [$this->offerRow(['price' => 65000, 'available_units' => 1])],
            'import-002'
        );
        (new ProcessImportJob($second->id))->handle();

        $this->assertSame(1, Offer::count());

        $offer = Offer::firstOrFail();
        $this->assertSame(65000, $offer->price);
        $this->assertSame(1, $offer->available_units);
        $this->assertSame($second->id, $offer->import_id);
    }

    public function test_the_same_external_offer_id_from_another_supplier_is_a_separate_offer(): void
    {
        $supplierA = Supplier::factory()->create(['code' => 'supplier-a']);
        $supplierB = Supplier::factory()->create(['code' => 'supplier-b']);

        (new ProcessImportJob($this->importFor($supplierA, [$this->offerRow()], 'a-1')->id))->handle();
        (new ProcessImportJob($this->importFor($supplierB, [$this->offerRow()], 'b-1')->id))->handle();

        $this->assertSame(2, Offer::count());
        // Both offers point at the property that was matched by its code.
        $this->assertSame(1, Property::count());
    }

    public function test_a_broken_payload_marks_the_import_as_failed_and_writes_nothing(): void
    {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $import = $this->importFor($supplier, [
            $this->offerRow(),
            // Second row lacks the property block: the batch must roll back.
            ['external_id' => 'offer-a-10002', 'price' => 1000],
        ]);

        try {
            (new ProcessImportJob($import->id))->handle();
            $this->fail('The job was expected to throw.');
        } catch (Throwable) {
            // Expected: the failure is recorded on the import.
        }

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertNotNull($import->error);
        $this->assertNull($import->completed_at);

        $this->assertSame(0, Offer::count());
        $this->assertSame(0, Property::count());
    }

    public function test_running_the_job_twice_does_not_duplicate_offers(): void
    {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $import = $this->importFor($supplier, [$this->offerRow()]);

        (new ProcessImportJob($import->id))->handle();
        (new ProcessImportJob($import->id))->handle();

        $this->assertSame(1, Offer::count());
        $this->assertSame(1, Property::count());
    }
}
