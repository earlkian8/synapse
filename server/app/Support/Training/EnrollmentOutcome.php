<?php

namespace App\Support\Training;

/**
 * What enrolling people into a program did: who got a seat, who was not
 * eligible, and who was left out because the program filled up — a silent
 * partial enroll is worse than none.
 */
final class EnrollmentOutcome
{
    /**
     * @param  list<int>  $enrolledIds
     */
    public function __construct(
        /** The employees who were given a seat. */
        public readonly array $enrolledIds,
        /** Already enrolled, inactive, or not in this workspace. */
        public readonly int $ineligible,
        /** Eligible, but there was no seat left. */
        public readonly int $leftOut,
    ) {}

    public function enrolled(): int
    {
        return count($this->enrolledIds);
    }

    /**
     * The outcome in words, and whether it is good news.
     *
     * @return array{0: string, 1: 'success'|'warning'}
     */
    public function message(): array
    {
        $enrolled = $this->enrolled();

        if ($enrolled === 0) {
            return $this->leftOut > 0
                ? ['This program is full.', 'warning']
                : ['No new employees to enroll — they are already enrolled or inactive.', 'warning'];
        }

        $message = $enrolled === 1 ? 'Employee enrolled.' : "{$enrolled} employees enrolled.";

        if ($this->leftOut > 0) {
            return ["{$message} {$this->leftOut} left out — the program is now full.", 'warning'];
        }

        return [$message, 'success'];
    }
}
