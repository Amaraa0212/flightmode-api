<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CabinClass;
use App\Enums\SeatType;
use App\Http\Resources\SeatPriceResource;
use App\Models\SeatPrice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SeatPriceController extends AirlineCrudController
{
    protected function model(): string
    {
        return SeatPrice::class;
    }

    protected function resource(): string
    {
        return SeatPriceResource::class;
    }

    protected function label(): string
    {
        return 'Seat price';
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:100'],
            'cabin_class' => [$required, Rule::enum(CabinClass::class)],
            'seat_type' => ['nullable', Rule::enum(SeatType::class)],
            'rows' => ['nullable', 'string', 'max:50', 'regex:/^\d{1,3}(-\d{1,3})?(,\d{1,3}(-\d{1,3})?)*$/'],
            'position' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'price' => [$required, 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['boolean'],
        ];
    }
}
