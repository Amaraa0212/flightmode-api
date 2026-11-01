<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PassengerType;
use App\Enums\TripType;
use App\Http\Resources\FareTaxResource;
use App\Models\Fare;
use App\Models\FareTax;
use App\Services\Access\AccessScope;
use App\Services\Fare\FareService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Airport taxes (fare_id null): charged on every fare departing the airport whose trip type (OW / RT) matches.
 * A line with fare_id overrides the default of the same tax code for that fare only (and takes the fare's trip type).
 */
class FareTaxController extends AirlineCrudController
{
    public function __construct(AccessScope $access, private readonly FareService $fares)
    {
        parent::__construct($access);
    }

    protected function model(): string
    {
        return FareTax::class;
    }

    protected function resource(): string
    {
        return FareTaxResource::class;
    }

    protected function label(): string
    {
        return 'Fare tax';
    }

    protected function with(): array
    {
        return ['taxCode:id,code', 'airport:id,iata_code'];
    }

    /** `origin_airport_id`: the default taxes of fares departing it; `fare_id`: the overrides of a fare. */
    protected function filter(Builder $query, Request $request): void
    {
        $query->when($request->filled('fare_id'), fn ($q) => $q->where('fare_id', $request->integer('fare_id')))
            ->when(! $request->filled('fare_id') && $request->filled('origin_airport_id'), fn ($q) => $q->whereNull('fare_id')
                ->where('airport_id', $request->integer('origin_airport_id')))
            ->when($request->filled('trip_type'), fn ($q) => $q->where('trip_type', $request->string('trip_type')))
            ->when($request->filled('airport_id'), fn ($q) => $q->where('airport_id', $request->integer('airport_id')))
            ->when($request->filled('passenger_type'), fn ($q) => $q->where('passenger_type', $request->string('passenger_type')));
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';
        $airlineId = $this->targetAirlineId($request, $model);

        return [
            'tax_code_id' => [$required, 'integer', Rule::exists('tax_codes', 'id')->where('airline_id', $airlineId)],
            'fare_id' => ['nullable', 'integer', Rule::exists('fares', 'id')->where('airline_id', $airlineId)],
            'airport_id' => ['nullable', 'required_without:fare_id', 'integer', 'exists:airports,id'],
            // ignored on a fare override: it takes the fare's trip type
            'trip_type' => [Rule::requiredIf(! $model && ! $request->filled('fare_id')), Rule::enum(TripType::class)],
            'passenger_type' => [$required, Rule::enum(PassengerType::class)],
            'amount' => [$required, 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'refundable' => ['boolean'],
        ];
    }

    public function update(Request $request, int $id): JsonResponse
    {
        // the fares of the old airport / fare need a re-sync too
        $before = clone $this->find($request, $id);
        $response = parent::update($request, $id);
        $this->fares->syncForTax($before);

        return $response;
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var FareTax $tax */
        $tax = $this->find($request, $id);
        $tax->delete();
        $this->fares->syncForTax($tax);

        return $this->deleted('Fare tax deleted.');
    }

    protected function afterSave(Model $model, array $data): void
    {
        if ($model->fare_id) {
            $model->forceFill(['trip_type' => Fare::query()->whereKey($model->fare_id)->value('trip_type')])->saveQuietly();
        }

        $this->fares->syncForTax($model);
    }
}
