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
        private readonly int $trainTimeout = 300,
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
     * Score a batch of instances against a model — the general one, or, given a
     * `$variant`, the organisation's own (ADR 0046).
     *
     * @param  'promotion'|'performance'|'attrition'  $model
     * @param  list<array{ref: string, features: array<string, mixed>}>  $instances
     * @param  array{tenant: string, version: string}|null  $variant
     * @return array{model: string, model_version: ?string, results: list<array<string, mixed>>}
     *
     * @throws MlException
     */
    public function predict(string $model, array $instances, ?array $variant = null): array
    {
        if ($instances === []) {
            return ['model' => $model, 'model_version' => null, 'results' => []];
        }

        // An employee with nothing on record has no features, and PHP encodes an
        // empty array as a JSON list — which the service rightly rejects, failing
        // the whole batch. Features are always encoded as an object.
        $payload = ['instances' => array_map(
            fn (array $instance): array => [...$instance, 'features' => (object) ($instance['features'] ?? [])],
            array_values($instances),
        )];

        if ($variant !== null) {
            $payload['variant'] = $variant;
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            $response = Http::timeout($this->timeout)
                ->withBody($body, 'application/json')
                ->acceptJson()
                ->post($this->url("/predict/{$model}"));
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
                'variant' => $variant,
                'status' => $response->status(),
                'detail' => $response->json('detail', $response->body()),
            ]);

            // The organisation's own model is not on the service (it was redeployed
            // without its stored models). Scoring with the general model instead
            // would misreport whose model spoke, so say what happened.
            if ($variant !== null && $response->status() === 404) {
                throw new MlException(
                    'Your organisation’s own model isn’t available on the prediction service right now. '
                    .'Switch back to the general model to keep scoring, or ask your system administrator to restore it.'
                );
            }

            throw new MlException("Couldn't complete the prediction just now. Please try again shortly.");
        }

        return $response->json() ?? ['model' => $model, 'model_version' => null, 'results' => []];
    }

    /**
     * Fit `$model` on an organisation's own labelled examples and check it against
     * the general model on those same records (ADR 0046). The service stores the
     * model only when it passes.
     *
     * @param  'promotion'|'performance'|'attrition'  $model
     * @param  list<array{group: string, features: array<string, mixed>, outcome: float|int, cycle?: ?string}>  $rows
     * @return array{verdict: string, version: ?string, findings: list<string>, comparison: array<string, mixed>, counts: array<string, int>}
     *
     * @throws MlException
     */
    public function train(string $model, string $tenant, array $rows): array
    {
        $body = json_encode(['tenant' => $tenant, 'rows' => array_map(
            fn (array $row): array => [...$row, 'features' => (object) ($row['features'] ?? [])],
            array_values($rows),
        )], JSON_THROW_ON_ERROR);

        try {
            $response = Http::timeout($this->trainTimeout)
                ->withBody($body, 'application/json')
                ->acceptJson()
                ->post($this->url("/train/{$model}"));
        } catch (ConnectionException $e) {
            Log::warning('ML inference service unreachable for training.', [
                'model' => $model,
                'reason' => $e->getMessage(),
            ]);

            throw new MlException(
                'Training is temporarily unavailable. Please try again shortly.',
                unreachable: true,
            );
        }

        if ($response->failed()) {
            Log::warning('ML inference service refused to train.', [
                'model' => $model,
                'status' => $response->status(),
                'detail' => $response->json('detail', $response->body()),
            ]);

            throw new MlException("Couldn't train on your records just now. Please try again shortly.");
        }

        return $response->json();
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
