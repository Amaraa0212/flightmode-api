<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PassengerType;
use App\Http\Resources\FareTaxResource;
use App\Models\FareTax;
use App\Services\Access\AccessScope;
use App\Services\Fare\FareService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Airport taxes (fare_id null): OUT lines are charged on fares departing the airport, IN lines on fares arriving there.
 * A line with fare_id overrides the default of the same tax code for that fare only.
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

    /** `origin_airport_id` + `destination_airport_id`: the default taxes of that route; `fare_id`: the overrides of a fare. */
    protected function filter(Builder $query, Request $request): void
    {
        $query->when($request->filled('fare_id'), fn ($q) => $q->where('fare_id', $request->integer('fare_id')))
            ->when(! $request->filled('fare_id') && $request->filled('origin_airport_id'), fn ($q) => $q->whereNull('fare_id')
                ->where(fn ($r) => $r
                    ->where(fn ($x) => $x->where('airport_id', $request->integer('origin_airport_id'))->where('bound', 'OUT'))
                    ->when($request->filled('destination_airport_id'), fn ($r) => $r
                        ->orWhere(fn ($x) => $x->where('airport_id', $request->integer('destination_airport_id'))->where('bound', 'IN')))))
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
            'bound' => [$required, Rule::in(FareTax::BOUNDS)],
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
        $this->fares->syncForTax($model);
    }
}
