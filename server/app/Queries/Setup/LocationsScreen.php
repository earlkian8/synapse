<?php

namespace App\Queries\Setup;

use App\Http\Resources\WorkLocationResource;
use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Company Setup → Locations (ADR 0040): the company's sites, each a fence drawn
 * on a map, who is based where, and the schedule and attendance policy people
 * based at a site default to.
 */
class LocationsScreen implements SetupScreen
{
    public function toArray(Request $request): array
    {
        return [
            'locations' => WorkLocationResource::collection($this->listing()->with('employees:id')->get())->resolve($request),
            'archivedLocations' => WorkLocationResource::collection($this->listing()->onlyTrashed()->get())->resolve($request),
            'options' => [
                'schedules' => WorkSchedule::query()->orderBy('name')->get(['id', 'name']),
                'policies' => AttendancePolicy::query()->orderBy('name')->get(['id', 'name']),
                'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
                'employees' => Employee::query()
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no', 'department_id', 'photo'])
                    ->map(fn (Employee $employee): array => [
                        'id' => $employee->id,
                        'full_name' => $employee->full_name,
                        'initials' => $employee->initials(),
                        'employee_no' => $employee->employee_no,
                        'department_id' => $employee->department_id,
                        'photo' => $employee->photo_url,
                    ]),
            ],
            // Policies that check where people punch — which is only as good as
            // the sites drawn here.
            'checkingPolicies' => AttendancePolicy::query()
                ->get(['id', 'name', 'settings'])
                ->filter(fn (AttendancePolicy $policy): bool => $policy->settings()->geofence !== 'off')
                ->map(fn (AttendancePolicy $policy): array => ['name' => $policy->name, 'mode' => $policy->settings()->geofence])
                ->values(),
            'can' => ['manage' => $request->user()->can('setup.locations.manage')],
        ];
    }

    /**
     * @return Builder<WorkLocation>
     */
    private function listing(): Builder
    {
        return WorkLocation::query()
            ->with(['defaultSchedule:id,name', 'policy:id,name'])
            ->withCount('employees')
            ->orderByDesc('is_active')
            ->orderBy('name');
    }
}
