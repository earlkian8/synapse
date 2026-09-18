<?php

namespace App\Support\Attendance;

/**
 * The attendance policy that applies to one person on one date — the answer
 * {@see PolicyResolver} gives (ADR 0038). Its settings are what the calculator
 * reads; its name and `source` are what the day modal says ("judged by Shift
 * Work, from the Night Shift schedule").
 */
final readonly class ResolvedPolicy
{
    /** Where a policy came from, most specific first. */
    public const SOURCES = ['assignment', 'schedule', 'department', 'organization', 'fallback'];

    public function __construct(
        public AttendancePolicySettings $settings,
        public string $source = 'fallback',
        public ?int $id = null,
        public ?string $name = null,
    ) {}

    /**
     * The built-in policy anybody with nothing configured is judged by — the
     * pre-policy behaviour, exactly.
     */
    public static function fallback(): self
    {
        return new self(AttendancePolicySettings::fallback());
    }
}
