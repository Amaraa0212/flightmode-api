<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CabinClass;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\RbdResource;
use App\Models\Rbd;
use App\Services\Fare\FareService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RbdController extends AirlineCrudController
{
    protected function model(): string
    {
        return Rbd::class;
    }

    protected function resource(): string
    {
        return RbdResource::class;
    }

    protected function label(): string
    {
        return 'RBD';
    }

    protected function with(): array
    {
        return ['parent:id,code', 'creator:id,name'];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $query->withCount(['fareClasses', 'children'])
            ->when($request->filled('cabin_class'), fn ($q) => $q->where('cabin_class', $request->string('cabin_class')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')));
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';
        $airlineId = $this->targetAirlineId($request, $model);

        return [
            'code' => [$required, 'string', 'max:2', 'regex:/^[A-Z]+$/',
                Rule::unique('rbds')->where('airline_id', $airlineId)->ignore($model?->getKey())],
            'cabin_class' => [$required, Rule::enum(CabinClass::class)],
            'rank' => [$required, 'integer', 'min:0', 'max:999'],
            'parent_id' => ['nullable', 'integer', Rule::exists('rbds', 'id')->where('airline_id', $airlineId),
                Rule::notIn(array_filter([$model?->getKey()]))],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    protected function afterSave(Model $model, array $data): void
    {
        if ($model->wasRecentlyCreated) {
            $model->forceFill(['created_by' => request()->user()->id])->saveQuietly();
        }

        // code / cabin are copied onto fares of this RBD
        app(FareService::class)->syncWhere(fn (Builder $q) => $q->whereHas('fareClass', fn ($c) => $c->where('rbd_id', $model->getKey())));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var Rbd $rbd */
        $rbd = $this->find($request, $id);

        if ($rbd->fareClasses()->exists() || $rbd->children()->exists()) {
            throw BusinessRuleException::make('rbd_in_use',
                'This RBD has fare classes or child RBDs and cannot be deleted. Set it inactive instead.');
        }

        $rbd->delete();

        return $this->deleted('RBD deleted.');
    }
}
