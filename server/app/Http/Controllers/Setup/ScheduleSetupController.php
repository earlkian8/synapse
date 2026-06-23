<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Resources\HolidayResource;
use App\Http\Resources\WorkScheduleResource;
use App\Models\Holiday;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Work Schedule & Holidays: the work patterns employees are
 * assigned to (read by Attendance) and the organisation's holiday calendar (read
 * by Leave). Both are addressed by hashid; restore / force-delete take the hashid
 * as a string.
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
            'can' => ['manage' => $request->user()->can('setup.schedule.manage')],
        ]);
    }

    /**
     * @return Builder<WorkSchedule>
     */
    private function schedules(): Builder
    {
        return WorkSchedule::query()->withCount('employees')->orderBy('name');
    }

    /**
     * @return Builder<Holiday>
     */
    private function holidays(): Builder
    {
        return Holiday::query()->chronological();
    }
}
