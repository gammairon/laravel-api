<?php

namespace App\Http\Resources;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A property together with the cheapest offer found by the search query.
 * The best_offer_* columns are aliases produced by PropertySearchService.
 *
 * @mixin Property
 */
class PropertyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'city' => $this->city,
            'best_offer' => [
                'id' => (int) $this->best_offer_id,
                'supplier' => $this->best_offer_supplier,
                'price' => (int) $this->best_offer_price,
                'currency' => $this->best_offer_currency,
                'available_units' => (int) $this->best_offer_available_units,
                'expires_at' => Carbon::parse($this->best_offer_expires_at)->toIso8601ZuluString(),
            ],
        ];
    }
}
