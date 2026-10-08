<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BaggageRuleType;
use App\Enums\CabinClass;
use App\Http\Resources\BaggageRuleResource;
use App\Models\BaggageRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BaggageRuleController extends AirlineCrudController
{
    protected function model(): string
    {
        return BaggageRule::class;
    }

    protected function resource(): string
    {
        return BaggageRuleResource::class;
    }

    protected function label(): string
    {
        return 'Baggage rule';
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(BaggageRuleType::class)],
            'cabin_class' => ['nullable', Rule::enum(CabinClass::class)],
            'pieces' => ['nullable', 'integer', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'max_quantity' => ['nullable', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'ssr_code' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ];
    }
}
