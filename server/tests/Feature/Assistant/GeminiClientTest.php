<?php

use App\Support\Ai\GeminiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| The Gemini wrapper's request shape (ADR 0068 §7). No real request is made:
| every call is answered by Http::fake, and what was sent is what is asserted.
*/

function geminiReply(array $parts = [['text' => 'Hi.']], ?string $finish = 'STOP'): array
{
    return [
        'candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $finish]],
        'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 8, 'totalTokenCount' => 128],
    ];
}

function sentGenerationConfig(): array
{
    $sent = Http::recorded()->first()[0];

    return $sent instanceof Request ? (array) ($sent->data()['generationConfig'] ?? []) : [];
}

test('a Gemini 3 model is left at its own default temperature', function () {
    Http::fake(['*' => Http::response(geminiReply())]);

    (new GeminiClient('key', 'gemini-3.5-flash-lite'))->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);

    expect(sentGenerationConfig())->not->toHaveKey('temperature');
});

test('a Gemini 2 model keeps the low temperature it was tuned on', function () {
    Http::fake(['*' => Http::response(geminiReply())]);

    (new GeminiClient('key', 'gemini-2.5-flash'))->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);

    expect(sentGenerationConfig()['temperature'] ?? null)->toBe(0.2);
});

test('a configured temperature and thinking level are sent as given', function () {
    Http::fake(['*' => Http::response(geminiReply())]);

    (new GeminiClient('key', 'gemini-3.5-flash-lite', temperature: 0.7, thinkingLevel: 'low'))
        ->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);

    expect(sentGenerationConfig()['temperature'] ?? null)->toBe(0.7)
        ->and(sentGenerationConfig()['thinkingConfig'] ?? null)->toBe(['thinkingLevel' => 'low']);
});

test('no thinking level is sent unless one is configured', function () {
    Http::fake(['*' => Http::response(geminiReply())]);

    (new GeminiClient('key', 'gemini-3.5-flash-lite'))->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);

    expect(sentGenerationConfig())->not->toHaveKey('thinkingConfig');
});

test('a malformed function call is retried once', function () {
    Http::fake(['*' => Http::sequence()
        ->push(geminiReply([], 'MALFORMED_FUNCTION_CALL'))
        ->push(geminiReply([['text' => 'Second try.']]))]);

    $response = (new GeminiClient('key', 'gemini-3.5-flash-lite'))->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);

    expect(Http::recorded())->toHaveCount(2)
        ->and(data_get($response, 'candidates.0.content.parts.0.text'))->toBe('Second try.');
});

test('an empty answer is retried once, then returned as it is', function () {
    Http::fake(['*' => Http::response(['candidates' => []])]);

    $response = (new GeminiClient('key', 'gemini-3.5-flash-lite'))->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);

    expect(Http::recorded())->toHaveCount(2)
        ->and($response['candidates'])->toBe([]);
});

test('the usage the API reports comes back with the response', function () {
    Http::fake(['*' => Http::response(geminiReply())]);

    $response = (new GeminiClient('key', 'gemini-3.5-flash-lite'))->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);

    expect($response['usageMetadata']['promptTokenCount'])->toBe(120);
});
