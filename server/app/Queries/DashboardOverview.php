<?php

namespace App\Queries;

use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Composes the home dashboard: a single, permission-aware overview of the active
 * organisation. Reuses the per-module {@see EmployeeStatistics}, {@see LeaveStatistics},
 * etc. classes — one source of truth for each module's headline numbers — and adds the
 * cross-cutting shape the overview needs (workforce composition, a hiring trend, a
 * consolidated "needs attention" queue, the recent activity feed).
 *
 * Every block is gated on the viewer's permissions and returned as null when they
 * can't see it, so the front-end simply renders whatever blocks are present. A user
 * with no management permissions (a regular employee) gets the greeting and an empty
 * overview rather than a wall of figures they aren't entitled to.
 */
class DashboardOverview
{
    /** Employment types, in the order the composition chart should read. */
    private const EMPLOYMENT_TYPES = [
        'regular' => 'Regular',
        'probationary' => 'Probationary',
        'contractual' => 'Contractual',
        'part_time' => 'Part-time',
    ];

    public function __construct(
        private readonly EmployeeStatistics $employees,
        private readonly DepartmentStatistics $departments,
        private readonly LeaveStatistics $leave,
        private readonly AttendanceStatistics $attendance,
        private readonly RecruitmentStatistics $recruitment,
        private readonly OnboardingStatistics $onboarding,
        private readonly OffboardingStatistics $offboarding,
    ) {}

    /**
     * Build the full overview payload for the given user.
     *
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $workforce = $user->hasPermissionTo('employees.view') ? $this->workforce() : null;
        $attendance = $user->hasPermissionTo('attendance.view') ? $this->attendanceToday() : null;
        $leave = $user->hasPermissionTo('leave.view') || $user->hasPermissionTo('leave.manage')
            ? $this->leave->toArray()
            : null;
        $recruitment = $user->hasPermissionTo('recruitment.view') ? $this->recruitment->toArray() : null;
        $onboarding = $user->hasPermissionTo('onboarding.view') ? $this->onboarding->toArray() : null;
        $offboarding = $user->hasPermissionTo('offboarding.view') ? $this->offboarding->toArray() : null;

        return [
            'today' => now()->toIso8601String(),
            'workforce' => $workforce,
            'attendance' => $attendance,
            'leave' => $leave,
            'recruitment' => $recruitment,
            'onboarding' => $onboarding,
            'offboarding' => $offboarding,
            'attention' => $this->attention($user, $leave, $attendance, $onboarding, $offboarding, $recruitment),
            'events' => $user->hasPermissionTo('events.view') ? $this->upcomingEvents() : null,
            'activity' => $user->hasPermissionTo('activity-logs.view') ? $this->recentActivity() : null,
        ];
    }

    /**
     * Headcount, workforce composition, the busiest departments, and a six-month
     * hiring trend.
     *
     * @return array<string, mixed>
     */
    private function workforce(): array
    {
        $byType = Employee::query()
            ->where('employment_status', 'active')
            ->selectRaw('employment_type, count(*) as aggregate')
            ->groupBy('employment_type')
            ->pluck('aggregate', 'employment_type');

        $composition = [];

        foreach (self::EMPLOYMENT_TYPES as $type => $label) {
            $composition[] = ['type' => $type, 'label' => $label, 'count' => (int) ($byType[$type] ?? 0)];
        }

        $topDepartments = Department::query()
            ->withCount(['employees' => fn (Builder $query) => $query->where('employment_status', 'active')])
            ->orderByDesc('employees_count')
            ->limit(6)
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => [
                'name' => $department->name,
                'count' => (int) $department->employees_count,
            ])
            ->all();

