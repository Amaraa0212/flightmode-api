<?php

namespace App\Http\Requests\Admin;

use App\Enums\FlightServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FlightSeriesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'airline_id' => ['nullable', 'integer', 'exists:airlines,id'],
            'aircraft_id' => ['nullable', 'integer', 'exists:aircraft,id'],
            'flight_number' => ['required', 'string', 'max:10'],
            'service_type' => ['sometimes', Rule::enum(FlightServiceType::class)],
            'origin_airport_id' => ['required', 'integer', 'exists:airports,id'],
            'destination_airport_id' => ['required', 'integer', 'exists:airports,id', 'different:origin_airport_id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:1,7', 'distinct'],
            'departure_time' => ['required', 'date_format:H:i'],
            'arrival_time' => ['required', 'date_format:H:i'],
            'arrival_day_offset' => ['nullable', 'integer', 'between:0,2'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'gate' => ['nullable', 'string', 'max:10'],
            'excess_baggage_max_kg' => ['nullable', 'numeric', 'min:0'],
            'excess_baggage_price_per_kg' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, mixed> */
    public function seriesData(int $airlineId): array
    {
        $data = $this->validated();
        $days = $data['days_of_week'];
        sort($days);

        return array_merge($data, [
            'airline_id' => $airlineId,
            'flight_number' => strtoupper(str_replace(' ', '', $data['flight_number'])),
            'days_of_week' => implode(',', $days),
            'arrival_day_offset' => $data['arrival_day_offset'] ?? 0,
        ]);
    }
}
