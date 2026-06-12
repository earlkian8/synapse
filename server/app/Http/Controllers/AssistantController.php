<?php

namespace App\Http\Controllers;

use App\Services\Assistant\Assistant;
use App\Support\Ai\GeminiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * JSON endpoint backing the floating Nexo assistant. Runs entirely server-side
 * so the Gemini key is never exposed, and every action it takes is gated by the
 * same permissions as the manual UI (enforced inside each module).
 */
class AssistantController extends Controller
{
    public function __invoke(Request $request, Assistant $assistant): JsonResponse
    {
        if (! $assistant->configured()) {
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
                'reply' => 'Tell me what you need — for example, “file sick leave for Maria tomorrow”, “add a new employee”, or attach a CV.',
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
            $result = $assistant->handle($request->user(), $message, $history, $fileParts);
        } catch (GeminiException $e) {
            // Rate-limited / overloaded: show a calm, accurate, retryable message.
            if ($e->isBusy()) {
                return response()->json([
                    'reply' => $this->busyMessage($e),
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
     * An honest in-chat message for a busy/limited AI, distinguishing the cases:
     *  - 503: the model is briefly overloaded (retry in seconds).
     *  - 429 with a short retry delay: a per-minute pacing burst (the free tier
     *    allows only a few requests/minute); it clears on its own.
     *  - 429 with a long/absent delay: the larger allowance is used up for now.
     */
    private function busyMessage(GeminiException $e): string
    {
        if ($e->isOverloaded()) {
            return 'The AI is briefly overloaded right now. Please try again in a few seconds.';
        }

        $retry = $e->retryAfterSeconds();

        if ($retry !== null && $retry <= 75) {
            return "I'm sending requests a little faster than the free tier allows (only a few per minute). ".
                "Give it about {$retry}s and try again — nothing's wrong on your end.";
        }

        return "I've used up the AI free-tier allowance for now; it resets after a while. ".
            'Enabling billing on the API key removes this limit.';
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
