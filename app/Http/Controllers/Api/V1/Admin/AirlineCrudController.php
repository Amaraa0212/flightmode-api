<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Access\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Standard CRUD for tables owned by an airline (airline_id column).
 * Airline users read / write their own airline only; system admins pass `airline_id`.
 */
abstract class AirlineCrudController extends Controller
{
    public function __construct(protected readonly AccessScope $access) {}

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resource(): string;

    /** @return array<string, mixed> validation rules; $model is null when creating */
    abstract protected function rules(Request $request, ?Model $model): array;

    protected function label(): string
    {
        return class_basename($this->model());
    }

    /** @return array<int, string> */
    protected function with(): array
    {
        return [];
    }

    protected function filter(Builder $query, Request $request): void {}

    /** Hook for pivots etc.; runs inside the transaction. @param array<string, mixed> $data */
    protected function afterSave(Model $model, array $data): void {}

    /** Columns that are not attributes of the model (e.g. pivot ids). @return array<int, string> */
    protected function nonAttributes(): array
    {
        return [];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->access->airlineOwned($this->model()::query(), $request->user())
            ->with($this->with())
            ->when($request->filled('airline_id'), fn ($q) => $q->where('airline_id', $request->integer('airline_id')));
        $this->filter($query, $request);

        return $this->resource()::collection($query->orderByDesc('id')->paginate($request->integer('per_page', 100)));
    }

    public function show(Request $request, int $id): JsonResource
    {
        $model = $this->find($request, $id);

        return new ($this->resource())($model->load($this->with()));
    }

    public function store(Request $request): JsonResponse
    {
        $airlineId = $this->access->airlineIdForWrite($request->user(), $request->integer('airline_id') ?: null);
        $data = $request->validate($this->rules($request, null));

        $model = DB::transaction(function () use ($data, $airlineId) {
            $model = $this->model()::create(collect($data)->except(array_merge($this->nonAttributes(), ['airline_id']))->all() + ['airline_id' => $airlineId]);
            $this->afterSave($model, $data);

            return $model;
        });

        return $this->created($this->label().' created.', new ($this->resource())($model->load($this->with())));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $model = $this->find($request, $id);
        $data = $request->validate($this->rules($request, $model));

        DB::transaction(function () use ($model, $data) {
            $model->update(collect($data)->except(array_merge($this->nonAttributes(), ['airline_id']))->all());
            $this->afterSave($model, $data);
        });

        return $this->success($this->label().' updated.', new ($this->resource())($model->load($this->with())));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return $this->deleted($this->label().' deleted.');
    }

    /** Airline the record is (or will be) written for: the model's own airline, or the one store() will use. */
    protected function targetAirlineId(Request $request, ?Model $model): int
    {
        return $model ? (int) $model->getAttribute('airline_id')
            : $this->access->airlineIdForWrite($request->user(), $request->integer('airline_id') ?: null);
    }

    protected function find(Request $request, int $id): Model
    {
        return $this->access->airlineOwned($this->model()::query(), $request->user())->findOrFail($id);
    }
}
