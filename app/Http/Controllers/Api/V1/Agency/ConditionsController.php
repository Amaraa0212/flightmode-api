<?php

namespace App\Http\Controllers\Api\V1\Agency;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The agency's own conditions of carriage (Markdown), printed on its e-tickets when they carry the agency's details. */
class ConditionsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $agency = $this->agency($request);

        return response()->json(['data' => [
            'conditions_of_carriage' => $agency->conditions_of_carriage,
            'updated_at' => $agency->updated_at?->toIso8601String(),
            'can_update' => $request->user()->hasPermission('agency_conditions.update'),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        $data = $request->validate(['conditions_of_carriage' => ['nullable', 'string', 'max:60000']]);
        $agency->update(['conditions_of_carriage' => trim((string) ($data['conditions_of_carriage'] ?? '')) ?: null]);

        return $this->success('Conditions of carriage saved.', ['updated_at' => $agency->updated_at?->toIso8601String()]);
    }

    private function agency(Request $request): Agency
    {
        $agency = $request->user()->agency;
        if (! $agency) {
            throw new AuthorizationException('Your account is not linked to an agency.');
        }

        return $agency;
    }
}
