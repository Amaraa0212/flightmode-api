<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\TaxCodeResource;
use App\Models\TaxCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxCodeController extends AirlineCrudController
{
    protected function model(): string
    {
        return TaxCode::class;
    }

    protected function resource(): string
    {
        return TaxCodeResource::class;
    }

    protected function label(): string
    {
        return 'Tax code';
    }

    protected function filter(Builder $query, Request $request): void
    {
        // faceted filters arrive as arrays (?is_active[]=true)
        $active = array_unique((array) $request->input('is_active', []));
        $query->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x
            ->where('code', 'like', $request->string('search')->upper().'%')
            ->orWhere('name', 'like', '%'.$request->string('search').'%')))
            ->when(count($active) === 1, fn ($q) => $q->where('is_active', filter_var($active[0], FILTER_VALIDATE_BOOLEAN)));
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:10', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('tax_codes')->where('airline_id', $this->targetAirlineId($request, $model))->ignore($model?->getKey())],
            'name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var TaxCode $code */
        $code = $this->find($request, $id);

        if ($code->fareTaxes()->exists()) {
            throw BusinessRuleException::make('tax_code_in_use', 'This tax code is used by fare taxes and cannot be deleted.');
        }

        $code->delete();

        return $this->deleted('Tax code deleted.');
    }
}
