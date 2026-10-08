<?php

namespace App\Http\Requests\Admin;

use App\Enums\CabinClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AircraftRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'airline_id' => ['nullable', 'integer', 'exists:airlines,id'],
            'registration' => [$required, 'string', 'max:20', Rule::unique('aircraft')->ignore($this->route('aircraft')?->id)],
            'type' => [$required, 'string', 'max:50'],
            'seat_configuration' => ['nullable', 'string', 'max:50'],
            'total_seats' => [$required, 'integer', 'min:1', 'max:1000'],
            'max_infants' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'cabins' => [$required, 'array', 'min:1'],
            'cabins.*' => ['integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $cabins = $this->input('cabins');
            if (! is_array($cabins)) {
                return;
            }
            foreach (array_keys($cabins) as $cabin) {
                if (! CabinClass::tryFrom($cabin)) {
                    $validator->errors()->add('cabins', "Unknown cabin class {$cabin}.");
                }
            }
            if ($this->filled('total_seats') && array_sum($cabins) !== (int) $this->input('total_seats')) {
                $validator->errors()->add('cabins', 'Cabin seats must add up to total seats.');
            }
        }];
    }
}
