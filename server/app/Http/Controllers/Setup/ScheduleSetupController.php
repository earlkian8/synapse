<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Resources\HolidayResource;
use App\Http\Resources\WorkScheduleResource;
use App\Models\AttendancePolicy;
use App\Models\Holiday;
use App\Models\WorkSchedule;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Work Schedule & Holidays: the work patterns employees are
 * assigned to (read by Attendance) and the organisation's holiday calendar (read
 * by Leave). Both are addressed by hashid; restore / force-delete take the hashid
 * as a string.
 *
 * A schedule is a template with a day pattern (ADR 0037), so its cycle is loaded
 * with it and the page can offer one of them as the company's default hours.
 */
class ScheduleSetupController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('setup/schedule', [
            'schedules' => WorkScheduleResource::collection($this->schedules()->get())->resolve($request),
            'archivedSchedules' => WorkScheduleResource::collection($this->schedules()->onlyTrashed()->get())->resolve($request),
            'holidays' => HolidayResource::collection($this->holidays()->get())->resolve($request),
            'archivedHolidays' => HolidayResource::collection($this->holidays()->onlyTrashed()->get())->resolve($request),
            // The schedule anybody with no assignment falls back to (ADR 0037).
            'defaultScheduleId' => app(Tenancy::class)->organization()?->default_work_schedule_id,
            // The policies a schedule can be judged by (ADR 0038).
            'policies' => AttendancePolicy::query()->orderBy('name')->get(['id', 'name', 'is_default']),
            'can' => ['manage' => $request->user()->can('setup.schedule.manage')],
        ]);
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
