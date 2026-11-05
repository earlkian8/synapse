<?php

namespace App\Support\Ml;

/**
 * The part of an inference result that says *why* (ADR 0098), as it is stored next to
 * the score. One place, so the three assessors keep the same shape:
 *
 *  - per score (`explanation`): `baseline` — the score of a typical record, which the
 *    score's factors add up from (attrition, promotion); `scenarios` — the score the
 *    model gives the same record with a change HR can make: a promotion, regular
 *    employment, a raise into the next pay band (attrition, ADR 0099); `indicators` —
 *    the behavioural indicators of emotional states the record reads on (withdrawal,
 *    career stagnation, workload strain, effort–reward imbalance), each with its level,
 *    the points the score gives it and the survey's leave rate there (attrition,
 *    ADR 0101); `outlook` —
 *    readiness after the next appraisal, by the rating it gives (promotion);
 *    `chances` — the chance of every band (performance);
 *  - per run (`reference`): what every score in it is read against — the typical
 *    record, where each tier starts, the model's rating curve (performance) and the
 *    leave rates of the data the model learned from (attrition).
 *
 * A key the service did not send is left out; an old service sends none, and the
 * score is stored without an explanation rather than with an invented one.
 */
final class PredictionExplanation
{
    /** The explanation keys each model's results carry. */
    private const KEYS = [
        'attrition' => ['baseline', 'scenarios', 'indicators'],
        'promotion' => ['baseline', 'outlook'],
        'performance' => ['chances'],
    ];

    /**
     * The explanation to store with one result, or null when it carries none.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    public static function forScore(string $model, array $result): ?array
    {
        $explanation = [];

        foreach (self::KEYS[$model] ?? [] as $key) {
            if (isset($result[$key]) && (is_array($result[$key]) || is_numeric($result[$key]))) {
                $explanation[$key] = $result[$key];
            }
        }

        return $explanation ?: null;
    }

    /**
     * What the run's scores are read against, from the service's response.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    public static function forRun(array $response): ?array
    {
        $reference = $response['reference'] ?? null;

        return is_array($reference) && $reference !== [] ? $reference : null;
    }
}
