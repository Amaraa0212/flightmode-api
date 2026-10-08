<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BaggagePolicyType;
use App\Http\Resources\BaggagePolicyResource;
use App\Models\BaggagePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Passenger-facing baggage texts shown in online check-in. */
class BaggagePolicyController extends AirlineCrudController
{
    protected function model(): string
    {
        return BaggagePolicy::class;
    }

    protected function resource(): string
    {
        return BaggagePolicyResource::class;
    }

    protected function label(): string
    {
        return 'Baggage policy';
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';

        return [
            'type' => [$required, Rule::enum(BaggagePolicyType::class)],
            'locale' => [$required, 'string', 'max:5'],
            'title' => [$required, 'string', 'max:255'],
            'body' => [$required, 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
