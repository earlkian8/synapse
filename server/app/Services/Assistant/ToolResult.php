<?php

namespace App\Services\Assistant;

/**
 * The outcome of executing one tool call: a timeline *step* (what happened) and
 * zero or more *cards* (rich record results the chat UI animates in). Every tool
 * returns one of these so the orchestrator can build a uniform transcript across
 * all modules.
 *
 * A third outcome sits between done and failed: **held** — the call was not run,
 * because it waits for the user to confirm it in the chat (ADR 0049). Only the
 * orchestrator produces it; a module never has to know.
 */
final class ToolResult
{
    /**
     * @param  'done'|'error'|'held'  $status
     * @param  array<int, array<string, mixed>>  $cards
     */
    public function __construct(
        public readonly string $label,
        public readonly string $status,
        public readonly ?string $detail = null,
        public readonly array $cards = [],
    ) {}

    /**
     * A successful action, optionally carrying a single result card.
     *
     * @param  array<string, mixed>|null  $card
     */
    public static function ok(string $label, ?string $detail = null, ?array $card = null): self
    {
        return new self($label, 'done', $detail, $card !== null ? [$card] : []);
    }

    /**
     * A successful lookup carrying any number of result cards.
     *
     * @param  array<int, array<string, mixed>>  $cards
     */
    public static function found(string $label, ?string $detail, array $cards): self
    {
        return new self($label, 'done', $detail, $cards);
    }

    /**
     * A failed action — recorded as an error step, no card.
     */
    public static function error(string $label, string $detail): self
    {
        return new self($label, 'error', $detail);
    }

    /**
     * A call parked until the user confirms it, carrying the card that asks.
     *
     * @param  array<string, mixed>  $card
     */
    public static function held(string $label, string $detail, array $card): self
    {
        return new self($label, 'held', $detail, [$card]);
    }

    public function failed(): bool
    {
        return $this->status === 'error';
    }

    public function isHeld(): bool
    {
        return $this->status === 'held';
    }
}
