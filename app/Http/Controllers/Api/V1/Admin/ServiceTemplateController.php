<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Resources\ServiceTemplateResource;
use App\Models\Flight;
use App\Models\ServiceTemplate;
use App\Services\Flight\FlightOperationsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        return ['baggageRules', 'seatPrices'];
    }

    protected function nonAttributes(): array
    {
        return ['baggage_rule_ids', 'seat_price_ids'];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';

        return [
            'aircraft_id' => [$required, 'integer', 'exists:aircraft,id'],
            'name' => [$required, 'string', 'max:255'],
            'seat_assignment_profile_id' => ['nullable', 'integer', 'exists:seat_assignment_profiles,id'],
            'checkin_opens_minutes' => ['nullable', 'integer', 'min:0'],
            'checkin_closes_minutes' => ['nullable', 'integer', 'min:0'],
            'baggage_rule_ids' => ['sometimes', 'array'],
            'baggage_rule_ids.*' => ['integer', 'exists:baggage_rules,id'],
            'seat_price_ids' => ['sometimes', 'array'],
            'seat_price_ids.*' => ['integer', 'exists:seat_prices,id'],
        ];
    }

    protected function afterSave(Model $model, array $data): void
    {
        /** @var ServiceTemplate $model */
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
