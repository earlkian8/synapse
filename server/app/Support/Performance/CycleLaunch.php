<?php

namespace App\Support\Performance;

/**
 * What launching a review cycle did: how many appraisals it opened, and who it
 * left out and why — a silent partial launch is worse than none.
 */
final class CycleLaunch
{
    public function __construct(
        public readonly int $opened,
        /** Already had an appraisal in this cycle. */
        public readonly int $skipped,
        /** No framework covers them (or it has nothing to measure). */
        public readonly int $uncovered,
    ) {}

    /**
     * The outcome in words, and whether it is good news.
     *
     * @return array{0: string, 1: 'success'|'warning'|'info'}
     */
    public function message(): array
    {
        if ($this->opened === 0) {
            return $this->uncovered > 0
                ? ["No appraisals opened — {$this->uncovered} ".str('employee')->plural($this->uncovered).' are not covered by a framework.', 'warning']
                : ['Everyone in that scope already has an appraisal for this cycle.', 'info'];
        }

        $message = "Opened {$this->opened} ".str('appraisal')->plural($this->opened).'.';

        if ($this->skipped > 0) {
            $message .= " {$this->skipped} already had one.";
        }

        if ($this->uncovered > 0) {
            $message .= " {$this->uncovered} ".str('employee')->plural($this->uncovered).' had no framework.';
        }

        return [$message, 'success'];
    }
}
