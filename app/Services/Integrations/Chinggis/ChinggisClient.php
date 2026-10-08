<?php

namespace App\Services\Integrations\Chinggis;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** HTTP client for the Chinggis Airlines agent API. Never throws: failures are logged. */
class ChinggisClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.chinggis.base_url')) && filled(config('services.chinggis.key'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, body: mixed}|null
     */
    public function post(string $path, array $payload): ?array
    {
        if (! $this->isConfigured()) {
            Log::warning('Chinggis API is not configured.', ['path' => $path]);

            return null;
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.chinggis.base_url'), '/'))
                ->timeout((int) config('services.chinggis.timeout', 15))
                ->acceptJson()
                ->withHeaders(['x-api-key' => config('services.chinggis.key')])
                ->post($path, $payload);

            if ($response->failed()) {
                Log::warning('Chinggis API request failed.', ['path' => $path, 'status' => $response->status(), 'body' => $response->body()]);
            }

            return ['ok' => $response->successful(), 'status' => $response->status(), 'body' => $response->json()];
        } catch (Throwable $e) {
            Log::error('Chinggis API request exception.', ['path' => $path, 'message' => $e->getMessage()]);

            return null;
        }
    }
}
