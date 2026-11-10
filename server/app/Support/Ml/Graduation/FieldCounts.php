<?php

namespace App\Support\Ml\Graduation;

use App\Models\Employee;
use App\Support\CheckIns\CheckInResults;
use Illuminate\Database\Eloquent\Builder;

/**
 * How many of the organisation's records carry each field a predictive surface
 * draws on (or could) — the figures behind the panel's "what the scores are based
 * on" list. Counted over active employees, the people the surfaces score, except
 * departures, which are counted against the people who left.
 *
 * Each figure is one tenant-scoped count, computed on first use and kept for the
 * request, since the three surfaces share most of them.
 */
class FieldCounts
{
    /** Former employees, as the employee record names them. */
    public const DEPARTED = ['resigned', 'terminated'];

    /** @var array<string, int> */
    private array $cache = [];

    public function active(): int
    {
        return $this->remember('active', fn (): int => $this->activeEmployees()->count());
    }

    /** Active employees with at least `$n` completed appraisals. */
    public function appraised(int $n = 1): int
    {
        return $this->remember("appraised:{$n}", fn (): int => $this->activeEmployees()
            ->has('performanceEvaluations', '>=', $n, 'and', fn (Builder $q) => $q->completed())
            ->count());
    }

    /**
     * Active employees whose department has a check-in result shown in the last
     * three months — who carries the team-climate signal (ADR 0102).
     */
    public function teamClimate(): int
    {
        return $this->remember('team_climate', fn (): int => $this->activeEmployees()
            ->whereIn('department_id', array_keys(app(CheckInResults::class)->teamClimate()))
            ->count());
    }

    /** Active employees here a year or more — who a year of leave is read for. */
    public function yearOrMore(): int
    {
        return $this->remember('year_or_more', fn (): int => $this->activeEmployees()
            ->where('date_hired', '<=', today()->subYear()->toDateString())
            ->count());
    }

    public function hired(): int
    {
        return $this->remember('hired', fn (): int => $this->activeEmployees()->whereNotNull('date_hired')->count());
    }

    public function typed(): int
    {
        return $this->remember('typed', fn (): int => $this->activeEmployees()->whereNotNull('employment_type')->count());
    }

    public function inDepartment(): int
    {
        return $this->remember('department', fn (): int => $this->activeEmployees()->whereNotNull('department_id')->count());
    }

    public function salaried(): int
    {
        return $this->remember('salary', fn (): int => $this->activeEmployees()->where('basic_salary', '>', 0)->count());
    }

    public function promoted(): int
    {
        return $this->remember('promoted', fn (): int => $this->activeEmployees()->has('promotions')->count());
    }

    public function certified(): int
    {
        return $this->remember('certified', fn (): int => $this->activeEmployees()->has('certifications')->count());
    }

    /** Active employees with attendance tracked in the last 90 days. */
    public function attendanceTracked(): int
    {
        return $this->remember('attendance', fn (): int => $this->activeEmployees()
            ->whereHas('attendanceRecords', fn (Builder $q) => $q->where('work_date', '>=', today()->subDays(90)->toDateString()))
            ->count());
    }

    /** Active employees who completed a training in the last 12 months. */
    public function trained(): int
    {
        return $this->remember('trained', fn (): int => $this->activeEmployees()
            ->whereHas('trainingEnrollments', fn (Builder $q) => $q->where('status', 'completed')
                ->where('completed_at', '>=', now()->subYear()))
            ->count());
    }

    /** People who have left. */
    public function departed(): int
    {
        return $this->remember('departed', fn (): int => Employee::query()->whereIn('employment_status', self::DEPARTED)->count());
    }

    /** People who have left through a completed offboarding case, so with a recorded type. */
    public function departedWithReason(): int
    {
        return $this->remember('departed_with_reason', fn (): int => Employee::query()
            ->whereIn('employment_status', self::DEPARTED)
            ->whereHas('offboardingCase', fn (Builder $q) => $q->where('status', 'completed'))
            ->count());
    }

    /** @return Builder<Employee> */
    private function activeEmployees(): Builder
    {
        return Employee::query()->where('employment_status', 'active');
    }

    /**
     * @param  callable(): int  $count
     */
    private function remember(string $key, callable $count): int
    {
        return $this->cache[$key] ??= $count();
    }
}
