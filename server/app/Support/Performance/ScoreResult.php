<?php

namespace App\Support\Performance;

/**
 * The outcome of scoring one appraisal: attainment on 0–100 (the canonical
 * figure), the same result projected onto the legacy 1–5 overall the ML pipeline
 * and analytics read, the per-section breakdown behind it, and the band the
 * tenant's own {@see RatingModel} puts it in.
 *
 * Everything here is derived — a result is produced by {@see PerformanceScorer}
 * and never assembled by hand.
 */
class ScoreResult
{
    /**
     * @param  float|null  $percent  Attainment on 0–100; null when nothing is scored.
     * @param  float|null  $normalized  The same result on the canonical 1–5 scale.
     * @param  list<array{key: string, name: string|null, weight: float, percent: float|null, scored: int, total: int}>  $sections
     * @param  array{key: string, label: string, min_percent: float, description: string|null, tone: string}|null  $band
     */
    public function __construct(
        public readonly ?float $percent,
        public readonly ?float $normalized,
        public readonly array $sections = [],
        public readonly ?array $band = null,
        public readonly int $scored = 0,
        public readonly int $total = 0,
    ) {}

    /** Whether every line of the scorecard has been rated. */
    public function isComplete(): bool
    {
        return $this->total > 0 && $this->scored === $this->total;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'percent' => $this->percent,
            'normalized' => $this->normalized,
            'band' => $this->band,
            'sections' => $this->sections,
            'scored' => $this->scored,
            'total' => $this->total,
        ];
    }
}
