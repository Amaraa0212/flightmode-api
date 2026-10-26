<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string', 'max:2000'],
            'code' => ['required', 'string', 'max:10'],
        ];
    }
}
