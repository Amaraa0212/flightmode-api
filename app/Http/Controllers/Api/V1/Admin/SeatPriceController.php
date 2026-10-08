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
            'cabin_class' => [$required, Rule::enum(CabinClass::class)],
            'seat_type' => ['nullable', Rule::enum(SeatType::class)],
            'price' => [$required, 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['boolean'],
        ];
    }
}
