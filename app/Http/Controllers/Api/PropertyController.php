<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchPropertiesRequest;
use App\Http\Resources\PropertyResource;
use App\Services\PropertySearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    public function __construct(private readonly PropertySearchService $search) {}

    /**
     * Search properties and return the cheapest bookable offer for each.
     */
    public function index(SearchPropertiesRequest $request): AnonymousResourceCollection
    {
        return PropertyResource::collection(
            $this->search->search($request->validated())
        );
    }
}
