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

        // Transparently retry the transient overload/rate-limit statuses
        // (429/503) Gemini returns under load, with a short linear backoff.
        $attempt = 0;

        do {
            $response = Http::timeout(60)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->asJson()
                ->post("{$this->baseUrl}/models/{$this->model}:generateContent", $payload);

            if (! in_array($response->status(), [429, 503], true)) {
                break;
            }

            if (++$attempt < 3) {
                usleep(800_000 * $attempt);
            }
        } while ($attempt < 3);

        if ($response->failed()) {
            throw new RuntimeException(
                'Gemini request failed ('.$response->status().'): '.$response->json('error.message', $response->body())
            );
        }

        return $response->json() ?? [];
    }
}
