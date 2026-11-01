<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Resources\FareCombinationResource;
use App\Models\FareCombination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Fare bases that may be combined: one fare base + many partners per request, one row per pair. */
class FareCombinationController extends AirlineCrudController
{
    protected function model(): string
    {
        return FareCombination::class;
    }

    protected function resource(): string
    {
        return FareCombinationResource::class;
    }

    protected function label(): string
    {
        return 'Fare combination';
    }

    protected function with(): array
    {
        return ['fareClass.rbd:id,code', 'combinableFareClass.rbd:id,code', 'creator:id,name'];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $query->when($request->filled('fare_class_id'), fn ($q) => $q->where(fn ($x) => $x
            ->where('fare_class_id', $request->integer('fare_class_id'))
            ->orWhere('combinable_fare_class_id', $request->integer('fare_class_id'))));
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $airlineId = $this->targetAirlineId($request, $model);
        $exists = Rule::exists('fare_classes', 'id')->where('airline_id', $airlineId);

        return [
            'fare_class_id' => ['required', 'integer', $exists],
            'combinable_fare_class_ids' => ['required', 'array', 'min:1'],
            'combinable_fare_class_ids.*' => ['integer', 'distinct', 'different:fare_class_id', $exists],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $airlineId = $this->access->airlineIdForWrite($request->user(), $request->integer('airline_id') ?: null);
        $data = $request->validate($this->rules($request, null));
        $from = (int) $data['fare_class_id'];

        $created = DB::transaction(function () use ($data, $from, $airlineId, $request) {
            $ids = [];
            foreach ($data['combinable_fare_class_ids'] as $to) {
                if (FareCombination::query()->where(fn ($q) => $q->pair($from, (int) $to))->exists()) {
                    continue; // pair already saved (either direction)
                }
                $ids[] = FareCombination::create([
                    'airline_id' => $airlineId,
                    'fare_class_id' => $from,
                    'combinable_fare_class_id' => (int) $to,
                    'created_by' => $request->user()->id,
                ])->id;
            }

            return FareCombination::query()->with($this->with())->whereIn('id', $ids)->get();
        });

        return $this->created(
            $created->isEmpty() ? 'These combinations already exist.' : $created->count().' fare combination(s) added.',
            FareCombinationResource::collection($created)
        );
    }
}
