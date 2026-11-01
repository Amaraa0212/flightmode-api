<?php

namespace App\Http\Requests\Admin;

use App\Enums\RoleScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'slug' => [$required, 'alpha_dash', 'max:50', Rule::unique('roles')->ignore($this->route('role')?->id)],
            'scope' => [$required, Rule::enum(RoleScope::class)],
            // system administrators: a role only that airline sees (null = shared)
            'airline_id' => ['sometimes', 'nullable', 'integer', 'exists:airlines,id'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ];
    }
}
