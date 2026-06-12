<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin server-side wrapper over the Google Gemini `generateContent` REST
 * endpoint. The API key lives in config (env) and never leaves the backend.
 *
 * Supports function-calling: pass `function_declarations` as tools and the
 * model may answer with `functionCall` parts that the caller executes.
 */
class GeminiClient
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
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
     * @param  array<int, array<string, mixed>>  $contents       The conversation so far.
     * @param  array<int, array<string, mixed>>  $functionDeclarations  Tool schemas the model may call.
     * @return array<string, mixed>  The decoded Gemini response.
     */
    public function generate(array $contents, array $functionDeclarations = [], ?string $systemInstruction = null): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('The assistant is not configured. Set GEMINI_API_KEY in your environment.');
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.2,
            ],
        ];

        if ($systemInstruction !== null) {
            $payload['system_instruction'] = ['parts' => [['text' => $systemInstruction]]];
        }

        if ($functionDeclarations !== []) {
            $payload['tools'] = [['function_declarations' => $functionDeclarations]];
            $payload['tool_config'] = ['function_calling_config' => ['mode' => 'AUTO']];
        }

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
