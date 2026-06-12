<?php

namespace App\Services\Assistant;

/**
 * The outcome of executing one tool call: a timeline *step* (what happened) and
 * zero or more *cards* (rich record results the chat UI animates in). Every tool
 * returns one of these so the orchestrator can build a uniform transcript across
 * all modules.
 */
final class ToolResult
{
    /**
     * @param  'done'|'error'  $status
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

    public function failed(): bool
    {
        return $this->status === 'error';
    }
}
