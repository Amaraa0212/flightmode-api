<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A request that is well-formed but breaks a business rule
 * (cabin full, check-in closed, seat taken ...). Rendered as HTTP 422.
 */
class BusinessRuleException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $context */
    public static function make(string $code, string $message, array $context = [], int $status = 422): self
    {
        return new self($code, $message, $context, $status);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'context' => $this->context ?: null,
        ]), $this->status);
    }
}
