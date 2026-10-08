<?php

namespace App\Http\Requests\Admin;

use App\Enums\BanType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PassengerBanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'airline_id' => ['nullable', 'integer', 'exists:airlines,id'],
            'ban_type' => [$required, Rule::enum(BanType::class)],
            'last_name' => [$required, 'string', 'max:100'],
            'first_name' => [$required, 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'required_without:document_number'],
            'document_number' => ['nullable', 'string', 'max:50', 'required_without:date_of_birth'],
            'nationality' => ['nullable', 'string', 'between:2,3'],
            'reason' => [$required, 'string', 'max:1000'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
