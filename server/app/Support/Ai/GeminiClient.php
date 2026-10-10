<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin server-side wrapper over the Google Gemini `generateContent` REST
 * endpoint. The API key lives in config (env) and never leaves the backend.
 *
 * Supports function-calling: pass `function_declarations` as tools and the
 * model may answer with `functionCall` parts that the caller executes, feeding
 * the results back across a bounded multi-step loop.
 */
class GeminiClient
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        private readonly ?float $temperature = null,
        private readonly ?string $thinkingLevel = null,
    ) {}

    /**
     * Whether the assistant is configured (an API key is present).
     */
    public function configured(): bool
    {
        return filled($this->apiKey);
    }

    /**
     * Run one generation turn.
     *
     * @param  array<int, array<string, mixed>>  $contents  The conversation so far.
     * @param  array<int, array<string, mixed>>  $functionDeclarations  Tool schemas the model may call.
     * @return array<string, mixed> The decoded Gemini response.
     */
    public function generate(array $contents, array $functionDeclarations = [], ?string $systemInstruction = null): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('The assistant is not configured. Set GEMINI_API_KEY in your environment.');
        }

        $payload = ['contents' => $contents];
        $generation = $this->generationConfig();

        if ($generation !== []) {
            $payload['generationConfig'] = $generation;
        }

        if ($systemInstruction !== null) {
            $payload['system_instruction'] = ['parts' => [['text' => $systemInstruction]]];
        }

        if ($functionDeclarations !== []) {
            $payload['tools'] = [['function_declarations' => $functionDeclarations]];
            $payload['tool_config'] = ['function_calling_config' => ['mode' => 'AUTO']];
        }

        $decoded = $this->post($payload);

        // A model sometimes answers with nothing, or with a function call it
        // could not write out. Both are flukes of one sample rather than the
        // request, so the same request is tried once more before the caller
        // has to make sense of an empty turn.
        if ($this->unusable($decoded)) {
            $decoded = $this->post($payload);
        }

        return $decoded;
    }

    /**
     * Sampling settings. Gemini 3 models are tuned for their default
     * temperature (Google warns a lower one can make them loop), so it is
     * only sent when configured — except on the Gemini 2 models this was
     * first built on, which keep the low 0.2 they were tuned with.
     *
     * @return array<string, mixed>
     */
    private function generationConfig(): array
    {
        $config = [];
        $temperature = $this->temperature ?? (str_starts_with($this->model, 'gemini-2') ? 0.2 : null);

        if ($temperature !== null) {
            $config['temperature'] = $temperature;
        }

        if (filled($this->thinkingLevel)) {
            $config['thinkingConfig'] = ['thinkingLevel' => $this->thinkingLevel];
        }

        return $config;
    }

    /**
     * Whether a response carries nothing a caller can use: no candidate, or a
     * candidate whose function call came out malformed.
     *
     * @param  array<string, mixed>  $response
     */
    private function unusable(array $response): bool
    {
        $candidate = $response['candidates'][0] ?? null;

        return ! is_array($candidate)
            || ($candidate['finishReason'] ?? null) === 'MALFORMED_FUNCTION_CALL';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        // Briefly retry only the transient 503 "high demand" overload (which
        // usually clears in a second). A 429 is a real quota/rate limit — the
        // quota won't refill in milliseconds, so fail fast and let the caller
        // surface a friendly "try again shortly" message.
        $attempt = 0;

        do {
            $response = Http::timeout(60)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->asJson()
                ->post("{$this->baseUrl}/models/{$this->model}:generateContent", $payload);

            if ($response->status() !== 503) {
                break;
            }

            if (++$attempt < 3) {
                usleep(800_000 * $attempt);
            }
        } while ($attempt < 3);

        if ($response->failed()) {
            throw new GeminiException(
                $response->status(),
                'Gemini request failed ('.$response->status().'): '.$response->json('error.message', $response->body())
            );
        }

        return $response->json() ?? [];
    }
}
