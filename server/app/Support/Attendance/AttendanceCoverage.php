<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Support\OrganizationClock;
use Illuminate\Support\Collection;

/**
 * Who a schedule or an attendance policy reaches **today** — asked of the same
 * resolvers the punch engine asks ({@see ShiftResolver}, {@see PolicyResolver}),
 * so "judges 42 people" counts the people whose day it will actually judge, not
 * the rows that happen to name it.
 *
 * A policy named on a department reaches nobody there who has a policy of their
 * own on the assignment or the schedule; a company default reaches only those
 * whom nothing more specific claims. Counting links would get both wrong.
 *
 * Everybody whose day is judged is counted: the working statuses the day closer
 * walks ({@see DayCloser::WORKING_STATUSES}). One resolution per instance.
 */
class AttendanceCoverage
{
    /** @var array{shifts: array<int, ResolvedShift>, policies: array<int, ResolvedPolicy>}|null */
    private ?array $today = null;

    public function __construct(
        private readonly ShiftResolver $shifts = new ShiftResolver,
        private readonly PolicyResolver $policies = new PolicyResolver,
    ) {}

    /**
     * How many people the policy judges today. Null counts those judged by the
     * built-in rules.
     */
    public function judgedBy(?int $policyId): int
    {
        return count(array_filter(
            $this->today()['policies'],
            fn (ResolvedPolicy $policy): bool => $policy->id === $policyId,
        ));
    }

    /**
     * How many people work the schedule today — by assignment, a department's
     * or the company's default, or a one-off roster entry.
     */
    public function onSchedule(int $scheduleId): int
    {
        return count(array_filter(
            $this->today()['shifts'],
            fn (ResolvedShift $shift): bool => $shift->scheduleId === $scheduleId,
        ));
    }

    /**
     * How many people nothing more specific claims, so the company's default
     * hours (or the built-in Mon–Fri) decide their day.
     */
    public function onCompanyDefault(): int
    {
        return count(array_filter(
            $this->today()['shifts'],
            fn (ResolvedShift $shift): bool => in_array($shift->source, ['organization', 'fallback'], true),
        ));
    }

    /**
     * Today's shift and policy for everybody whose day is judged, keyed by
     * employee id.
     *
     * @return array{shifts: array<int, ResolvedShift>, policies: array<int, ResolvedPolicy>}
     */
    private function today(): array
    {
        if ($this->today !== null) {
            return $this->today;
        }

        $date = OrganizationClock::today();

        /** @var Collection<int, Employee> $employees */
        $employees = Employee::query()
            ->whereIn('employment_status', DayCloser::WORKING_STATUSES)
            ->get(['id', 'department_id', 'work_schedule_id']);

        $shifts = $this->shifts->forMany($employees, $date, $date);
        $policies = $this->policies->forMany($employees, $shifts);

        return $this->today = [
            'shifts' => array_filter(array_map(fn (array $days): ?ResolvedShift => $days[$date] ?? null, $shifts)),
            'policies' => array_filter(array_map(fn (array $days): ?ResolvedPolicy => $days[$date] ?? null, $policies)),
        ];
    }
}
