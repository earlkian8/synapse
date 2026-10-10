<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\Attachments\ConversationAttachments;
use App\Services\Assistant\TurnState;
use App\Support\Ai\GeminiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The chat turn endpoint behind the floating Synapse assistant. Runs the agent
 * server-side (the Gemini key never leaves the backend), persists each turn to
 * the signed-in user's conversation history, and supports regenerating the last
 * answer. Every action the agent takes is permission-gated inside its modules.
 */
class AssistantController extends Controller
{
    /**
     * Handle a chat turn: persist the user message, run the agent, persist the
     * reply, and return the assistant turn (creating/continuing a conversation).
     */
    public function send(Request $request, Assistant $assistant): JsonResponse
    {
        if (! $assistant->configured()) {
            return response()->json(['reply' => 'The assistant is not configured yet. Add a GEMINI_API_KEY to enable it.'], 503);
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'integer'],
            'replace_message_id' => ['nullable', 'integer'],
            'files' => ['nullable', 'array', 'max:8'],
            'files.*' => ['file', 'mimes:pdf,png,jpg,jpeg,webp,txt', 'max:8192'],
            'mode' => ['nullable', Rule::in(TurnState::MODES)],
        ]);

        $message = trim((string) ($validated['message'] ?? ''));
        $files = $request->file('files', []);

        if ($message === '' && $files === []) {
            return response()->json(['error' => 'Empty message.'], 422);
        }

        $user = $request->user();
        $conversation = $this->resolveConversation($request, $user->id);

        // Editing a prior message: drop it and everything after, then re-send.
        if ($replaceId = ($validated['replace_message_id'] ?? null)) {
            $conversation->messages()->where('id', '>=', (int) $replaceId)->delete();
        }

        // Kept, not just read once (ADR 0068): a tool may file one of these on
        // a record, now or after a Confirm, and a later turn may refer back.
        $attachments = collect($files)
            ->map(fn (UploadedFile $file): array => ConversationAttachments::store($conversation, $file))
            ->all();

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'body' => $message !== '' ? $message : '['.count($files).' file'.(count($files) === 1 ? '' : 's').' attached]',
            'attachments' => $attachments ?: null,
        ]);

        return $this->run($assistant, $conversation, $user, $userMessage, $this->mode($request));
    }

    /**
     * Regenerate the most recent answer in a conversation (re-runs the last user
     * message, with the files it carried).
     */
    public function regenerate(Request $request, Assistant $assistant, AssistantConversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        if (! $assistant->configured()) {
            return response()->json(['reply' => 'The assistant is not configured yet.'], 503);
        }

        $request->validate(['mode' => ['nullable', Rule::in(TurnState::MODES)]]);

        // reorder() clears the relation's default id-ASC sort so we truly get the
        // most recent user message (not the oldest).
        $lastUser = $conversation->messages()->where('role', 'user')->reorder('id', 'desc')->first();

        if (! $lastUser) {
            return response()->json(['error' => 'Nothing to regenerate.'], 422);
        }

        // Drop any answers after that user message, then re-run it.
        $conversation->messages()->where('id', '>', $lastUser->id)->delete();

        return $this->run($assistant, $conversation, $request->user(), $lastUser, $this->mode($request));
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Run the agent for a (already-persisted) user message and persist the reply.
     */
    private function run(Assistant $assistant, AssistantConversation $conversation, $user, AssistantMessage $userMessage, string $mode): JsonResponse
    {
        $history = $this->history($conversation, $userMessage->id);
        $prompt = (string) $userMessage->body;

        $inbox = app(ConversationAttachments::class);
        $inbox->use($conversation, $user, $userMessage->id);
        $fileParts = $this->fileParts($inbox, $prompt);

        try {
            $result = $assistant->handle($user, $prompt, $history, $fileParts, $conversation->id, $mode);
        } catch (GeminiException $e) {
            if (! $e->isBusy()) {
                report($e);
            }

            return $this->transientFailure(
                $conversation,
                $userMessage,
                $e->isBusy() ? $this->busyMessage($e) : 'Something went wrong while I was working on that. Please try again.',
            );
        } catch (Throwable $e) {
            report($e);

            return $this->transientFailure($conversation, $userMessage, 'Something went wrong while I was working on that. Please try again.');
        }

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'body' => $result['reply'],
            'steps' => $result['steps'] ?: null,
            'actions' => $result['actions'] ?: null,
            'usage' => $result['usage'] ?? null,
        ]);

        if (blank($conversation->title)) {
            $conversation->title = AssistantConversation::deriveTitle((string) $userMessage->body);
        }

        $conversation->last_activity_at = now();
        $conversation->save();

        return response()->json([
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
            'user_message_id' => $userMessage->id,
            'message' => $assistantMessage->present(),
        ]);
    }

    /**
     * A failed turn: the user message is kept (so the thread shows it), but no
     * assistant message is persisted — the client shows a retryable notice.
     */
    private function transientFailure(AssistantConversation $conversation, AssistantMessage $userMessage, string $reply): JsonResponse
    {
        if (blank($conversation->title)) {
            $conversation->title = AssistantConversation::deriveTitle((string) $userMessage->body);
        }

        $conversation->last_activity_at = now();
        $conversation->save();

        return response()->json([
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
            'user_message_id' => $userMessage->id,
            'message' => [
                'id' => null,
                'role' => 'assistant',
                'body' => $reply,
                'steps' => [],
                'actions' => [],
                'attachments' => [],
                'failed' => true,
                'created_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function resolveConversation(Request $request, int $userId): AssistantConversation
    {
        $id = $request->integer('conversation_id');

        if ($id) {
            $existing = AssistantConversation::where('user_id', $userId)->find($id);

            if ($existing) {
                return $existing;
            }
        }

        return AssistantConversation::create(['user_id' => $userId, 'last_activity_at' => now()]);
    }

    private function authorizeConversation(Request $request, AssistantConversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
    }

    /**
     * The prior turns (before the given message id) as agent history: what was
     * said, and for the assistant's turns what it did and with which modules,
     * so a follow-up can build on it.
     *
     * @return array<int, array{role: string, text: string, steps: list<string>, modules: list<string>}>
     */
    private function history(AssistantConversation $conversation, int $beforeId): array
    {
        return $conversation->messages()
            ->where('id', '<', $beforeId)
            ->get()
            ->filter(fn (AssistantMessage $m): bool => filled($m->body))
            ->map(fn (AssistantMessage $m): array => [
                'role' => $m->role,
                'text' => (string) $m->body,
                'steps' => collect($m->steps ?? [])
                    ->filter(fn (mixed $step): bool => is_array($step) && ($step['kind'] ?? 'action') === 'action' && ($step['status'] ?? '') !== 'error')
                    ->pluck('label')
                    ->filter(fn (mixed $label): bool => is_string($label))
                    ->values()
                    ->all(),
                'modules' => collect($m->actions ?? [])->pluck('module')->filter(fn (mixed $key): bool => is_string($key))->unique()->values()->all(),
            ])
            ->take(-20)
            ->values()
            ->all();
    }

    /** The mode the person chose for this message (ADR 0068 §5). */
    private function mode(Request $request): string
    {
        $mode = (string) $request->input('mode', TurnState::BALANCED);

        return in_array($mode, TurnState::MODES, true) ? $mode : TurnState::BALANCED;
    }

    /**
     * The files the model reads this turn, base64-encoded for multimodal input:
     * the ones this message carried, or — when it carried none and talks about
     * "the CV" or "that document" — the most recent earlier one. Gemini reads
     * PDFs and images itself, scans included; that is how a CV's details reach
     * a tool call. Capped so one request stays under Gemini's size limit.
     *
     * @return array<int, array{mime: string, data: string}>
     */
    private function fileParts(ConversationAttachments $inbox, string $prompt): array
    {
        $files = array_values(array_filter($inbox->all(), fn ($file): bool => $file->current));

        if ($files === []) {
            $files = $inbox->replayFor($prompt);
        }

        $parts = [];
        $budget = ConversationAttachments::INLINE_BUDGET;

        foreach ($files as $file) {
            if ($file->size > $budget) {
                continue;
            }

            try {
                $data = base64_encode($file->contents());
            } catch (Throwable) {
                continue;
            }

            $budget -= strlen($data);

            if ($budget < 0) {
                break;
            }

            $parts[] = ['mime' => $file->mime, 'data' => $data];
        }

        return $parts;
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
}
