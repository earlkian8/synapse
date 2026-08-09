<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\Assistant\Contracts\AssistantModule;
use App\Support\Ai\GeminiClient;
use Illuminate\Support\Carbon;

/**
 * The agentic brain behind the floating Synapse assistant.
 *
 * Aggregates the tools every module is willing to expose to *this* user (module
 * availability first, then per-tool permissions), then runs a bounded Gemini
 * function-calling loop: the model decides which tools to call,
 * this service executes each one (permission-checked, validated, logged) and
 * feeds the results back until the model produces a final reply. The model only
 * *decides* — the modules *enforce*.
 */
class Assistant
{
    /** Hard ceiling on tool-calling round-trips per request. */
    private const MAX_STEPS = 6;

    /**
     * @param  array<int, AssistantModule>  $modules
     */
    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly array $modules,
    ) {}

    public function configured(): bool
    {
        return $this->gemini->configured();
    }

    /**
     * Handle one user turn and return the assistant's reply plus a transcript of
     * what it actually did (for the UI to animate).
     *
     * @param  array<int, array{role?: string, text?: string}>  $history
     * @param  array<int, array{mime: string, data: string}>  $fileParts  Base64 files (e.g. CVs) for multimodal input.
     * @return array{reply: string, steps: array<int, array<string, mixed>>, actions: array<int, array<string, mixed>>}
     */
    public function handle(User $user, string $message, array $history = [], array $fileParts = []): array
    {
        $modules = array_values(array_filter($this->modules, fn (AssistantModule $m): bool => $m->isAvailable($user)));
        $tools = $this->collectTools($modules, $user);

        $contents = $this->buildHistory($history);
        $contents[] = $this->buildUserTurn($message, $fileParts);

        $steps = [];
        $actions = [];
        $reply = '';
        $lastResults = [];

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $response = $this->gemini->generate($contents, $tools, $this->systemInstruction($modules, $user));
            $parts = data_get($response, 'candidates.0.content.parts', []);

            if (! is_array($parts) || $parts === []) {
                break;
            }

            // Echo the model's turn back so a follow-up function-response stays
            // correctly paired with its call.
            $contents[] = ['role' => 'model', 'parts' => $parts];

            $calls = [];
            $texts = [];

            foreach ($parts as $part) {
                if (isset($part['functionCall'])) {
                    $calls[] = $part['functionCall'];
                } elseif (isset($part['text']) && trim((string) $part['text']) !== '') {
                    $texts[] = $part['text'];
                }
            }

            if ($texts !== []) {
                $reply = trim(implode("\n", $texts));
            }

            if ($calls === []) {
                break;
            }

            $responseParts = [];
            $results = [];

            foreach ($calls as $call) {
                $name = (string) ($call['name'] ?? '');
                $args = (array) ($call['args'] ?? []);
                $result = $this->dispatch($user, $modules, $name, $args, $steps, $actions);
                $responseParts[] = [
                    'functionResponse' => ['name' => $name, 'response' => $this->toFunctionResponse($result)],
                ];
                $results[] = [$name, $result];
            }

            // Cost saver: when every tool call this step succeeded — a lookup we
            // can read straight from, or a mutation we just performed — we already
            // hold the answer. Synthesize the reply instead of spending a second
            // request just to have the model word it. This makes the common
            // "look something up / do one thing" turn cost a single request.
            // Only errors (and unknown tools) go back for the model to recover.
            if ($reply === '' && $this->isTerminal($results)) {
                $reply = $this->synthesize($results);

                break;
            }

            $lastResults = $results;
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        if ($reply === '') {
            $reply = $lastResults !== []
                ? $this->synthesize($lastResults)
                : "I wasn't able to complete that. Could you rephrase or give me a few more details?";
        }

        return ['reply' => $reply, 'steps' => $steps, 'actions' => $actions];
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────

    /**
     * Route a function call to the owning module, recording a step and any result
     * cards for the UI, and returning a compact payload for the model.
     *
     * @param  array<int, AssistantModule>  $modules
     * @param  array<string, mixed>  $args
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function dispatch(User $user, array $modules, string $name, array $args, array &$steps, array &$actions): ?ToolResult
    {
        $module = null;

        foreach ($modules as $candidate) {
            if ($candidate->handles($name)) {
                $module = $candidate;

                break;
            }
        }

        if (! $module) {
            $steps[] = ['label' => "Unknown action “{$name}”", 'status' => 'error', 'detail' => 'That tool is not available.'];

            return null;
        }

        $result = $module->run($user, $name, $args);

        $steps[] = ['label' => $result->label, 'status' => $result->status, 'detail' => $result->detail];

        foreach ($result->cards as $card) {
            $actions[] = $card;
        }

        return $result;
    }

    /**
     * Whether every call in a step succeeded (read or write). When so, the result
     * is already in hand and we can answer without another model round-trip. Only
     * errors / unknown tools force a follow-up so the model can recover or explain.
     *
     * @param  array<int, array{0: string, 1: ?ToolResult}>  $results
     */
    private function isTerminal(array $results): bool
    {
        foreach ($results as [, $result]) {
            if ($result === null || $result->failed()) {
                return false;
            }
        }

        return $results !== [];
    }

    /**
     * Compact result the model can read to chain further calls or write its reply.
     *
     * @return array<string, mixed>
     */
    private function toFunctionResponse(?ToolResult $result): array
    {
        if ($result === null) {
            return ['ok' => false, 'error' => 'That tool is not available to you.'];
        }

        $payload = ['ok' => ! $result->failed()];

        if ($result->failed()) {
            $payload['error'] = $result->detail;
        } elseif ($result->detail !== null) {
            $payload['detail'] = $result->detail;
        }

        if ($result->cards !== []) {
            $payload['results'] = array_map(fn (array $card): array => [
                'id' => $card['id'],
                'name' => $card['title'],
                'info' => $card['subtitle'],
                'meta' => $card['meta'],
            ], $result->cards);
        }

        return $payload;
    }

    // ── Prompt building ──────────────────────────────────────────────────────

    /**
     * @param  array<int, AssistantModule>  $modules
     * @return array<int, array<string, mixed>>
     */
    private function collectTools(array $modules, User $user): array
    {
        $tools = [];

        foreach ($modules as $module) {
            foreach ($module->tools($user) as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @param  array<int, AssistantModule>  $modules
     */
    private function systemInstruction(array $modules, User $user): string
    {
        $today = Carbon::today()->toDateString();

        if ($modules === []) {
            return <<<TXT
            You are Synapse Assistant, an agentic HR copilot embedded in the Synapse HR platform.
            The signed-in user has no HR modules available to them. Politely say you can't help with that right now, in one short sentence, and call no tools.
            Today is {$today}.
            TXT;
        }

        $capabilities = collect($modules)
            ->map(fn (AssistantModule $m): string => trim($m->guidance($user)))
            ->implode("\n\n");

        return <<<TXT
        You are Synapse Assistant, an agentic HR copilot embedded in the Synapse HR platform.

        Use the provided tools to take real actions across the HR capabilities listed below, and ONLY those. Never claim to have done something unless a tool actually did it. If a request is outside every capability below (for example payroll, performance, analytics, or general/unrelated questions), reply with one short, polite sentence that it's outside what you can do today — and call no tools.

        How to work:
        - Use exactly one tool call per request whenever possible. Every action resolves a person/record by name or number on its own, so pass the name directly in the action — NEVER call a find_* tool first just to act on something.
        - find_* tools are ONLY for when the user wants to look something up or see a list. Do not chain a find_* into another tool. Never guess ids; if nothing matches, the system says so and you relay it — never fabricate data.
        - Only set fields you were actually given or can read from an attached document. Do not invent emails, salaries, ids or government numbers.
        - Every action is permission-checked server-side; if one is denied, tell the user plainly.
        - Some actions are significant (archiving, hiring, rejecting) — only take them on a clear request.
        - After acting, reply in 1–3 short, warm, accurate sentences describing exactly what you did (or why you couldn't). Reply in the user's language (English or Filipino).

        Today is {$today}.

        CAPABILITIES:
        {$capabilities}
        TXT;
    }

    /**
     * @param  array<int, array{role?: string, text?: string}>  $history
     * @return array<int, array<string, mixed>>
     */
    private function buildHistory(array $history): array
    {
        $contents = [];

        foreach ($history as $turn) {
            $text = trim((string) ($turn['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $contents[] = [
                'role' => ($turn['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $text]],
            ];
        }

        return $contents;
    }

    /**
     * @param  array<int, array{mime: string, data: string}>  $fileParts
     * @return array<string, mixed>
     */
    private function buildUserTurn(string $message, array $fileParts): array
    {
        $fallback = count($fileParts) > 1
            ? 'Please review the attached documents.'
            : 'Please review the attached document.';

        $parts = [['text' => $message !== '' ? $message : $fallback]];

        foreach ($fileParts as $filePart) {
            $parts[] = ['inline_data' => ['mime_type' => $filePart['mime'], 'data' => $filePart['data']]];
        }

        return ['role' => 'user', 'parts' => $parts];
    }

    /**
     * Compose a short, accurate reply from the executed tool results, so we can
     * answer without a second model round-trip. Handles lookups (one or many
     * matches, or none) and mutations.
     *
     * @param  array<int, array{0: string, 1: ?ToolResult}>  $results
     */
    private function synthesize(array $results): string
    {
        $cards = [];
        $emptyFinds = 0;

        foreach ($results as [$name, $result]) {
            if ($result === null) {
                continue;
            }

            if ($result->cards === [] && str_starts_with($name, 'find_')) {
                $emptyFinds++;

                continue;
            }

            foreach ($result->cards as $card) {
                $cards[] = $card;
            }
        }

        $finds = array_values(array_filter($cards, fn (array $c): bool => ($c['kind'] ?? '') === 'find'));
        // Read-outs (summaries, rankings, AI reads) carry their substance in the
        // subtitle + meta rather than in a "we changed this" badge, so they are
        // narrated like a single lookup instead of like an action.
        $reads = array_values(array_filter($cards, fn (array $c): bool => ($c['kind'] ?? '') === 'insight'));
        $mutations = array_values(array_filter($cards, fn (array $c): bool => ! in_array($c['kind'] ?? '', ['find', 'insight'], true)));

        $parts = [];

        foreach ($mutations as $card) {
            $parts[] = $this->describeCard($card);
        }

        foreach ($reads as $card) {
            $parts[] = $this->describeCard($card, withMeta: true);
        }

        if (count($finds) === 1) {
            $parts[] = $this->describeCard($finds[0], withMeta: true);
        } elseif (count($finds) > 1) {
            $names = array_map(fn (array $c): string => (string) $c['title'], $finds);
            $shown = array_slice($names, 0, 5);
            $more = count($names) - count($shown);
            $parts[] = 'Found '.count($names).': '.implode(', ', $shown).($more > 0 ? " and {$more} more" : '').'.';
        }

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return $emptyFinds > 0
            ? "I couldn't find anything matching that."
            : 'Done.';
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function describeCard(array $card, bool $withMeta = false): string
    {
        $line = $withMeta
            ? (string) ($card['title'] ?? '')
            : trim(((string) ($card['badge'] ?? 'Done')).' '.((string) ($card['title'] ?? '')));

        if (filled($card['subtitle'] ?? null)) {
            $line .= ' — '.$card['subtitle'];
        }

        if ($withMeta) {
            $meta = array_values(array_filter($card['meta'] ?? [], fn ($m): bool => filled($m)));

            if ($meta !== []) {
                $line .= ' ('.implode(' · ', $meta).')';
            }
        }

        return rtrim($line, '.').'.';
    }
}
