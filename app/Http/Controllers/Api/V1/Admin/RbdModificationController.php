<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Resources\RbdModificationResource;
use App\Models\RbdModification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RbdModificationController extends AirlineCrudController
{
    protected function model(): string
    {
        return RbdModification::class;
    }

    protected function resource(): string
    {
        return RbdModificationResource::class;
    }

    protected function label(): string
    {
        return 'Modification rule';
    }

    protected function with(): array
    {
        return ['rbd:id,code,cabin_class'];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $query->when($request->filled('rbd_id'), fn ($q) => $q->where('rbd_id', $request->integer('rbd_id')))
            ->when($request->filled('type'), fn ($q) => $q->whereIn('type', (array) $request->input('type')))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('rbd', fn ($r) => $r->where('code', 'like', $request->string('search')->upper().'%')));
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';

        return [
            'rbd_id' => [$required, 'integer', Rule::exists('rbds', 'id')->where('airline_id', $this->targetAirlineId($request, $model))],
            'type' => [$required, Rule::in(RbdModification::TYPES)],
            'timeframe_hours' => [$required, 'integer', 'min:0'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'percentage' => ['nullable', 'integer', 'between:0,100'],
            'is_allowed' => ['boolean'],
        ];
    }
}
