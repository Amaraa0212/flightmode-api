<?php

namespace App\Http\Requests\Admin;

use App\Enums\CabinClass;
use App\Models\AgencyAllocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AllocationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'agency_id' => ['required', 'integer', 'exists:agencies,id'],
            'outbound_flight_id' => ['required', 'integer', 'exists:flights,id'],
            'return_flight_id' => ['nullable', 'integer', 'exists:flights,id', 'different:outbound_flight_id'],
            'mode' => ['sometimes', Rule::in([AgencyAllocation::FIXED, AgencyAllocation::POOL])],
            // a pool allocation's seats are a limit, 0 = no limit
            'seats' => ['required', 'integer', Rule::when($this->input('mode') === AgencyAllocation::POOL, 'min:0', 'min:1')],
            'deadline' => ['required', 'date'],
            'is_waitlist' => ['boolean'],
            // a pool without cabins sells every cabin of the aircraft
            'cabins' => [Rule::requiredIf($this->input('mode') !== AgencyAllocation::POOL), 'array'],
            'cabins.*' => ['integer', 'min:0'],
            'fares' => ['sometimes', 'array'],
            'fares.*.id' => ['nullable', 'integer'],
            'fares.*.fare_id' => ['nullable', 'integer', 'exists:fares,id'],
            'fares.*.cabin_class' => ['required', Rule::enum(CabinClass::class)],
            'fares.*.seats' => ['nullable', 'integer', 'min:0'],
            'fares.*.min_fare_seats' => ['nullable', 'integer', 'min:0'],
            'fares.*.fare_amount' => ['required_without:fares.*.fare_id', 'nullable', 'numeric', 'min:0'],
            'fares.*.fare_max_amount' => ['nullable', 'numeric', 'min:0'],
            'fares.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'fares.*.child_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fares.*.infant_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_keys((array) $this->input('cabins')) as $cabin) {
                if (! CabinClass::tryFrom((string) $cabin)) {
                    $validator->errors()->add('cabins', "Unknown cabin class {$cabin}.");
                }
            }
        }];
    }
}
