<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class FlightRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'airline_id' => ['nullable', 'integer', 'exists:airlines,id'],
            'aircraft_id' => [$required, 'integer', 'exists:aircraft,id'],
            'flight_number' => [$required, 'string', 'max:10'],
            'origin_airport_id' => [$required, 'integer', 'exists:airports,id'],
            'destination_airport_id' => [$required, 'integer', 'exists:airports,id', 'different:origin_airport_id'],
            'scheduled_departure_at' => [$required, 'date'],
            'scheduled_arrival_at' => [$required, 'date', 'after:scheduled_departure_at'],
            'terminal' => ['nullable', 'string', 'max:5'],
            'gate' => ['nullable', 'string', 'max:10'],
            'is_published' => ['boolean'],
            'excess_baggage_max_kg' => ['nullable', 'numeric', 'min:0'],
            'excess_baggage_price_per_kg' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Times are sent with an offset (ISO-8601, e.g. 2026-10-01T09:30:00+08:00) and stored as UTC.
     *
     * @return array<string, mixed>
     */
    public function flightData(): array
    {
        $data = collect($this->validated())->except('airline_id');
        foreach (['scheduled_departure_at', 'scheduled_arrival_at'] as $field) {
            if ($data->has($field)) {
                $data[$field] = Carbon::parse($data[$field])->utc();
            }
        }
        if ($data->has('flight_number')) {
            $data['flight_number'] = strtoupper(str_replace(' ', '', $data['flight_number']));
        }

        return $data->all();
    }
}
