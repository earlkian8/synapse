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
     * The Gemini function declarations (OpenAPI subset) this module exposes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tools(): array;

    /**
     * A short system-prompt fragment: what this module can do, plus any live
     * catalogs / allowed values the model should prefer.
     */
    public function guidance(): string;

    /**
     * Execute one tool call for this module.
     *
     * @param  array<string, mixed>  $args
     */
    public function run(User $user, string $tool, array $args): ToolResult;
}
