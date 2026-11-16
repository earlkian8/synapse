<?php

namespace App\Support\Discipline;

use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * An employee's conduct record (ADR 0105): where they stand today, and the
 * upheld cases that still count against them — each until its offense's
 * reckoning period has run since the decision.
 *
 * Where they stand, the most serious first:
 *
 *  - `dismissed` — an upheld case ended in dismissal;
 *  - `suspended` — serving a suspension today (`preventive` while a case is
 *    heard, which is not a penalty);
 *  - `final_warning` / `warned` — a warning that still counts;
 *  - `under_review` — a case served on them is open;
 *  - `good` — none of these.
 */
final class ConductRecord
{
    /** The reckoning period of a case charged under no offense. */
    private const DEFAULT_RECKONING_MONTHS = 12;

    public const STANDINGS = [
        'dismissed' => 'Dismissed',
        'suspended' => 'Serving a suspension',
        'preventive' => 'On preventive suspension',
        'final_warning' => 'On a final warning',
        'warned' => 'On a warning',
        'under_review' => 'Case being heard',
        'good' => 'In good standing',
    ];

    /**
     * @return array{standing: array{key: string, label: string, until: ?string}, active: list<array<string, mixed>>, open: int, upheld: int, cleared: int}
     */
    public static function for(Employee $employee, ?string $today = null): array
    {
        $today ??= OrganizationClock::today();

        $cases = DisciplinaryCase::query()
            ->with('offense:id,code,title,reckoning_months')
            ->where('employee_id', $employee->id)
            ->served()
            ->get();

        $active = $cases
            ->filter(fn (DisciplinaryCase $case): bool => $case->isUpheld() && self::stillCounts($case, $today))
            ->sortByDesc('decided_at')
            ->values();

        return [
            'standing' => self::standing($cases, $active, $today),
            'active' => $active->map(fn (DisciplinaryCase $case): array => [
                'hashid' => $case->hashid,
                'reference' => $case->reference,
                'offense' => $case->offense?->title,
                'penalty' => $case->penalty,
                'penalty_label' => DisciplinaryCase::PENALTIES[$case->penalty] ?? null,
                'decided_at' => $case->decided_at?->toIso8601String(),
                'counts_until' => self::countsUntil($case)->toDateString(),
            ])->all(),
            'open' => $cases->filter(fn (DisciplinaryCase $case): bool => $case->isOpen())->count(),
            'upheld' => $cases->filter(fn (DisciplinaryCase $case): bool => $case->isUpheld())->count(),
            'cleared' => $cases->where('finding', 'cleared')->count(),
        ];
    }

    public static function countsUntil(DisciplinaryCase $case): CarbonImmutable
    {
        $months = $case->offense?->reckoning_months ?? self::DEFAULT_RECKONING_MONTHS;

        return CarbonImmutable::parse(OrganizationClock::localDate($case->decided_at ?? now()))->addMonths($months);
    }

    private static function stillCounts(DisciplinaryCase $case, string $today): bool
    {
        return self::countsUntil($case)->toDateString() > $today;
    }

    /**
     * @param  Collection<int, DisciplinaryCase>  $cases
     * @param  Collection<int, DisciplinaryCase>  $active
     * @return array{key: string, label: string, until: ?string}
     */
    private static function standing($cases, $active, string $today): array
    {
        $make = fn (string $key, ?string $until = null): array => ['key' => $key, 'label' => self::STANDINGS[$key], 'until' => $until];

        if ($cases->contains(fn (DisciplinaryCase $case): bool => $case->isUpheld() && $case->penalty === 'dismissal')) {
            return $make('dismissed');
        }

        $serving = $cases->first(fn (DisciplinaryCase $case): bool => $case->isUpheld()
            && $case->penalty === 'suspension'
            && $case->penalty_from?->toDateString() <= $today
            && $case->penalty_to?->toDateString() >= $today);

        if ($serving !== null) {
            return $make('suspended', $serving->penalty_to?->toDateString());
        }

        $preventive = $cases->first(fn (DisciplinaryCase $case): bool => $case->isOpen()
            && $case->preventive_from?->toDateString() <= $today
            && $case->preventive_to?->toDateString() >= $today);

        if ($preventive !== null) {
            return $make('preventive', $preventive->preventive_to?->toDateString());
        }

        $final = $active->first(fn (DisciplinaryCase $case): bool => $case->penalty === 'final_warning');

        if ($final !== null) {
            return $make('final_warning', self::countsUntil($final)->toDateString());
        }

        if ($active->isNotEmpty()) {
            return $make('warned', self::countsUntil($active->first())->toDateString());
        }

        if ($cases->contains(fn (DisciplinaryCase $case): bool => $case->isOpen())) {
            return $make('under_review');
        }

        return $make('good');
    }
}
