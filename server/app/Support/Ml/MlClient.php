<?php

namespace App\Support\Ml;

use App\Support\Ai\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin server-side wrapper over the Synapse ML inference service (FastAPI, see
 * model/api). It scores batches of instances against a named model and reports
 * service health.
 *
 * Mirrors {@see GeminiClient}: the base URL lives in config, the
 * call never reaches the browser, and a failure raises a typed {@see MlException}
 * the caller can degrade on instead of bubbling a 500.
 *
 * An {@see MlException}'s message is shown to the person who clicked the button,
 * so it is written for them: what happened and what to do about it, never a shell
 * command, a status code, or the service's own response body. The diagnostic
 * detail is logged instead, where whoever operates the service will look for it.
 */
class MlClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 30,
    ) {}

    /**
     * Liveness + loaded-model metadata, or null when the service is unreachable.
     *
     * @return array<string, mixed>|null
     */
    public function health(): ?array
    {
        try {
            $response = Http::timeout(min($this->timeout, 5))->get($this->url('/health'));
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Score a batch of instances against a model.
     *
     * @param  'promotion'|'performance'  $model
     * @param  list<array{ref: string, features: array<string, mixed>}>  $instances
     * @return array{model: string, model_version: ?string, results: list<array<string, mixed>>}
     *
     * @throws MlException
     */
    public function predict(string $model, array $instances): array
    {
        if ($instances === []) {
            return ['model' => $model, 'model_version' => null, 'results' => []];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->asJson()
                ->post($this->url("/predict/{$model}"), ['instances' => array_values($instances)]);
        } catch (ConnectionException $e) {
            Log::warning('ML inference service unreachable.', [
                'model' => $model,
                'url' => $this->url("/predict/{$model}"),
                'reason' => $e->getMessage(),
            ]);

            throw new MlException(
                'Predictions are temporarily unavailable. Please try again shortly.',
                unreachable: true,
            );
        }

        if ($response->failed()) {
            Log::warning('ML inference service returned an error.', [
                'model' => $model,
                'status' => $response->status(),
                'detail' => $response->json('detail', $response->body()),
            ]);

            throw new MlException("Couldn't complete the prediction just now. Please try again shortly.");
        }

        return $response->json() ?? ['model' => $model, 'model_version' => null, 'results' => []];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
