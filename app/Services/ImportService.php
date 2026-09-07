<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;

class ImportService
{
    /**
     * Register an incoming batch and queue it for processing.
     *
     * Idempotent: the (supplier, external_import_id) pair is unique, so a
     * repeated submission returns the existing import and does not queue a
     * second job.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): Import
    {
        $supplier = Supplier::where('code', $data['supplier'])->firstOrFail();

        if ($existing = $this->find($supplier, $data['external_import_id'])) {
            return $existing;
        }

        try {
            $import = Import::create([
                'supplier_id' => $supplier->id,
                'external_import_id' => $data['external_import_id'],
                'sent_at' => $data['sent_at'],
                'status' => ImportStatus::Pending,
                'total_offers' => count($data['offers']),
                'processed_offers' => 0,
                'payload' => $data['offers'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two identical requests raced each other; the unique index decided
            // the winner and the job was queued by whoever inserted the row.
            return $this->find($supplier, $data['external_import_id']);
        }

        ProcessImportJob::dispatch($import->id);

        return $import;
    }

    private function find(Supplier $supplier, string $externalImportId): ?Import
    {
        return Import::where('supplier_id', $supplier->id)
            ->where('external_import_id', $externalImportId)
            ->first();
    }
}
