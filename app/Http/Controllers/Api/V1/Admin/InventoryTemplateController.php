<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CabinClass;
use App\Http\Resources\InventoryTemplateResource;
use App\Models\Flight;
use App\Models\InventoryTemplate;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Saved cabin seats + RBD limits, applied to flights from the seat allocation screen or by a flight series. */
class InventoryTemplateController extends AirlineCrudController
{
    protected function model(): string
    {
        return InventoryTemplate::class;
    }

    protected function resource(): string
    {
        return InventoryTemplateResource::class;
    }

    protected function label(): string
    {
        return 'Inventory template';
    }

    protected function with(): array
    {
        return ['aircraft:id,registration,type', 'cabins', 'rbds.rbd:id,code,cabin_class', 'creator:id,name'];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $query->when($request->filled('aircraft_id'), fn ($q) => $q->where('aircraft_id', $request->integer('aircraft_id')));
    }

    protected function nonAttributes(): array
    {
        return ['cabins', 'rbds'];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';
        $airlineId = $this->targetAirlineId($request, $model);

        return [
            'name' => [$required, 'string', 'max:100',
                Rule::unique('inventory_templates')->where('airline_id', $airlineId)->ignore($model?->getKey())],
            'description' => ['nullable', 'string', 'max:255'],
            'aircraft_id' => ['nullable', 'integer', Rule::exists('aircraft', 'id')->where('airline_id', $airlineId)],
            'cabins' => [$required, 'array', 'min:1'],
            'cabins.*.cabin_class' => ['required', Rule::enum(CabinClass::class), 'distinct'],
            'cabins.*.seats' => ['required', 'integer', 'min:0', 'max:999'],
            'cabins.*.oversell' => ['nullable', 'integer', 'min:0', 'max:999'],
            'cabins.*.curtail' => ['nullable', 'integer', 'min:0', 'max:999'],
            'rbds' => ['sometimes', 'array'],
            'rbds.*.rbd_id' => ['required', 'integer', 'distinct', Rule::exists('rbds', 'id')->where('airline_id', $airlineId)],
            'rbds.*.seats' => ['required', 'integer', 'min:0', 'max:999'],
            'rbds.*.is_closed' => ['boolean'],
        ];
    }

    protected function afterSave(Model $model, array $data): void
    {
        if ($model->wasRecentlyCreated) {
            $model->forceFill(['created_by' => request()->user()->id])->saveQuietly();
        }

        // a list left out of an update keeps what the template has
        if (array_key_exists('cabins', $data) || array_key_exists('rbds', $data)) {
            app(InventoryService::class)->syncTemplate($model,
                $data['cabins'] ?? $model->cabins()->get()->map(fn ($c) => ['cabin_class' => $c->cabin_class->value] + $c->only(['seats', 'oversell', 'curtail']))->all(),
                $data['rbds'] ?? $model->rbds()->get()->map->only(['rbd_id', 'seats', 'is_closed'])->all());
        }
    }

    /** New template from the current inventory of a flight. */
    public function fromFlight(Request $request, Flight $flight, InventoryService $inventory): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $template = $inventory->templateFromFlight($flight, $data['name'], $data['description'] ?? null);

        return $this->created('Inventory template created.', new InventoryTemplateResource($template->load($this->with())));
    }
}
