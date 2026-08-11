<?php

namespace App\Support\Performance;

use App\Models\PerformanceScore;
use Illuminate\Support\Collection;

/**
 * The single source of truth for the performance-scoring contract.
 *
 * An appraisal is scored in two levels, because that is how appraisal frameworks
 * are actually written: each line is rated on **its own criterion's scale** (a
 * 1–5 or 1–4 numeric scale, a 0–100 goal attainment, a set of named competency
 * levels) and weighted **within its section**; the sections are then weighted
 * against each other ("Goals 60%, Competencies 30%, Values 10%"). The result is
 * attainment on **0–100**, which the tenant's own {@see RatingModel} turns into
 * the words that company reports in.
 *
 * The 1–5 `normalized` overall is kept as an affine projection of the same
 * figure so the ML forecast pipeline, attrition features and analytics keep one
 * stable meaning of "overall" across every framework.
 *
 * Pure math — given the score lines it returns the result; controllers reuse it
 * rather than re-deriving the formula. A card with no sections is one section, so
 * a flat weighted scorecard scores exactly as it always did.
 */
class PerformanceScorer
{
    /** The inclusive bounds of the canonical (projected) overall scale. */
    public const RATING_MIN = 1.0;

    public const RATING_MAX = 5.0;

    /**
     * The full result for a set of score lines, against a rating model.
     *
     * Only *scored* lines contribute, so a draft carries a live running result.
     * A section's attainment is the weighted mean of its scored lines' positions
     * on their own scales; the overall is the weighted mean of the sections. A
     * section that carries no weight of its own falls back to the weight of the
     * lines inside it, which makes an unsectioned card behave as a flat weighted
     * average.
     *
     * @param  iterable<int, PerformanceScore|array<string, mixed>>  $lines
     * @param  iterable<int, array<string, mixed>>  $bands
     */
    public function score(iterable $lines, iterable $bands = []): ScoreResult
    {
        $lines = new Collection($lines);
        $total = $lines->count();

        $grouped = $lines
            ->map(fn ($line): array => [
                'section_key' => (string) ($this->raw($line, 'section_key') ?? 'overall'),
                'section_name' => $this->raw($line, 'section_name'),
                'section_weight' => (float) ($this->number($line, 'section_weight') ?? 0),
                'weight' => (float) ($this->number($line, 'weight') ?? 0),
                'fraction' => RatingScales::fraction(
                    $this->number($line, 'score'),
                    $this->number($line, 'scale_min') ?? self::RATING_MIN,
                    $this->number($line, 'scale_max') ?? self::RATING_MAX,
                ),
            ])
            ->groupBy('section_key');

        $sections = $grouped
            ->map(fn (Collection $group, string $key): array => $this->section($key, $group))
            ->values()
            ->all();

        $scored = array_sum(array_column($sections, 'scored'));

        // Only sections with something scored in them can carry the overall.
        $contributing = array_values(array_filter($sections, fn (array $section): bool => $section['percent'] !== null));

        if ($contributing === []) {
            return new ScoreResult(null, null, $sections, null, 0, $total);
        }

        $weight = array_sum(array_column($contributing, 'weight'));

        $percent = $weight > 0
            ? array_sum(array_map(fn (array $s): float => (float) $s['percent'] * $s['weight'], $contributing)) / $weight
            : array_sum(array_column($contributing, 'percent')) / count($contributing);

        $percent = round($percent, 2);

        return new ScoreResult(
            percent: $percent,
            // An affine map onto 1–5, so a pure 1–5 card yields exactly the old
            // weighted average of its ratings.
            normalized: round(self::RATING_MIN + ($percent / 100) * (self::RATING_MAX - self::RATING_MIN), 2),
            sections: $sections,
            band: RatingModel::bandFor($percent, $bands),
            scored: $scored,
            total: $total,
        );
    }

    /**
     * The weighted overall on the canonical 1–5 scale. Retained as the narrow
     * contract everything outside Performance reads (ML features, analytics,
     * the awards nominator); {@see score()} is the full picture.
     *
     * @param  iterable<int, PerformanceScore|array<string, mixed>>  $lines
     */
    public function overall(iterable $lines): ?float
    {
        return $this->score($lines)->normalized;
    }

    /**
     * One section's attainment: the weighted mean of its scored lines' positions
     * on their own scales, as 0–100. Its weight is its own if it declares one,
     * otherwise the weight of the lines it holds — which is what makes an
     * unsectioned card score as a flat weighted average.
     *
     * @param  Collection<int, array<string, mixed>>  $group
     * @return array{key: string, name: string|null, weight: float, percent: float|null, scored: int, total: int}
     */
    private function section(string $key, Collection $group): array
    {
        $scored = $group->filter(fn (array $line): bool => $line['fraction'] !== null);
        $declared = (float) $group->max('section_weight');

        $weight = $declared > 0 ? $declared : (float) $scored->sum('weight');
        $lineWeight = (float) $scored->sum('weight');

        $fraction = match (true) {
            $scored->isEmpty() => null,
            $lineWeight > 0 => $scored->sum(fn (array $line): float => (float) $line['fraction'] * $line['weight']) / $lineWeight,
            default => (float) $scored->avg('fraction'),
        };

        return [
            'key' => $key,
            'name' => $group->first()['section_name'] ?? null,
            'weight' => round($weight, 2),
            'percent' => $fraction === null ? null : round($fraction * 100, 2),
            'scored' => $scored->count(),
            'total' => $group->count(),
        ];
    }

    /**
     * Pull a field from either a model or a plain array line.
     *
     * @param  PerformanceScore|array<string, mixed>  $line
     */
    private function raw(PerformanceScore|array $line, string $key): mixed
    {
        return is_array($line) ? ($line[$key] ?? null) : $line->getAttribute($key);
    }

    /**
     * Pull a numeric field, keeping "absent" distinct from "zero".
     *
     * @param  PerformanceScore|array<string, mixed>  $line
     */
    private function number(PerformanceScore|array $line, string $key): ?float
    {
        $raw = $this->raw($line, $key);

        return $raw === null ? null : (float) $raw;
    }
}
