<?php

namespace App\Http\Requests\Admin;

use App\Models\Agency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgencyRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'channel' => ['sometimes', Rule::in(Agency::CHANNELS)],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('agencies')->ignore($this->route('agency')?->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'registration_no' => ['nullable', 'string', 'max:50'],
            'api_enabled' => ['boolean'],
            'chinggis_sync' => ['boolean'],
            // system administrators: charter round trips may return on another airline
            'open_jaw' => ['boolean'],
            'is_active' => ['boolean'],
            // system administrators: the airline the new agency belongs to (empty = shared by every airline)
            'owner_airline_id' => ['nullable', 'integer', 'exists:airlines,id'],
            // system administrators, a shared agency: a contract with every airline at once
            'all_airlines' => ['boolean'],
        ];
    }
}
