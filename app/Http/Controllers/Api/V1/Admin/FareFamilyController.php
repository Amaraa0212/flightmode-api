<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Resources\FareFamilyResource;
use App\Models\FareFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FareFamilyController extends AirlineCrudController
{
    protected function model(): string
    {
        return FareFamily::class;
    }

    protected function resource(): string
    {
        return FareFamilyResource::class;
    }

    protected function label(): string
    {
        return 'Fare family';
    }

    protected function with(): array
    {
        return ['rbds:id,fare_family_id,code', 'creator:id,name'];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $active = array_unique((array) $request->input('is_active', []));
        $query->withCount('rbds')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x
                ->where('code', 'like', $request->string('search')->upper().'%')
                ->orWhere('name', 'like', '%'.$request->string('search').'%')))
            ->when(count($active) === 1, fn ($q) => $q->where('is_active', filter_var($active[0], FILTER_VALIDATE_BOOLEAN)));
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('fare_families')->where('airline_id', $this->targetAirlineId($request, $model))->ignore($model?->getKey())],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'rank' => ['integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ];
    }

    protected function afterSave(Model $model, array $data): void
    {
        if ($model->wasRecentlyCreated) {
            $model->forceFill(['created_by' => request()->user()->id])->saveQuietly();
        }
    }

    // destroy: booking classes of the family fall back to none (nullOnDelete)
}
