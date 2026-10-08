<?php

namespace App\Http\Requests\Shared;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a booking. Agency / partner: `allocation_id` (+ optional `return_allocation_id`).
 * Back office direct sale: `flight_ids`.
 */
class StoreBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return array_merge([
            'allocation_id' => ['required_without:flight_ids', 'nullable', 'integer', 'exists:agency_allocations,id'],
            'return_allocation_id' => ['nullable', 'integer', 'exists:agency_allocations,id', 'different:allocation_id'],
            'flight_ids' => ['required_without:allocation_id', 'array', 'min:1', 'max:4'],
            'flight_ids.*' => ['integer', 'exists:flights,id'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'send_eticket' => ['boolean'],
            'passengers' => ['required', 'array', 'min:1', 'max:50'],
        ], PassengerFields::rules('passengers.*.'));
    }
}
