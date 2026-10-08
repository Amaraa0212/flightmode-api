<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AirlineRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('airline')?->id;
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'iata_code' => ['nullable', 'string', 'size:2', Rule::unique('airlines')->ignore($id)],
            'icao_code' => ['nullable', 'string', 'size:3', Rule::unique('airlines')->ignore($id)],
            'numeric_code' => ['nullable', 'digits:3'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'registration_no' => ['nullable', 'string', 'max:50'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'conditions_of_carriage' => ['nullable', 'string'],
            'bag_tag_layout' => ['nullable', 'integer', 'min:1', 'max:9'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['boolean'],
        ];
    }
}
