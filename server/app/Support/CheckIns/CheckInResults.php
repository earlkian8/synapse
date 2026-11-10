<?php

namespace App\Support\CheckIns;

use App\Models\CheckIn;
use App\Models\Department;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What HR may know from check-ins (ADR 0102): results for groups, never a person.
 *
 * **A group is shown only with at least {@see self::MIN_GROUP} answers.** Below
 * that, a group's average says too much about each person in it.
 *
 * **Hidden groups cannot be worked out by subtraction.** The company's result is
 * shown, and so are the departments that clear the bar, so a single hidden
 * department would be the company minus the rest. Hidden departments are therefore
 * pooled into "Other teams", shown as one group only when the pool itself has
 * {@see self::MIN_GROUP} answers. When the pool has some answers but fewer, the
 * smallest shown department is hidden into it too, until the pool clears the bar
 * or nothing is left to show — complementary suppression, as statistical offices do.
 *
 * Every figure is a count, a share or a mean over a group. Nothing here returns a
 * row, an employee or an answer.
 */
final class CheckInResults
{
    /** The fewest answers a group's results are shown for. Not configurable. */
    public const MIN_GROUP = 5;

    /** The months of trend shown. */
    public const TREND_MONTHS = 12;

    /**
     * One period's results: the company's, then each department's.
     *
     * @return array{period: string, period_label: string, company: array<string, mixed>, departments: list<array<string, mixed>>, others: array<string, mixed>|null}
     */
    public function period(CarbonImmutable $period): array
    {
        $rows = $this->rows($period, $period);
        $active = Employee::query()->where('employment_status', 'active')->count();
        $headcounts = Employee::query()->where('employment_status', 'active')
            ->selectRaw('department_id, count(*) as people')->groupBy('department_id')
            ->pluck('people', 'department_id');

        [$shown, $pool] = $this->suppress($rows->groupBy(fn (array $row): string => (string) ($row['department_id'] ?? '')));
        $names = Department::query()->whereIn('id', array_filter(array_keys($shown)))->pluck('name', 'id');

        $departments = [];
        foreach ($shown as $id => $group) {
            $departments[] = [
                'id' => $id === '' ? null : (int) $id,
                'name' => $id === '' ? 'No department' : ($names[$id] ?? 'Department'),
                // `pluck` keys the people with no department by ''.
                'people' => (int) ($headcounts[$id] ?? 0),
            ] + $this->summarise($group);
        }
        usort($departments, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'period' => $period->toDateString(),
            'period_label' => CheckInPeriod::label($period),
            'company' => ['people' => $active] + $this->summarise($rows),
            'departments' => $departments,
            'others' => $pool->isEmpty() ? null : $this->summarise($pool) + [
                'teams' => $rows->groupBy(fn (array $row): string => (string) ($row['department_id'] ?? ''))->count() - count($shown),
            ],
        ];
    }

    /**
     * The company's results month by month, oldest first, ending with `$through`.
     *
     * @return list<array<string, mixed>>
     */
    public function trend(CarbonImmutable $through, int $months = self::TREND_MONTHS): array
    {
        $from = $through->subMonthsNoOverflow($months - 1);
        $byPeriod = $this->rows($from, $through)->groupBy('period');

        return array_map(function (int $i) use ($from, $byPeriod): array {
            $period = $from->addMonthsNoOverflow($i);

            return [
                'period' => $period->toDateString(),
                'period_label' => CheckInPeriod::label($period),
            ] + $this->summarise($byPeriod->get($period->toDateString(), collect()));
        }, range(0, $months - 1));
    }

    /**
     * Each department's most recent shown result within the last `$months` months
     * (newest first) — what the attrition model reads as the climate around a person
     * (ADR 0102). Only results the period view itself shows, under the same rules,
     * so the model is never given a figure HR could not already see.
     *
     * @return array<int, array{period: string, mood: float, engagement: float, leave_share: float, answers: int}>
     */
    public function teamClimate(int $months = 3): array
    {
        $climate = [];

        foreach (CheckInPeriod::recent($months) as $period) {
            foreach ($this->period($period)['departments'] as $department) {
                if ($department['id'] === null || isset($climate[$department['id']]) || ! $department['shown']) {
                    continue;
                }

                $climate[$department['id']] = [
                    'period' => $period->toDateString(),
                    'mood' => $department['mood'],
                    'engagement' => $department['engagement'],
                    'leave_share' => $department['leave_share'],
                    'answers' => $department['answers'],
                ];
            }
        }

        return $climate;
    }

    /**
     * A group's figures — or only its answer count, when it is too small to show.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarise(Collection $rows): array
    {
        $answers = $rows->count();

        if ($answers < self::MIN_GROUP) {
            return ['answers' => $answers, 'shown' => false];
        }

        $mean = fn (string $key): float => round((float) $rows->avg($key), 2);

        return [
            'answers' => $answers,
            'shown' => true,
            'mood' => $mean('mood'),
            'satisfaction' => $mean('satisfaction'),
            'engagement' => round((float) $rows->avg(fn (array $r): float => ($r['energy'] + $r['enthusiasm'] + $r['immersion']) / 3), 2),
            'leave_share' => round($rows->filter(fn (array $r): bool => $r['leave_thoughts'] >= CheckInQuestions::LEAVE_THOUGHTS_OFTEN)->count() / $answers, 4),
            // How many answered each mood, 1 (rough) to 5 (great).
            'moods' => array_map(fn (int $m): int => $rows->where('mood', $m)->count(), range(1, 5)),
        ];
    }

    /**
     * Split groups into those shown and the pooled rest, so that no hidden group can
     * be recovered as a difference (see the class comment).
     *
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $groups
     * @return array{0: array<string, Collection<int, array<string, mixed>>>, 1: Collection<int, array<string, mixed>>}
     */
    private function suppress(Collection $groups): array
    {
        $shown = $groups->filter(fn (Collection $g): bool => $g->count() >= self::MIN_GROUP)->sortBy(fn (Collection $g): int => $g->count());
        $pool = $groups->reject(fn (Collection $g): bool => $g->count() >= self::MIN_GROUP)->flatten(1);

        while ($pool->isNotEmpty() && $pool->count() < self::MIN_GROUP && $shown->isNotEmpty()) {
            $smallest = $shown->keys()->first();
            $pool = $pool->concat($shown->pull($smallest));
        }

        // Nothing left shown means the company figure alone covers the pool: fine.
        return [$shown->all(), $pool->values()];
    }

    /**
     * The answers in a range of periods, as plain arrays (no names, no notes).
     *
     * @return Collection<int, array{period: string, department_id: int|null, mood: int, satisfaction: int, energy: int, enthusiasm: int, immersion: int, leave_thoughts: int}>
     */
    private function rows(CarbonImmutable $from, CarbonImmutable $through): Collection
    {
        return CheckIn::query()
            ->whereBetween('period', [$from->toDateString(), $through->toDateString()])
            ->get(['period', 'department_id', 'mood', 'satisfaction', 'energy', 'enthusiasm', 'immersion', 'leave_thoughts'])
            ->map(fn (CheckIn $c): array => [
                'period' => $c->period->toDateString(),
                'department_id' => $c->department_id,
                'mood' => $c->mood,
                'satisfaction' => $c->satisfaction,
                'energy' => $c->energy,
                'enthusiasm' => $c->enthusiasm,
                'immersion' => $c->immersion,
                'leave_thoughts' => $c->leave_thoughts,
            ]);
    }
}
