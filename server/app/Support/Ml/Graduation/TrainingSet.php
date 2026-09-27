<?php

namespace App\Support\Ml\Graduation;

/**
 * The labelled examples a surface's own model would learn from, assembled from this
 * organisation's records, and the volumes the graduation requirements are stated in.
 *
 * One builder produces both, so the checklist on the page and the rows sent to the
 * inference service can never disagree: whatever a requirement counts is exactly
 * what training would use.
 */
final class TrainingSet
{
    /**
     * @param  list<array{group: string, features: array<string, mixed>, outcome: float|int, cycle?: ?string}>  $rows
     * @param  array<string, int|float>  $counts
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $counts,
    ) {}

    public function count(string $key): int|float
    {
        return $this->counts[$key] ?? 0;
    }
}
