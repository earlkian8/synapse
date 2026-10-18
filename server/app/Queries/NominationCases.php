<?php

namespace App\Queries;

use App\Models\AwardNomination;
use App\Support\Recognition\NominationWorkflow;
use Illuminate\Support\Collection;

/**
 * Nominations as HR reviews them: one **case** per person and award, however
 * many colleagues put that person forward (ADR 0071, revised 2026-10-18). Two
 * nominations of Ana for Employee of the Month are one decision — one award,
 * one set of points — with two nominators and two reasons, so they are read,
 * counted and decided together ({@see NominationWorkflow}).
 *
 * The queue, its count on every Awards page, and the assistant all read cases
 * from here.
 */
final class NominationCases
{
    /** What a case needs to be shown. */
    public const WITH = [
        'employee.position:id,title',
        'employee.department:id,name',
        'awardType',
        'award',
        'nominator:id,first_name,last_name',
        'reviewer:id,first_name,last_name',
    ];

    /**
     * The cases waiting for review, the one waiting longest first. Each is
     * its nominations, oldest first.
     *
     * @return Collection<int, Collection<int, AwardNomination>>
     */
    public static function pending(int $limit = 200): Collection
    {
        return AwardNomination::query()->pending()->with(self::WITH)->oldest('id')->limit($limit)->get()
            ->groupBy(fn (AwardNomination $n): string => self::key($n))
            ->values();
    }

    /**
     * Cases decided since a day, latest first. Nominations decided together
     * share their review — the same reviewer, at the same moment — so they come
     * back as one case; a withdrawn one stands alone.
     *
     * @return Collection<int, Collection<int, AwardNomination>>
     */
    public static function decided(int $days = 90, int $limit = 200): Collection
    {
        return AwardNomination::query()
            ->where('status', '!=', 'pending')
            ->where('updated_at', '>=', now()->subDays($days))
            ->with(self::WITH)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->groupBy(fn (AwardNomination $n): string => $n->status === 'withdrawn'
                ? 'withdrawn|'.$n->id
                : implode('|', [self::key($n), $n->status, $n->reviewed_by, $n->reviewed_at?->toIso8601String()]))
            ->map(fn (Collection $group): Collection => $group->sortBy('id')->values())
            ->values();
    }

    /**
     * How many cases wait for review — the number the Awards pages show.
     */
    public static function pendingCount(): int
    {
        return AwardNomination::query()->pending()
            ->select(['employee_id', 'award_type_id'])
            ->distinct()
            ->get()
            ->count();
    }

    /**
     * The nominators' names, in the order they nominated: "Ana Cruz",
     * "Ana Cruz and Ben Reyes", "Ana Cruz, Ben Reyes and 2 others".
     *
     * @param  Collection<int, AwardNomination>  $case
     */
    public static function nominators(Collection $case): string
    {
        $names = $case->map(fn (AwardNomination $n): string => $n->nominator
            ? trim("{$n->nominator->first_name} {$n->nominator->last_name}")
            : 'a former colleague')->values();

        return match (true) {
            $names->count() <= 2 => $names->implode(' and '),
            $names->count() === 3 => "{$names[0]}, {$names[1]} and {$names[2]}",
            default => "{$names[0]}, {$names[1]} and ".($names->count() - 2).' others',
        };
    }

    private static function key(AwardNomination $n): string
    {
        return $n->employee_id.'|'.$n->award_type_id;
    }
}
