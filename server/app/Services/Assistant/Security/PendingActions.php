<?php

namespace App\Services\Assistant\Security;

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Actions the assistant proposed but may not take until the user says so.
 *
 * A held action is a **plan**: the exact calls the model asked for — tools and
 * cleaned arguments, in order — parked under an unguessable token for a few
 * minutes (ADR 0068 §3; a plan of one is the single held call of ADR 0049).
 * Pressing Confirm in the chat runs *those* calls, and nothing the model says
 * afterwards can change them; pressing Cancel, or waiting, discards them.
 *
 * A token is a capability, so it is bound as tightly as it can be:
 *
 * - **to the person** it was issued to — nobody else's session can spend it;
 * - **to the workspace** it was issued in — switching organisation voids it, so
 *   a call can never run under a tenant it was not proposed in;
 * - **to the conversation** it was proposed in;
 * - **to one use** — a double click, a replayed request or a second tab cannot
 *   run it twice ({@see take()} claims it atomically before reading it);
 * - **to a short life** ({@see TTL_MINUTES}).
 *
 * Running it still goes through the module, which re-checks the permission at
 * that moment — confirming is consent, not a grant.
 */
final class PendingActions
{
    /** How long a held action waits for its answer. */
    public const TTL_MINUTES = 15;

    /**
     * Park a plan — one or more calls, to run in order — and return its token.
     *
     * @param  list<array{tool: string, args: array<string, mixed>, title: string}>  $steps
     */
    public function hold(User $user, ?int $conversationId, array $steps): string
    {
        $token = Str::random(48);

        Cache::put($this->key($token), [
            'user_id' => $user->id,
            'organization_id' => app(Tenancy::class)->id(),
            'conversation_id' => $conversationId,
            'steps' => array_values($steps),
        ], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * Queue one more call behind a plan that is still waiting (ADR 0068 §3).
     * A plan that has expired or was already answered gains nothing.
     *
     * @param  array{tool: string, args: array<string, mixed>, title: string}  $step
     */
    public function extend(string $token, array $step): bool
    {
        $payload = Cache::get($this->key($token));

        if (! is_array($payload) || Cache::has($this->key($token).':claimed')) {
            return false;
        }

        $payload['steps'][] = $step;

        Cache::put($this->key($token), $payload, now()->addMinutes(self::TTL_MINUTES));

        return true;
    }

    /**
     * Claim a held plan for this user in this workspace, exactly once. Null when
     * the token is unknown, expired, already used, or belongs to somebody else —
     * deliberately indistinguishable, so a token cannot be probed.
     *
     * @return array{steps: list<array{tool: string, args: array<string, mixed>, title: string}>, conversation_id: int|null}|null
     */
    public function take(User $user, string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }

        $payload = Cache::get($this->key($token));

        if (! is_array($payload) || ! $this->ownedBy($payload, $user)) {
            return null;
        }

        // Claim before acting: `add` only succeeds for the first caller, so two
        // requests racing on the same token cannot both get past this line.
        if (! Cache::add($this->key($token).':claimed', true, now()->addMinutes(self::TTL_MINUTES))) {
            return null;
        }

        Cache::forget($this->key($token));

        // A single call held before plans existed (ADR 0049) is a one-step plan.
        $steps = isset($payload['steps'])
            ? (array) $payload['steps']
            : [['tool' => (string) ($payload['tool'] ?? ''), 'args' => (array) ($payload['args'] ?? []), 'title' => (string) ($payload['summary'] ?? '')]];

        return [
            'steps' => array_values(array_map(fn (array $step): array => [
                'tool' => (string) ($step['tool'] ?? ''),
                'args' => (array) ($step['args'] ?? []),
                'title' => (string) ($step['title'] ?? ''),
            ], $steps)),
            'conversation_id' => $payload['conversation_id'] !== null ? (int) $payload['conversation_id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ownedBy(array $payload, User $user): bool
    {
        $tenant = app(Tenancy::class)->id();

        return (int) ($payload['user_id'] ?? 0) === $user->id
            && $tenant !== null
            && (int) ($payload['organization_id'] ?? 0) === $tenant;
    }

    private function key(string $token): string
    {
        // Hashed, so the cache (a database table, in most installs) never holds
        // a usable token.
        return 'assistant:pending:'.hash('sha256', $token);
    }
}
