<?php

namespace App\Support\Performance;

use App\Models\PerformanceScore;
use Illuminate\Support\Collection;

/**
 * The single source of truth for the performance-scoring contract. Each score
 * line is rated on its criterion's own scale (a 1–5 or 1–10 points scale, a
 * 0–100 percentage, or a set of descriptive levels); an evaluation's overall is
 * the weighted average of each line's position on its scale, **normalised back
 * onto the canonical 1–5 scale** so the whole app — historical evaluations, the
 * ML forecast pipeline, the analytics — keeps one stable meaning of "overall".
 *
 * Pure math — given the score lines it returns the overall; controllers reuse it
 * rather than re-deriving the formula. A line that omits scale bounds defaults to
 * the legacy 1–5 scale, so pre-existing evaluations are scored identically.
 */
class PerformanceScorer
{
    /** The inclusive bounds of the canonical overall scale. */
    public const RATING_MIN = 1.0;

    public const RATING_MAX = 5.0;

    /**
     * The weighted overall for a set of score lines, on the canonical 1–5 scale.
     *
     * Only *scored* lines (a non-null rating) contribute, so a draft shows a live
     * running score; a line's weight is its share of the average. Each line's raw
     * score is first normalised to a 0–1 fraction of its own scale, so mixed
     * scales combine coherently. Returns null when nothing has been scored yet;
     * falls back to an unweighted mean if every scored line has a zero weight.
     *
     * @param  iterable<int, PerformanceScore|array{score?: float|int|null, weight?: float|int|null, scale_min?: float|int|null, scale_max?: float|int|null}>  $lines
     */
    public function overall(iterable $lines): ?float
    {
        $scored = (new Collection($lines))
            ->map(fn ($line): array => [
                'fraction' => $this->fraction(
                    $this->value($line, 'score'),
                    $this->value($line, 'scale_min'),
                    $this->value($line, 'scale_max'),
                ),
                'weight' => (float) ($this->value($line, 'weight') ?? 0),
            ])
            ->filter(fn (array $line): bool => $line['fraction'] !== null);

        if ($scored->isEmpty()) {
            return null;
        }

        $totalWeight = $scored->sum('weight');

        $avgFraction = $totalWeight > 0
            ? $scored->sum(fn (array $line): float => (float) $line['fraction'] * $line['weight']) / $totalWeight
            : (float) $scored->avg('fraction');

        // Project the 0–1 fraction back onto 1–5 (an affine map — so a pure 1–5
        // points evaluation yields exactly the old weighted average of ratings).
        return round(self::RATING_MIN + $avgFraction * (self::RATING_MAX - self::RATING_MIN), 2);
    }

    /**
     * A raw score's position on its scale as a 0–1 fraction, clamped. Null when
     * unscored. A missing/degenerate scale defaults to the legacy 1–5 bounds.
     */
    private function fraction(?float $score, ?float $min, ?float $max): ?float
    {
        if ($score === null) {
            return null;
        }

        $min ??= self::RATING_MIN;
        $max ??= self::RATING_MAX;
        $span = $max - $min;

        if ($span <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, ($score - $min) / $span));
    }

    /**
     * Pull a numeric field from either a model or a plain array line.
     *
     * @param  PerformanceScore|array<string, mixed>  $line
     */
    private function value(PerformanceScore|array $line, string $key): float|int|null
    {
        $raw = is_array($line) ? ($line[$key] ?? null) : $line->getAttribute($key);

        return $raw === null ? null : (float) $raw;
    }
}
