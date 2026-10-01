<?php

namespace App\Queries\Setup;

use App\Http\Resources\HolidayResource;
use App\Http\Resources\WorkScheduleResource;
use App\Models\AttendancePolicy;
use App\Models\Holiday;
use App\Models\WorkSchedule;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Company Setup → Work Schedule & Holidays: the work patterns employees are
 * assigned to (read by Attendance) and the holiday calendar (read by Leave).
 *
 * A schedule is a template with a day pattern (ADR 0037), so its cycle is loaded
 * with it and the screen can offer one of them as the company's default hours.
 */
class WorkScheduleScreen implements SetupScreen
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function toArray(Request $request): array
    {
        return [
            'schedules' => WorkScheduleResource::collection($this->schedules()->get())->resolve($request),
            'archivedSchedules' => WorkScheduleResource::collection($this->schedules()->onlyTrashed()->get())->resolve($request),
            'holidays' => HolidayResource::collection($this->holidays()->get())->resolve($request),
            'archivedHolidays' => HolidayResource::collection($this->holidays()->onlyTrashed()->get())->resolve($request),
            // The schedule anybody with no assignment falls back to (ADR 0037).
            'defaultScheduleId' => $this->tenancy->organization()?->default_work_schedule_id,
            // The policies a schedule can be judged by (ADR 0038).
            'policies' => AttendancePolicy::query()->orderBy('name')->get(['id', 'name', 'is_default']),
            'can' => ['manage' => $request->user()->can('setup.schedule.manage')],
        ];
    }

    /**
     * @return Builder<WorkSchedule>
     */
    private function schedules(): Builder
    {
        return WorkSchedule::query()
            ->withCount('employees')
            ->with(['days' => fn ($query) => $query->orderBy('day_index')])
            ->orderBy('name');
    }

    /**
     * @return Builder<Holiday>
     */
    private function holidays(): Builder
    {
        return Holiday::query()->chronological();
    }
}
