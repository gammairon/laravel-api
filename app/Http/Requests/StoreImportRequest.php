<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier' => ['required', 'string', 'exists:suppliers,code'],
            'external_import_id' => ['required', 'string', 'max:128'],
            'sent_at' => ['required', 'date'],

            'offers' => ['required', 'array', 'min:1'],
            'offers.*.external_id' => ['required', 'string', 'max:128', 'distinct'],

            'offers.*.property' => ['required', 'array'],
            'offers.*.property.code' => ['required', 'string', 'max:64'],
            'offers.*.property.name' => ['required', 'string', 'max:255'],
            'offers.*.property.city' => ['required', 'string', 'max:120'],

            'offers.*.check_in' => ['required', 'date_format:Y-m-d'],
            'offers.*.check_out' => ['required', 'date_format:Y-m-d'],
            'offers.*.max_guests' => ['required', 'integer', 'min:1', 'max:65535'],
            'offers.*.price' => ['required', 'integer', 'min:0'],
            'offers.*.currency' => ['required', 'string', 'size:3'],
            'offers.*.available_units' => ['required', 'integer', 'min:0'],
            'offers.*.expires_at' => ['required', 'date'],
        ];
    }

    /**
     * "after:offers.*.check_in" is not expressible with wildcards, so the date
     * order is verified per offer here.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->input('offers', []) as $index => $offer) {
                    $checkIn = $offer['check_in'] ?? null;
                    $checkOut = $offer['check_out'] ?? null;

                    if ($checkIn && $checkOut && $checkOut <= $checkIn) {
                        $validator->errors()->add(
                            'offers.'.$index.'.check_out',
                            'The check_out date must be after the check_in date.'
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier.exists' => 'The selected supplier is unknown.',
        ];
    }
}
