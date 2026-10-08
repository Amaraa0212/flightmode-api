<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class Controller
{
    /** Response for an action: `{ message, data }`. */
    protected function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        return response()->json(array_filter(
            ['message' => $message, 'data' => $data],
            fn ($value) => $value !== null,
        ), $status);
    }

    protected function created(string $message, mixed $data = null): JsonResponse
    {
        return $this->success($message, $data, 201);
    }

    protected function deleted(string $message = 'Deleted.'): JsonResponse
    {
        return response()->json(['message' => $message]);
    }
}
