<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The batch is applied atomically, so a retry would just repeat the whole
     * transaction. One attempt is enough; the failure is recorded on the import.
     */
    public int $tries = 1;

    public function __construct(public readonly int $importId) {}

    /**
     * Guarantees a single in-flight job per import, even if the same batch is
     * somehow dispatched twice.
     */
    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    public function handle(): void
    {
        $import = Import::find($this->importId);

        // Nothing to do if the import vanished or was already applied.
        if (! $import || $import->status === ImportStatus::Completed) {
            return;
        }

        $import->update(['status' => ImportStatus::Processing]);

        try {
            $processed = DB::transaction(fn () => $this->applyOffers($import));

            $import->update([
                'status' => ImportStatus::Completed,
                'processed_offers' => $processed,
                'error' => null,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $import->update([
                'status' => ImportStatus::Failed,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * The whole batch is written inside one transaction: either every offer
     * lands or the import is marked failed and nothing is half-applied.
     */
    private function applyOffers(Import $import): int
    {
        $processed = 0;

        foreach ((array) $import->payload as $row) {
            $property = Property::firstOrCreate(
                ['code' => $row['property']['code']],
                [
                    'name' => $row['property']['name'],
                    'city' => $row['property']['city'],
                ]
            );

            // An offer already known under this (supplier, external_id) is
            // updated in place, even when it arrives in a different import.
            Offer::updateOrCreate(
                [
                    'supplier_id' => $import->supplier_id,
                    'external_id' => $row['external_id'],
                ],
                [
                    'property_id' => $property->id,
                    'import_id' => $import->id,
                    'check_in' => $row['check_in'],
                    'check_out' => $row['check_out'],
                    'max_guests' => $row['max_guests'],
                    'price' => $row['price'],
                    'currency' => strtoupper($row['currency']),
                    'available_units' => $row['available_units'],
                    'expires_at' => $row['expires_at'],
                ]
            );

            $processed++;
        }

        return $processed;
    }

    /**
     * Safety net for failures that never reach the catch block in handle()
     * (timeouts, serialization errors, exhausted retries).
     */
    public function failed(?Throwable $e): void
    {
        Import::where('id', $this->importId)
            ->whereNot('status', ImportStatus::Completed->value)
            ->update([
                'status' => ImportStatus::Failed->value,
                'error' => $e?->getMessage() ?? 'Import job failed.',
            ]);
    }
}
