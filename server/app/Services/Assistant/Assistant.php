<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\Assistant\Contracts\AssistantModule;
use App\Support\Ai\GeminiClient;
use Illuminate\Support\Carbon;

/**
 * The agentic brain behind the floating Nexo assistant.
 *
 * Aggregates the tools of every module the user is permitted to use, then runs a
 * bounded Gemini function-calling loop: the model decides which tools to call,
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
        $tools = $this->collectTools($modules);

        $contents = $this->buildHistory($history);
        $contents[] = $this->buildUserTurn($message, $fileParts);

        $steps = [];
        $actions = [];
        $reply = '';

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $response = $this->gemini->generate($contents, $tools, $this->systemInstruction($modules));
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

            // Cost saver: when every call this step was a successful mutation —
            // nothing to reason over, nothing to recover from — we already know
            // the outcome, so synthesize the confirmation rather than spend
            // another request just to have the model word it. Lookups and errors
            // still go back to the model so it can chain or fix.
            if ($reply === '' && $actions !== [] && $this->isTerminal($results)) {
                $reply = $this->summariseActions($actions);

                break;
            }

            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        if ($reply === '') {
            $reply = $actions !== []
                ? $this->summariseActions($actions)
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
     * Whether every call in a step was a successful, terminal mutation (no
     * lookup the model must reason over, no error to recover from) — meaning we
     * can answer without another round-trip.
     *
     * @param  array<int, array{0: string, 1: ?ToolResult}>  $results
     */
    private function isTerminal(array $results): bool
    {
        foreach ($results as [$name, $result]) {
            if ($result === null || $result->failed() || $result->cards === [] || str_starts_with($name, 'find_')) {
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
    private function collectTools(array $modules): array
    {
        $tools = [];

        foreach ($modules as $module) {
            foreach ($module->tools() as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @param  array<int, AssistantModule>  $modules
     */
    private function systemInstruction(array $modules): string
    {
        $today = Carbon::today()->toDateString();

        if ($modules === []) {
            return <<<TXT
            You are Nexo Assistant, an agentic HR copilot embedded in the Nexo HR platform.
            The signed-in user has no HR modules available to them. Politely say you can't help with that right now, in one short sentence, and call no tools.
            Today is {$today}.
            TXT;
        }

        $capabilities = collect($modules)
            ->map(fn (AssistantModule $m): string => trim($m->guidance()))
            ->implode("\n\n");

        return <<<TXT
        You are Nexo Assistant, an agentic HR copilot embedded in the Nexo HR platform.

        Use the provided tools to take real actions across the HR capabilities listed below, and ONLY those. Never claim to have done something unless a tool actually did it. If a request is outside every capability below (for example payroll, attendance, performance, analytics, or general/unrelated questions), reply with one short, polite sentence that it's outside what you can do today — and call no tools.

        How to work:
        - To act on an existing record, pass the person/record by name or number directly in the action — the system resolves it for you. Do NOT call a find_* tool just to act on something; only use find_* when the user actually wants a list or you genuinely must choose among results. Never guess ids; if nothing matches, the system tells you and you relay that — never fabricate data.
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
     * Compose a short, accurate confirmation from the executed actions (used when
     * we skip the model's wording round-trip).
     *
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function summariseActions(array $actions): string
    {
        return collect($actions)
            ->map(function (array $a): string {
                $line = trim(((string) ($a['badge'] ?? 'Done')).' '.((string) ($a['title'] ?? '')));

                if (filled($a['subtitle'] ?? null)) {
                    $line .= ' — '.$a['subtitle'];
                }

                return rtrim($line, '.').'.';
            })
            ->implode(' ');
    }
}
