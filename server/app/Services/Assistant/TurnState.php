<?php

namespace App\Services\Assistant;

/**
 * What the orchestrator knows about the turn in progress that decides how a
 * tool call is treated: whether the user asked or instructed, whether an
 * untrusted document came with it, and how much of the per-turn budget is spent.
 */
final class TurnState
{
    /** Tool calls attempted so far this turn. */
    public int $calls = 0;

    /** Writes actually run so far this turn (held ones do not count). */
    public int $writes = 0;

    public function __construct(
        /** The user asked a question rather than gave an instruction. */
        public readonly bool $asking,
        /** A document came with the turn — content nobody here wrote. */
        public readonly bool $attachments,
        /** Where a held call's outcome will be written back. */
        public readonly ?int $conversationId = null,
    ) {}
}
