<?php

namespace App\Http\Requests\Admin;

use App\Enums\PermissionKey;
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
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::enum(PermissionKey::class)],
        ];
    }
}
