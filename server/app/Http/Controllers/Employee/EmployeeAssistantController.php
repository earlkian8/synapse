<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Services\Employee\EmployeeAgent;
use App\Support\Ai\GeminiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * JSON endpoint backing the floating Employee assistant. Runs entirely
 * server-side so the Gemini key is never exposed and every mutation is gated by
 * the same permissions as the manual UI.
 */
class EmployeeAssistantController extends Controller
{
    public function __invoke(Request $request, EmployeeAgent $agent): JsonResponse
    {
        if (! $agent->configured()) {
            return response()->json([
                'reply' => 'The assistant is not configured yet. Add a GEMINI_API_KEY to enable it.',
                'steps' => [],
                'actions' => [],
            ], 503);
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:4000'],
            'history' => ['nullable'],
            'files' => ['nullable', 'array', 'max:8'],
            'files.*' => ['file', 'mimes:pdf,png,jpg,jpeg,webp,txt', 'max:8192'],
        ]);

        $message = trim((string) ($validated['message'] ?? ''));
        $history = $this->normaliseHistory($request->input('history'));
        $files = $request->file('files', []);

        if ($message === '' && $files === []) {
            return response()->json([
                'reply' => 'Tell me what you need — for example, “add a new employee” or attach one or more CVs.',
                'steps' => [],
                'actions' => [],
            ]);
        }

        $fileParts = [];

        foreach ($files as $file) {
            $fileParts[] = [
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'data' => base64_encode((string) file_get_contents($file->getRealPath())),
            ];
        }

        try {
            $result = $agent->handle($request->user(), $message, $history, $fileParts);
        } catch (GeminiException $e) {
            // Rate-limited / overloaded: show a calm, retryable message in-chat.
            if ($e->isBusy()) {
                return response()->json([
                    'reply' => "I'm getting a lot of requests right now and hit the AI rate limit. Please wait a few seconds and try again.",
                    'steps' => [],
                    'actions' => [],
                ]);
            }

            report($e);

            return response()->json([
                'reply' => 'Something went wrong while I was working on that. Please try again.',
                'steps' => [],
                'actions' => [],
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'reply' => 'Something went wrong while I was working on that. Please try again.',
                'steps' => [],
                'actions' => [],
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        return response()->json($result);
    }

    /**
     * Accept history as either a JSON string (multipart) or an array.
     *
     * @return array<int, array{role?: string, text?: string}>
     */
    private function normaliseHistory(mixed $history): array
    {
        if (is_string($history)) {
            $decoded = json_decode($history, true);
            $history = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($history)) {
            return [];
        }

        return collect($history)
            ->filter(fn ($turn): bool => is_array($turn) && isset($turn['text']))
            ->map(fn (array $turn): array => [
                'role' => (string) ($turn['role'] ?? 'user'),
                'text' => (string) ($turn['text'] ?? ''),
            ])
            ->take(20)
            ->values()
            ->all();
    }
}
