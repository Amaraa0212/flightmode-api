<?php

namespace App\Http\Requests\Shared;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePassengerRequest extends FormRequest
{
    public function rules(): array
    {
        return PassengerFields::rules('', creating: false);
    }
}
