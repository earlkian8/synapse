<?php

namespace App\Support\Performance;

use App\Models\PerformanceScore;
use App\Support\Payroll\PayrollCalculator;
use Illuminate\Support\Collection;

/**
 * The single source of truth for the performance-scoring contract: ratings sit on
 * a 1–5 scale, and an evaluation's overall score is the weighted average of its
 * scored criteria. Pure math — given the score lines it returns the overall;
 * controllers reuse it rather than re-deriving the formula (mirrors how
 * {@see PayrollCalculator} owns the payslip totals).
 */
class PerformanceScorer
{
    /** The inclusive bounds of the rating scale. */
    public const RATING_MIN = 1.0;

    public const RATING_MAX = 5.0;

    /**
     * The weighted overall for a set of score lines, on the 1–5 scale.
     *
     * Only *scored* lines (a non-null rating) contribute, so a draft shows a live
     * running score; a line's weight is its share of the average. Returns null
     * when nothing has been scored yet. Falls back to a simple mean if every
     * scored line has a zero weight.
     *
     * @param  iterable<int, PerformanceScore|array{score?: float|int|null, weight?: float|int|null}>  $lines
     */
    public function overall(iterable $lines): ?float
    {
        $scored = (new Collection($lines))
            ->map(fn ($line): array => [
                'score' => $this->value($line, 'score'),
                'weight' => (float) ($this->value($line, 'weight') ?? 0),
            ])
            ->filter(fn (array $line): bool => $line['score'] !== null);

        if ($scored->isEmpty()) {
            return null;
        }

        $totalWeight = $scored->sum('weight');

        // No usable weights — fall back to an unweighted mean.
        if ($totalWeight <= 0) {
            return round((float) $scored->avg('score'), 2);
        }

        $weighted = $scored->sum(fn (array $line): float => (float) $line['score'] * $line['weight']);

        return round($weighted / $totalWeight, 2);
    }

    /**
     * Pull a field from either a model or a plain array line.
     *
     * @param  PerformanceScore|array{score?: float|int|null, weight?: float|int|null}  $line
     */
    private function value(PerformanceScore|array $line, string $key): float|int|null
    {
        $raw = is_array($line) ? ($line[$key] ?? null) : $line->getAttribute($key);

        return $raw === null ? null : (float) $raw;
    }
}
