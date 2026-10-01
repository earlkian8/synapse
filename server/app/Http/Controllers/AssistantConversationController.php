<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for the signed-in user's assistant conversation history (list, open,
 * rename/pin, delete, clear). Threads are tenant-scoped and owned by one user.
 */
class AssistantConversationController extends Controller
{
    /**
     * The user's conversations, most-recently-active first, with a preview line.
     */
    public function index(Request $request): JsonResponse
    {
        $conversations = AssistantConversation::query()
            ->forUser($request->user()->id)
            ->with('latestMessage')
            ->limit(200)
            ->get()
            ->map(fn (AssistantConversation $c): array => $c->present())
            ->all();

        return response()->json(['conversations' => $conversations]);
    }

    /**
     * A single conversation with its full turn list.
     */
    public function show(Request $request, AssistantConversation $conversation): JsonResponse
    {
        $this->authorize($request, $conversation);

        $conversation->load('messages');

        return response()->json(['conversation' => $conversation->present(withMessages: true)]);
    }

    /**
     * Rename and/or pin a conversation.
     */
    public function update(Request $request, AssistantConversation $conversation): JsonResponse
    {
        $this->authorize($request, $conversation);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'pinned' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('title', $validated)) {
            $conversation->title = trim((string) $validated['title']) ?: $conversation->title;
        }

        if (array_key_exists('pinned', $validated)) {
            $conversation->pinned = (bool) $validated['pinned'];
        }

        $conversation->save();

        return response()->json(['conversation' => $conversation->present()]);
    }

    /**
     * Delete one conversation (and its messages).
     */
    public function destroy(Request $request, AssistantConversation $conversation): JsonResponse
    {
        $this->authorize($request, $conversation);

        $conversation->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Clear all of the user's conversations.
     */
    public function clear(Request $request): JsonResponse
    {
        AssistantConversation::where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true]);
    }

    private function authorize(Request $request, AssistantConversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
    }
}
