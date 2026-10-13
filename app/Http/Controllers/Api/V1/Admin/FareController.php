<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CabinClass;
use App\Enums\TripType;
use App\Http\Resources\FareResource;
use App\Models\Fare;
use App\Models\FareClass;
use App\Models\Flight;
use App\Services\Fare\FareService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class FareController extends AirlineCrudController
{
    protected function model(): string
    {
        return Fare::class;
    }

    protected function resource(): string
    {
        return FareResource::class;
    }

    protected function with(): array
    {
        return ['origin', 'destination', 'fareClass.rbd'];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $query->when($request->boolean('with_fare_class'), fn ($q) => $q->whereNotNull('fare_class_id'))
            ->when($request->filled('fare_class_id'), fn ($q) => $q->where('fare_class_id', $request->integer('fare_class_id')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('cabin_class'), fn ($q) => $q->where('cabin_class', $request->string('cabin_class')))
            ->when($request->filled('trip_type'), fn ($q) => $q->where('trip_type', $request->string('trip_type')))
            ->when($request->filled('origin_airport_id'), fn ($q) => $q->where('origin_airport_id', $request->integer('origin_airport_id')))
            ->when($request->filled('destination_airport_id'), fn ($q) => $q->where('destination_airport_id', $request->integer('destination_airport_id')))
            ->when($request->boolean('valid_now'), fn ($q) => $q->whereDate('valid_from', '<=', today())->whereDate('valid_to', '>=', today()));
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';
        // cabin / RBD / trip type come from the fare class when one is given
        $legacy = $model ? 'sometimes' : 'required_without:fare_class_id';

        return [
            'fare_class_id' => ['nullable', 'integer', Rule::exists('fare_classes', 'id')->where('airline_id', $this->targetAirlineId($request, $model))],
            'origin_airport_id' => [$required, 'integer', 'exists:airports,id'],
            'destination_airport_id' => [$required, 'integer', 'exists:airports,id', 'different:origin_airport_id'],
            'trip_type' => [$legacy, Rule::enum(TripType::class)],
            'cabin_class' => [$legacy, Rule::enum(CabinClass::class)],
            'fare_class' => [$legacy, 'alpha', 'max:2'],
            'valid_from' => [$required, 'date'],
            'valid_to' => [$required, 'date', 'after_or_equal:valid_from'],
            'base_amount' => [$required, 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'child_discount_percent' => ['nullable', 'integer', 'between:0,100'],
            'infant_discount_percent' => ['nullable', 'integer', 'between:0,100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'conditions' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        // the flat columns are NOT NULL: seed them from the fare class, FareService keeps them in sync afterwards
        $class = $request->filled('fare_class_id') ? FareClass::query()->with('rbd')->find($request->integer('fare_class_id')) : null;
        if ($class) {
            $request->merge([
                'cabin_class' => $class->rbd->cabin_class->value,
                'fare_class' => $class->rbd->code,
                'trip_type' => $class->trip_type->value,
            ]);
        }

        return parent::store($request);
    }

    protected function afterSave(Model $model, array $data): void
    {
        app(FareService::class)->sync($model);
    }

    /** Fares that can be sold on a flight: its route (and the reverse route for round-trip fares) valid on its date. */
    public function forFlight(Request $request, Flight $flight): AnonymousResourceCollection
    {
        $this->access->ensureFlight($request->user(), $flight);
        $date = $flight->scheduled_departure_at->toDateString();

        return FareResource::collection(
            Fare::query()->with($this->with())
                ->where('airline_id', $flight->airline_id)
                ->where('is_active', true)
                ->forRoute($flight->origin_airport_id, $flight->destination_airport_id)
                ->whereDate('valid_from', '<=', $date)->whereDate('valid_to', '>=', $date)
                ->orderBy('cabin_class')->orderBy('base_amount')
                ->get()
        );
    }
}
