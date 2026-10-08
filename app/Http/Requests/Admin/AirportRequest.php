<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AirportRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'iata_code' => [$required, 'alpha', 'size:3', Rule::unique('airports')->ignore($this->route('airport')?->id)],
            'icao_code' => ['nullable', 'alpha', 'size:4'],
            'name' => [$required, 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'city_code' => ['nullable', 'alpha', 'size:3'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'timezone' => [$required, 'timezone:all'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['iata_code', 'icao_code', 'city_code'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => strtoupper($this->input($field))]);
            }
        }
    }
}
