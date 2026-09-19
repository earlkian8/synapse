<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOrganizations;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\Attendance\DayCloser;
use App\Support\Attendance\DayRules;
use App\Support\Attendance\PolicyResolver;
use App\Support\Attendance\ShiftResolver;
use App\Support\HolidayCalendar;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Clock-in reminders (ADR 0041), scheduled every fifteen minutes. Somebody whose
 * working shift started at least the policy's `reminders.clock_in_after_minutes`
 * ago, is still under way, and has not clocked in is told — once per shift.
 *
 * Nobody is reminded on a rest day, on approved leave, on a holiday nobody works,
 * or on a day of approved official business or remote work. A policy that sets no
 * minutes sends nothing, which is the built-in default.
 *
 * The reminder is an ordinary notification (in the app, by email and by web push,
 * as the person chose). The mobile app has no push channel of its own yet.
 */
class RemindAttendance extends Command
{
    use ResolvesOrganizations;

    protected $signature = 'attendance:remind
        {--organization= : Only this organisation (id or slug)}';

    protected $description = 'Remind people whose shift has started that they have not clocked in';

    public function handle(Tenancy $tenancy): int
    {
        $organizations = $this->organizations();

        if ($organizations === null) {
            return self::FAILURE;
        }

        $sent = 0;

        foreach ($organizations as $organization) {
            $sent += $tenancy->runFor($organization, fn (): int => $this->remind($organization->id));
        }

        $this->info(sprintf('%d %s sent.', $sent, str('reminder')->plural($sent)));

        return self::SUCCESS;
    }

    /**
     * Remind everybody in the bound organisation who is due one.
     */
    private function remind(int $organizationId): int
    {
        $now = CarbonImmutable::now();
        $today = OrganizationClock::today();
        // A night shift that started yesterday evening is still today's concern.
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();

        $employees = Employee::query()
            ->whereIn('employment_status', DayCloser::WORKING_STATUSES)
            ->whereNotNull('user_id')
            ->with('user')
            ->get();

        if ($employees->isEmpty()) {
            return 0;
        }

        $shifts = (new ShiftResolver)->forMany($employees, $yesterday, $today);
        $policies = (new PolicyResolver)->forMany($employees, $shifts);
        $holidays = HolidayCalendar::inRange(CarbonImmutable::parse($yesterday), CarbonImmutable::parse($today));
        $ids = $employees->pluck('id');

        $clockedIn = AttendanceRecord::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$yesterday, $today])
            ->whereNotNull('first_in_at')
            ->get(['employee_id', 'work_date'])
            ->map(fn (AttendanceRecord $record): string => $record->employee_id.'|'.$record->work_date->toDateString())
            ->flip();

        $onLeave = LeaveRequest::query()
            ->whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $yesterday)
            ->get(['employee_id', 'start_date', 'end_date']);

        $away = AttendanceRequest::query()
            ->whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->whereIn('type', ['official_business', 'remote_work'])
            ->overlapping($yesterday, $today)
            ->get(['employee_id', 'start_date', 'end_date']);

        $covers = fn ($rows, int $employeeId, string $date): bool => $rows->contains(
            fn ($row): bool => $row->employee_id === $employeeId
                && $row->start_date->toDateString() <= $date
                && $row->end_date->toDateString() >= $date,
        );

        $sent = 0;

        foreach ($employees as $employee) {
            foreach ($shifts[$employee->id] ?? [] as $date => $shift) {
                $after = ($policies[$employee->id][$date] ?? null)?->settings->clockInReminderAfterMinutes;
                $start = $shift->startsAt();
                $end = $shift->endsAt();

                if ($after === null || ! $shift->isWorkingDay || $start === null
                    || $now->lt($start->addMinutes($after)) || ($end !== null && $now->gte($end))
                    || $clockedIn->has($employee->id.'|'.$date)
                    || DayRules::fromShift($shift, $holidays[$date] ?? null)->isNonWorkingHoliday()
                    || $covers($onLeave, $employee->id, $date)
                    || $covers($away, $employee->id, $date)
                    || $employee->user === null || ! $employee->user->is_active) {
                    continue;
                }

                // Once per shift, however often the job runs.
                if (! Cache::add("attendance:reminded:{$organizationId}:{$employee->id}:{$date}", true, now()->addDays(2))) {
                    continue;
                }

                $sent += Notifier::toUser(
                    $employee->user,
                    "You haven't clocked in",
                    'Your shift started at '.OrganizationClock::local($start)->format('g:i A').'. Clock in now, or ask for a correction if you already started.',
                    url: '/attendance/me',
                    level: 'warning',
                    category: 'attendance',
                );
            }
        }

        return $sent;
    }
}
