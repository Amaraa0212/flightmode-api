<?php

namespace App\Http\Requests\Admin;

use App\Enums\CabinClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateSeatsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'row_from' => ['required', 'integer', 'min:1', 'max:199'],
            'row_to' => ['required', 'integer', 'gte:row_from', 'max:199'],
            'cabin_class' => ['required', Rule::enum(CabinClass::class)],
            'zone' => ['nullable', 'string', 'max:5'],
            'is_emergency_exit' => ['boolean'],
            'columns' => ['required', 'array'],
            'columns.left' => ['nullable', 'string', 'regex:/^[A-Za-z]{0,4}$/'],
            'columns.middle' => ['nullable', 'string', 'regex:/^[A-Za-z]{0,4}$/'],
            'columns.right' => ['nullable', 'string', 'regex:/^[A-Za-z]{0,4}$/'],
            'skip_rows' => ['sometimes', 'array'],
            'skip_rows.*' => ['integer'],
        ];
    }
}
