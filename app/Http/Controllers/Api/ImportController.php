<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Http\Resources\ImportSubmissionResource;
use App\Models\Import;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports) {}

    /**
     * Accept a supplier batch and hand it to the queue.
     */
    public function store(StoreImportRequest $request): JsonResponse
    {
        $import = $this->imports->submit($request->validated());

        return ImportSubmissionResource::make($import)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * Current state of an asynchronous import.
     */
    public function show(Import $import): ImportResource
    {
        return ImportResource::make($import->load('supplier'));
    }
}
