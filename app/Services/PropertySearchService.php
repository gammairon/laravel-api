<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\JoinClause;

class PropertySearchService
{
    /**
     * Properties that have at least one bookable offer for the requested stay,
     * each carrying its cheapest offer.
     *
     * Picking the cheapest offer, ordering and paginating all happen inside a
     * single SQL statement: a ROW_NUMBER() window numbers the matching offers
     * per property and only row 1 is joined back. Nothing is grouped in PHP.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Property>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $cheapestPerProperty = Offer::query()
            ->select([
                'offers.id',
                'offers.property_id',
                'offers.supplier_id',
                'offers.price',
                'offers.currency',
                'offers.available_units',
                'offers.expires_at',
            ])
            ->selectRaw(
                'row_number() over (partition by offers.property_id order by offers.price asc, offers.id asc) as rn'
            )
            ->where('offers.check_in', $filters['check_in'])
            ->where('offers.check_out', $filters['check_out'])
            ->where('offers.max_guests', '>=', $filters['guests'])
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', now());

        return Property::query()
            ->joinSub($cheapestPerProperty, 'best', function (JoinClause $join) {
                $join->on('best.property_id', '=', 'properties.id')
                    ->where('best.rn', '=', 1);
            })
            ->join('suppliers', 'suppliers.id', '=', 'best.supplier_id')
            ->when(
                $filters['city'] ?? null,
                fn ($query, string $city) => $query->where('properties.city', $city)
            )
            ->select([
                'properties.id',
                'properties.code',
                'properties.name',
                'properties.city',
                'best.id as best_offer_id',
                'suppliers.code as best_offer_supplier',
                'best.price as best_offer_price',
                'best.currency as best_offer_currency',
                'best.available_units as best_offer_available_units',
                'best.expires_at as best_offer_expires_at',
            ])
            ->orderBy('best.price')
            ->orderBy('properties.id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }
}
