<?php

namespace App\Support\Attendance;

use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\WorkSchedule;
use Illuminate\Support\Collection;

/**
 * Which attendance policy judges this person on this day (ADR 0038).
 *
 * The one place that answers it, so the punch engine, a re-apply and the
 * worked example never disagree. Precedence, most specific first — the same
 * shape as {@see ShiftResolver}'s chain:
 *
 *  1. **assignment** — the policy on the dated assignment covering the date, for
 *     one person judged differently from everybody else on their shift.
 *  2. **schedule** — the policy on the schedule the day's shift came from
 *     (including one a roster override borrowed for the day).
 *  3. **department** — the department's policy.
 *  4. **organization** — the company's default policy.
 *  5. **fallback** — the built-in policy, which judges exactly as attendance did
 *     before policies existed ({@see AttendancePolicySettings::fallback()}).
 *
 * Work locations (Phase 4) will slot in between department and organisation.
 *
 * {@see forMany()} answers for a whole roster over a whole range in five queries
 * whatever the range — and in one for a company that has no policies at all,
 * which is every company until it configures one.
 */
class PolicyResolver
{
    /**
     * Policies already loaded, keyed by id — including archived ones, so a day
     * whose schedule points at a retired policy still resolves.
     *
     * @var array<int, AttendancePolicy>
     */
    private array $policies = [];

    /**
     * The policy one employee is judged by on the date of a shift.
     */
    public function for(Employee $employee, ResolvedShift $shift): ResolvedPolicy
    {
        return $this->forMany(collect([$employee]), [$employee->id => [$shift->date => $shift]])[$employee->id][$shift->date]
            ?? ResolvedPolicy::fallback();
    }

    /**
     * Every policy for every employee and date the shifts cover, as
     * `[employeeId][Y-m-d] => ResolvedPolicy`.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array<int, array<string, ResolvedShift>>  $shifts  What {@see ShiftResolver::forMany()} returned.
     * @return array<int, array<string, ResolvedPolicy>>
     */
    public function forMany(Collection $employees, array $shifts): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        // A company that has never written a policy is judged by the fallback
        // everywhere — one query rather than five.
        if (! AttendancePolicy::withTrashed()->exists()) {
            return $this->everywhere($employees, $shifts, ResolvedPolicy::fallback());
        }

        $dates = collect($shifts)->flatMap(fn (array $days): array => array_keys($days));

        if ($dates->isEmpty()) {
            return [];
        }

        [$from, $to] = [(string) $dates->min(), (string) $dates->max()];
        $ids = $employees->pluck('id')->all();

        $assignments = EmployeeScheduleAssignment::query()
            ->whereIn('employee_id', $ids)
            ->overlapping($from, $to)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get(['id', 'employee_id', 'effective_from', 'effective_to', 'attendance_policy_id'])
            ->groupBy('employee_id');

        $scheduleIds = collect($shifts)->flatten(1)->map(fn (ResolvedShift $shift): ?int => $shift->scheduleId)->filter()->unique()->values();

        $schedulePolicies = $scheduleIds->isEmpty() ? [] : WorkSchedule::withTrashed()
            ->whereIn('id', $scheduleIds)
            ->whereNotNull('attendance_policy_id')
            ->pluck('attendance_policy_id', 'id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $departmentIds = $employees->pluck('department_id')->filter()->unique()->values();

        $departmentPolicies = $departmentIds->isEmpty() ? [] : Department::withTrashed()
            ->whereIn('id', $departmentIds)
            ->whereNotNull('attendance_policy_id')
            ->pluck('attendance_policy_id', 'id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->preload(array_merge(
            $assignments->flatten(1)->pluck('attendance_policy_id')->filter()->all(),
            array_values($schedulePolicies),
            array_values($departmentPolicies),
        ));

        $organizationDefault = $this->organizationDefault();

        $out = [];

        foreach ($employees as $employee) {
            $own = $assignments->get($employee->id) ?? collect();

            foreach ($shifts[$employee->id] ?? [] as $date => $shift) {
                $assignment = $own->first(fn (EmployeeScheduleAssignment $a): bool => $this->covers($a, $date));

                $out[$employee->id][$date] = $this->resolve([
                    [$assignment?->attendance_policy_id, 'assignment'],
                    [$shift->scheduleId !== null ? ($schedulePolicies[$shift->scheduleId] ?? null) : null, 'schedule'],
                    [$employee->department_id !== null ? ($departmentPolicies[$employee->department_id] ?? null) : null, 'department'],
                    [$organizationDefault?->id, 'organization'],
                ]);
            }
        }

        return $out;
    }

    /**
     * The first link in the chain that names a policy that exists.
     *
     * @param  list<array{0: ?int, 1: string}>  $chain
     */
    private function resolve(array $chain): ResolvedPolicy
    {
        foreach ($chain as [$id, $source]) {
            $policy = $id !== null ? ($this->policies[$id] ?? null) : null;

            if ($policy !== null) {
                return new ResolvedPolicy(
                    settings: $policy->settings(),
                    source: $source,
                    id: $policy->id,
                    name: $policy->name,
                );
            }
        }

        return ResolvedPolicy::fallback();
    }

    /**
     * The organisation's default policy — the one marked `is_default` and not
     * archived.
     */
    private function organizationDefault(): ?AttendancePolicy
    {
        $default = AttendancePolicy::query()->where('is_default', true)->first();

        if ($default !== null) {
            $this->policies[$default->id] = $default;
        }

        return $default;
    }

    /**
     * Load every policy the chain might reach, in one query.
     *
     * @param  array<int, int|string|null>  $ids
     */
    private function preload(array $ids): void
    {
        $missing = collect($ids)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => isset($this->policies[$id]))
            ->values();

        if ($missing->isEmpty()) {
            return;
        }

        AttendancePolicy::withTrashed()
            ->whereIn('id', $missing)
            ->get()
            ->each(function (AttendancePolicy $policy): void {
                $this->policies[$policy->id] = $policy;
            });
    }

    /**
     * One answer for every date of every employee.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array<int, array<string, ResolvedShift>>  $shifts
     * @return array<int, array<string, ResolvedPolicy>>
     */
    private function everywhere(Collection $employees, array $shifts, ResolvedPolicy $policy): array
    {
        $out = [];

        foreach ($employees as $employee) {
            foreach (array_keys($shifts[$employee->id] ?? []) as $date) {
                $out[$employee->id][$date] = $policy;
            }
        }

        return $out;
    }

    /**
     * Whether an assignment's range covers a date.
     */
    private function covers(EmployeeScheduleAssignment $assignment, string $date): bool
    {
        if ($assignment->effective_from->toDateString() > $date) {
            return false;
        }

        return $assignment->effective_to === null || $assignment->effective_to->toDateString() >= $date;
    }
}
