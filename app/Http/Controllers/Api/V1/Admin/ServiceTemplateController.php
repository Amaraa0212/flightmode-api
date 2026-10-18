<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Resources\ServiceTemplateResource;
use App\Models\Flight;
use App\Models\ServiceTemplate;
use App\Services\Flight\FlightOperationsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Online check-in settings that can be applied to many flights at once (legacy templates / shop). */
class ServiceTemplateController extends AirlineCrudController
{
    protected function model(): string
    {
        return ServiceTemplate::class;
    }

    protected function resource(): string
    {
        return ServiceTemplateResource::class;
    }

    protected function label(): string
    {
        return 'Service template';
    }

    protected function with(): array
    {
        return ['aircraft', 'seatAssignmentProfile', 'baggageRules', 'seatPrices'];
    }

    protected function nonAttributes(): array
    {
        return ['baggage_rule_ids', 'seat_price_ids'];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';
        $airlineId = $this->targetAirlineId($request, $model);
        $aircraftId = $request->has('aircraft_id') ? $request->input('aircraft_id') : $model?->getAttribute('aircraft_id');

        return [
            // null = any aircraft of the airline; a seat assignment profile belongs to one aircraft
            'aircraft_id' => ['nullable', 'integer', Rule::exists('aircraft', 'id')->where('airline_id', $airlineId)->whereNull('deleted_at')],
            'name' => [$required, 'string', 'max:255'],
            'seat_assignment_profile_id' => ['nullable', 'integer',
                Rule::exists('seat_assignment_profiles', 'id')->where('aircraft_id', is_numeric($aircraftId) ? (int) $aircraftId : 0)],
            'checkin_opens_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'checkin_closes_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'baggage_rule_ids' => ['sometimes', 'array'],
            'baggage_rule_ids.*' => ['integer', Rule::exists('baggage_rules', 'id')->where('airline_id', $airlineId)],
            'seat_price_ids' => ['sometimes', 'array'],
            'seat_price_ids.*' => ['integer', Rule::exists('seat_prices', 'id')->where('airline_id', $airlineId)],
        ];
    }

    protected function afterSave(Model $model, array $data): void
    {
        /** @var ServiceTemplate $model */
        if ($model->seat_assignment_profile_id !== null && $model->seatAssignmentProfile?->aircraft_id !== $model->aircraft_id) {
            $model->update(['seat_assignment_profile_id' => null]); // the aircraft changed, its profile no longer fits
        }
        if (array_key_exists('baggage_rule_ids', $data)) {
            $model->baggageRules()->sync($data['baggage_rule_ids']);
        }
        if (array_key_exists('seat_price_ids', $data)) {
            $model->seatPrices()->sync($data['seat_price_ids']);
        }
    }

    public function apply(Request $request, int $id, FlightOperationsService $operations): JsonResponse
    {
        /** @var ServiceTemplate $template */
        $template = $this->find($request, $id);
        $data = $request->validate(['flight_ids' => ['required', 'array', 'min:1'], 'flight_ids.*' => ['integer']]);

        $flights = $this->access->flights(Flight::query(), $request->user())->whereIn('flights.id', $data['flight_ids'])->get();
        abort_if($flights->count() !== count(array_unique($data['flight_ids'])), 404, 'Flight not found.');

        $count = $operations->applyTemplate($template, $flights);

        return $this->success("Template applied to {$count} flight(s).");
    }
}
