<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\Security\PendingActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The user's answer to an action the assistant held for confirmation (ADR 0049).
 *
 * Confirm runs exactly the call that was proposed — the stored tool and
 * arguments, not whatever the model might say now — through the module, which
 * re-checks the permission at that moment. Cancel discards it. Either way the
 * card that asked is marked answered, and its token is spent: it is claimed
 * atomically, so a double click or a replay cannot run the action twice.
 *
 * No model call is made here. Confirming is free.
 */
class AssistantActionController extends Controller
{
    public function confirm(Request $request, Assistant $assistant, PendingActions $pending): JsonResponse
    {
        $token = (string) $request->validate(['token' => ['required', 'string', 'size:48']])['token'];
        $user = $request->user();
        $action = $pending->take($user, $token);

        if ($action === null) {
            return $this->gone();
        }

        $conversation = $this->conversation($user->id, $action['conversation_id']);
        $this->answerCard($conversation, $token, 'confirmed');

        try {
            $result = $assistant->execute($user, $action['tool'], $action['args']);
        } catch (Throwable $e) {
            report($e);

            $result = [
                'reply' => 'Something went wrong while I was doing that, so it may not have happened. Please check before trying again.',
                'steps' => [],
                'actions' => [],
            ];
        }

        $message = $conversation?->messages()->create([
            'role' => 'assistant',
            'body' => $result['reply'],
            'steps' => $result['steps'] ?: null,
            'actions' => $result['actions'] ?: null,
        ]);

        if ($conversation !== null) {
            $conversation->last_activity_at = now();
            $conversation->save();
        }

        return response()->json([
            'state' => 'confirmed',
            'message' => $message?->present() ?? [
                'id' => null,
                'role' => 'assistant',
                'body' => $result['reply'],
                'steps' => $result['steps'],
                'actions' => $result['actions'],
                'attachments' => [],
                'failed' => false,
                'created_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function cancel(Request $request, PendingActions $pending): JsonResponse
    {
        $token = (string) $request->validate(['token' => ['required', 'string', 'size:48']])['token'];
        $user = $request->user();
        $action = $pending->take($user, $token);

        if ($action === null) {
            return $this->gone();
        }

        $this->answerCard($this->conversation($user->id, $action['conversation_id']), $token, 'cancelled');

        return response()->json(['state' => 'cancelled']);
    }

    /**
     * The same answer for "expired", "already used", "never existed" and
     * "somebody else's" — a token cannot be probed.
     */
    private function gone(): JsonResponse
    {
        return response()->json([
            'error' => 'This confirmation has expired or has already been answered. Ask again if you still want it done.',
        ], 410);
    }

    private function conversation(int $userId, ?int $id): ?AssistantConversation
    {
        return $id === null ? null : AssistantConversation::where('user_id', $userId)->find($id);
    }

    /**
     * Mark the card that asked as answered, and drop the spent token from the
     * stored transcript — a used capability has no business being kept.
     */
    private function answerCard(?AssistantConversation $conversation, string $token, string $state): void
    {
        if ($conversation === null) {
            return;
        }

        $messages = $conversation->messages()
            ->where('role', 'assistant')
            ->whereNotNull('actions')
            ->reorder('id', 'desc')
            ->limit(50)
            ->get();

        foreach ($messages as $message) {
            /** @var AssistantMessage $message */
            $actions = $message->actions ?? [];
            $changed = false;

            foreach ($actions as $index => $card) {
                if (($card['confirmation']['token'] ?? null) === $token) {
                    $actions[$index]['confirmation'] = [
                        'token' => null,
                        'state' => $state,
                        'expires_at' => $card['confirmation']['expires_at'] ?? null,
                    ];
                    $changed = true;
                }
            }

            if ($changed) {
                $message->actions = $actions;
                $message->save();

                return;
            }
        }
    }
}
