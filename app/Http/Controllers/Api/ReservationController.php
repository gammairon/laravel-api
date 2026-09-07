<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Offer;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReservationController extends Controller
{
    public function __construct(private readonly ReservationService $reservations) {}

    /**
     * Book one unit of the given offer.
     */
    public function store(StoreReservationRequest $request, Offer $offer): JsonResponse
    {
        $reservation = $this->reservations->reserve($offer, $request->validated());

        return ReservationResource::make($reservation)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
