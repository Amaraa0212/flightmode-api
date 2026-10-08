<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CountryResource;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CountryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        return $this->created('Country created.', new CountryResource(Country::create($this->validated($request))));
    }

    public function update(Request $request, Country $country): JsonResponse
    {
        $country->update($this->validated($request, $country));

        return $this->success('Country updated.', new CountryResource($country));
    }

    public function destroy(Country $country): JsonResponse
    {
        $country->delete();

        return $this->deleted('Country deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Country $country = null): array
    {
        $required = $country ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'alpha2' => [$required, 'alpha', 'size:2', Rule::unique('countries')->ignore($country?->id)],
            'alpha3' => [$required, 'alpha', 'size:3', Rule::unique('countries')->ignore($country?->id)],
            'numeric_code' => ['nullable', 'digits:3'],
        ]);
    }
}
