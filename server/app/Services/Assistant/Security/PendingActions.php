<?php

namespace App\Services\Assistant\Security;

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Actions the assistant proposed but may not take until the user says so.
 *
 * A held action is the exact call the model asked for — tool and cleaned
 * arguments — parked under an unguessable token for a few minutes. Pressing
 * Confirm in the chat runs *that* call, and nothing the model says afterwards can
 * change it; pressing Cancel, or waiting, discards it.
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
     * Park a call and return its token.
     *
     * @param  array<string, mixed>  $args
     */
    public function hold(User $user, ?int $conversationId, string $tool, array $args, string $summary): string
    {
        $token = Str::random(48);

        Cache::put($this->key($token), [
            'user_id' => $user->id,
            'organization_id' => app(Tenancy::class)->id(),
            'conversation_id' => $conversationId,
            'tool' => $tool,
            'args' => $args,
            'summary' => $summary,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * Claim a held call for this user in this workspace, exactly once. Null when
     * the token is unknown, expired, already used, or belongs to somebody else —
     * deliberately indistinguishable, so a token cannot be probed.
     *
     * @return array{tool: string, args: array<string, mixed>, summary: string, conversation_id: int|null}|null
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

        return [
            'tool' => (string) $payload['tool'],
            'args' => (array) $payload['args'],
            'summary' => (string) $payload['summary'],
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