        return [
            ...$this->employees->toArray(),
            'departments' => $this->departments->toArray()['departments'],
            'composition' => $composition,
            'top_departments' => $topDepartments,
        ];
    }

    /**
     * Today's attendance board, the active headcount it is measured against, and a
     * two-week present-count trend (the workforce's recent rhythm).
     *
     * @return array<string, mixed>
     */
    private function attendanceToday(): array
    {
        return [
            ...$this->attendance->toArray(now()->toDateString()),
            'workforce' => Employee::where('employment_status', 'active')->count(),
            'trend' => $this->attendanceTrend(),
        ];
    }

    /**
     * Present headcount per day for the trailing 14 days (oldest first), filling days
     * with no records (weekends) as zero so the series shows the weekly cadence.
     *
     * @return list<array{label: string, value: int}>
     */
    private function attendanceTrend(): array
    {
        $start = now()->subDays(13)->startOfDay();

        $counts = AttendanceRecord::query()
            ->where('work_date', '>=', $start->toDateString())
            ->whereIn('status', ['present', 'late', 'undertime', 'incomplete'])
            ->selectRaw('work_date, count(*) as aggregate')
            ->groupBy('work_date')
            ->get()
            ->mapWithKeys(fn ($row): array => [Carbon::parse($row->work_date)->toDateString() => (int) $row->aggregate]);

        return collect(range(13, 0))
            ->map(function (int $daysAgo) use ($counts): array {
                $day = now()->subDays($daysAgo);

                return [
                    'label' => $day->format('M j'),
                    'value' => $counts[$day->toDateString()] ?? 0,
                ];
            })
            ->all();
    }

    /**
     * The consolidated action queue: items across modules that the viewer is allowed
     * to act on and that currently need attention. Empty when everything is clear.
     *
     * @param  array<string, int|float>|null  $leave
     * @param  array<string, int|float>|null  $attendance
     * @param  array<string, int>|null  $onboarding
     * @param  array<string, int>|null  $offboarding
     * @param  array<string, int>|null  $recruitment
     * @return list<array{key: string, label: string, count: int, href: string, tone: string}>
     */
    private function attention(
        User $user,
        ?array $leave,
        ?array $attendance,
        ?array $onboarding,
        ?array $offboarding,
        ?array $recruitment,
    ): array {
        $items = [];

        if ($leave !== null && $user->hasPermissionTo('leave.manage')) {
            $items[] = ['key' => 'leave', 'label' => 'Leave requests to review', 'count' => (int) $leave['pending'], 'href' => '/leave', 'tone' => 'amber'];
        }

        if ($attendance !== null && $user->hasPermissionTo('attendance.manage')) {
            $items[] = ['key' => 'attendance', 'label' => 'Attendance records to approve', 'count' => (int) $attendance['pending'], 'href' => '/attendance', 'tone' => 'amber'];
        }

        if ($onboarding !== null && $user->hasPermissionTo('onboarding.manage')) {
            $items[] = ['key' => 'onboarding', 'label' => 'Overdue onboarding tasks', 'count' => (int) $onboarding['overdue_tasks'], 'href' => '/onboarding', 'tone' => 'rose'];
        }

        if ($offboarding !== null && $user->hasPermissionTo('offboarding.manage')) {
            $items[] = ['key' => 'offboarding', 'label' => 'Flagged clearance items', 'count' => (int) $offboarding['flagged_items'], 'href' => '/offboarding', 'tone' => 'rose'];
        }

        if ($recruitment !== null) {
            $items[] = ['key' => 'interviews', 'label' => 'Interviews coming up', 'count' => (int) $recruitment['interviews_upcoming'], 'href' => '/recruitment', 'tone' => 'teal'];
        }

        // Only surface the rows that actually need action.
        return array_values(array_filter($items, fn (array $item): bool => $item['count'] > 0));
    }

    /**
     * The next few upcoming events/meetings.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingEvents(): array
    {
        return Event::query()
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(4)
            ->get(['id', 'title', 'type', 'starts_at', 'location'])
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'type' => $event->type,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'location' => $event->location,
            ])
            ->all();
    }

    /**
     * The most recent audit-trail entries, with the actor for each.
     *
     * @return list<array<string, mixed>>
     */
    private function recentActivity(): array
    {
        return ActivityLog::query()
            ->with('causer:id,first_name,middle_name,last_name,suffix,profile_photo')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'id' => $log->id,
                'description' => $log->description,
                'event' => $log->event,
                'subject_label' => $log->subject_label,
                'created_at' => $log->created_at?->toIso8601String(),
                'causer' => $log->causer ? [
                    'name' => $log->causer->full_name,
                    'initials' => $this->initials($log->causer->first_name, $log->causer->last_name),
                    'avatar' => $log->causer->avatar,
                ] : null,
            ])
            ->all();
    }

    private function initials(?string $first, ?string $last): string
    {
        return mb_strtoupper(mb_substr((string) $first, 0, 1).mb_substr((string) $last, 0, 1)) ?: '?';
    }
}
