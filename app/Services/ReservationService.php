<?php

namespace App\Services;

use App\Exceptions\OfferNotBookableException;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    /**
     * Book one unit of an offer.
     *
     * Two concurrent requests for the last unit cannot both succeed: the offer
     * row is read with SELECT ... FOR UPDATE inside a transaction, so the
     * second request blocks until the first commits and then re-reads
     * available_units as 0. The unsigned column and the unique
     * client_reference index back this up at the schema level.
     *
     * @param  array<string, mixed>  $data
     */
    public function reserve(Offer $offer, array $data): Reservation
    {
        try {
            return DB::transaction(function () use ($offer, $data) {
                $locked = Offer::whereKey($offer->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->expires_at->isPast()) {
                    throw OfferNotBookableException::expired();
                }

                if ($locked->available_units < 1) {
                    throw OfferNotBookableException::soldOut();
                }

                $locked->decrement('available_units');

                return Reservation::create([
                    'offer_id' => $locked->id,
                    'client_reference' => $data['client_reference'],
                    'customer_name' => $data['customer_name'],
                    'customer_email' => $data['customer_email'],
                    'units' => 1,
                    // Snapshot: the offer may be re-imported with a new price.
                    'price' => $locked->price,
                    'currency' => $locked->currency,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The same client_reference was already booked; the transaction
            // above was rolled back, so no unit was consumed.
            throw OfferNotBookableException::duplicateReference();
        }
    }
}
