<?php

namespace App\Services\Assistant\Contracts;

use App\Models\User;
use App\Services\Assistant\ToolResult;

/**
 * A capability area the assistant can act on (employees, leave, onboarding,
 * recruitment …). Each module advertises Gemini function declarations via
 * {@see tools()}, contributes catalog context via {@see guidance()}, and
 * executes a call deterministically in {@see run()} — the model only *decides*,
 * the module *enforces* (permissions, validation, logging).
 */
interface AssistantModule
{
    /**
     * Stable key for this module (e.g. "employees"). Also the card `module` tag.
     */
    public function key(): string;

    /**
     * Whether this user may use the module at all (its base "view" permission).
     * Modules the user cannot use are omitted from the prompt and tool set.
     */
    public function isAvailable(User $user): bool;

    /**
     * Whether this module owns the given tool name.
     */
    public function handles(string $tool): bool;

    /**
     * The Gemini function declarations (OpenAPI subset) this module exposes to
     * *this* user — a module advertises only the tools their permissions allow,
     * so the model is never offered an action that would just be denied.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tools(User $user): array;

    /**
     * A short system-prompt fragment: what this module can do *for this user*,
     * plus any live catalogs / allowed values the model should prefer.
     */
    public function guidance(User $user): string;

    /**
     * Execute one tool call for this module.
     *
     * @param  array<string, mixed>  $args
     */
    public function run(User $user, string $tool, array $args): ToolResult;
}
