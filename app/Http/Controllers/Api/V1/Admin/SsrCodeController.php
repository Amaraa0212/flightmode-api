<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SsrCodeResource;
use App\Models\SsrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SsrCodeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        return $this->created('SSR code created.', new SsrCodeResource(SsrCode::create($this->validated($request))));
    }

    public function update(Request $request, SsrCode $ssrCode): JsonResponse
    {
        $ssrCode->update($this->validated($request, $ssrCode));

        return $this->success('SSR code updated.', new SsrCodeResource($ssrCode));
    }

    public function destroy(SsrCode $ssrCode): JsonResponse
    {
        $ssrCode->delete();

        return $this->deleted('SSR code deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SsrCode $ssr = null): array
    {
        $required = $ssr ? 'sometimes' : 'required';
        $request->merge(['code' => strtoupper((string) $request->input('code', $ssr?->code))]);

        return $request->validate([
            'code' => [$required, 'alpha_num', 'max:8', Rule::unique('ssr_codes')->ignore($ssr?->id)],
            'name' => [$required, 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
