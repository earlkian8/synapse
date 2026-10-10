<?php

namespace App\Services\Assistant;

/**
 * What the orchestrator knows about the turn in progress that decides how a
 * tool call is treated: whether the user asked or instructed, whether an
 * untrusted document came with it, which mode the user chose, how much of the
 * per-turn budget is spent, and the plan any held writes are queued in.
 */
final class TurnState
{
    /** Ask before every change. */
    public const MANUAL = 'manual';

    /** The rules of ADR 0049: consequential changes, questions and documents wait. */
    public const BALANCED = 'balanced';

    /** Only consequential changes and changes proposed on a question wait. */
    public const AUTO = 'auto';

    public const MODES = [self::MANUAL, self::BALANCED, self::AUTO];

    /** Tool calls attempted so far this turn. */
    public int $calls = 0;

    /** Writes actually run so far this turn (held ones are counted in the plan). */
    public int $writes = 0;

    /** The token of the plan held writes are queued in, once one is. */
    public ?string $planToken = null;

    /** Where the plan's confirm card sits in the turn's cards. */
    public ?int $planCard = null;

    /** @var list<array{title: string, detail: string|null}> The plan's steps, as the card shows them. */
    public array $plan = [];

    /** @var array<string, ToolResult|null> Results of the calls made so far, by call signature. */
    public array $seen = [];

    public readonly string $mode;

    public function __construct(
        /** The user asked a question rather than gave an instruction. */
        public readonly bool $asking,
        /** A document came with the turn — content nobody here wrote. */
        public readonly bool $attachments,
        /** Where a held call's outcome will be written back. */
        public readonly ?int $conversationId = null,
        string $mode = self::BALANCED,
    ) {
        $this->mode = in_array($mode, self::MODES, true) ? $mode : self::BALANCED;
    }

    public function planned(): int
    {
        return count($this->plan);
    }
}
