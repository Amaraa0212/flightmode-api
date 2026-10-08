<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => [$creating ? 'required' : 'sometimes', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user')?->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => [$creating ? 'required' : 'sometimes', Password::min(8)],
            'role_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:roles,id'],
            'airline_id' => ['nullable', 'integer', 'exists:airlines,id'],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'airport_id' => ['nullable', 'integer', 'exists:airports,id'],
            'is_active' => ['boolean'],
        ];
    }
}
