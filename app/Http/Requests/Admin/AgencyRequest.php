<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgencyRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('agencies')->ignore($this->route('agency')?->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'registration_no' => ['nullable', 'string', 'max:50'],
            'api_enabled' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
