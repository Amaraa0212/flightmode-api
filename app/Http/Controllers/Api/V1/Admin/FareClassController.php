<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TripType;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\FareClassResource;
use App\Models\FareClass;
use App\Services\Fare\FareService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FareClassController extends AirlineCrudController
{
    protected function model(): string
    {
        return FareClass::class;
    }

    protected function resource(): string
    {
        return FareClassResource::class;
    }

    protected function label(): string
    {
        return 'Fare class';
    }

    protected function with(): array
    {
        return ['rbd:id,code,cabin_class', 'discounts'];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $query->withCount('fares')
            ->when($request->filled('rbd_id'), fn ($q) => $q->where('rbd_id', $request->integer('rbd_id')))
            ->when($request->filled('trip_type'), fn ($q) => $q->where('trip_type', $request->string('trip_type')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($s) => $s
                ->where('booking_code', 'like', '%'.$request->string('search').'%')
                ->orWhere('description', 'like', '%'.$request->string('search').'%')));
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';
        $airlineId = $this->targetAirlineId($request, $model);

        return [
            'rbd_id' => [$required, 'integer', Rule::exists('rbds', 'id')->where('airline_id', $airlineId)],
            'booking_code' => [$required, 'string', 'max:10', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('fare_classes')->where('airline_id', $airlineId)->ignore($model?->getKey())],
            'description' => ['nullable', 'string', 'max:100'],
            'status' => [$required, Rule::in(FareClass::STATUSES)],
            'waitlist' => ['boolean'],
            'is_visible' => ['boolean'],
            'trip_type' => [$required, Rule::enum(TripType::class)],
            'rule' => ['nullable', 'string'],
            'is_group' => ['boolean'],
            'group_min' => ['nullable', 'required_if_accepted:is_group', 'integer', 'min:1'],
            'group_max' => ['nullable', 'integer', Rule::when($request->filled('group_min'), 'gte:group_min')],
            'is_active' => ['boolean'],
        ];
    }

    protected function afterSave(Model $model, array $data): void
    {
        if ($model->wasRecentlyCreated) {
            $model->forceFill(['created_by' => request()->user()->id])->saveQuietly();
        }

        app(FareService::class)->syncWhere(fn (Builder $q) => $q->where('fare_class_id', $model->getKey()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var FareClass $class */
        $class = $this->find($request, $id);

        if ($class->fares()->exists()) {
            throw BusinessRuleException::make('fare_class_in_use',
                'This fare class is used by fares and cannot be deleted. Set it inactive instead.');
        }

        $class->delete();

        return $this->deleted('Fare class deleted.');
    }
}
