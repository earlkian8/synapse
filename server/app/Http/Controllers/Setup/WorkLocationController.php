<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\WorkLocationPeopleRequest;
use App\Http\Requests\Setup\WorkLocationRequest;
use App\Http\Resources\WorkLocationResource;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Locations (ADR 0040): the company's sites, each a fence drawn
 * on a map, who is based where, and the schedule and attendance policy people
 * based at a site default to.
 *
 * Addressed by hashid; restore / force-delete take it as a string. Archived
 * rather than deleted, so a punch that names a site keeps saying where it was.
 * Moving a fence never re-judges a punch already made — a punch was judged where
 * it was made.
 */
class WorkLocationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('setup/locations', [
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
        ]);
    }

    public function store(WorkLocationRequest $request): RedirectResponse
    {
        $location = WorkLocation::create($request->locationAttributes());

        ActivityLogger::log(
            event: 'created',
            description: "Created work location \"{$location->name}\" ({$location->radius_meters} m fence)",
            subject: $location,
            logName: 'company-setup',
            subjectLabel: $location->name,
        );

        return $this->respond('Location created. Add the people based there so their punches are checked against it.');
    }

    public function update(WorkLocationRequest $request, WorkLocation $workLocation): RedirectResponse
    {
        $workLocation->update($request->locationAttributes());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated work location \"{$workLocation->name}\"",
            subject: $workLocation,
            properties: ['changes' => array_keys($workLocation->getChanges())],
            logName: 'company-setup',
            subjectLabel: $workLocation->name,
        );

        return $this->respond('Location updated. Punches already made keep where they were judged.');
    }

    /**
     * Set who is based here, and for whom it is the primary site. Anybody not
     * listed stops being based here; somebody made primary here stops having a
     * primary site anywhere else.
     */
    public function people(WorkLocationPeopleRequest $request, WorkLocation $workLocation): RedirectResponse
    {
        $ids = array_map('intval', $request->validated('employee_ids'));
        $primary = array_map('intval', $request->validated('primary_ids'));

        DB::transaction(function () use ($workLocation, $ids, $primary): void {
            $workLocation->employees()->sync(array_fill_keys($ids, ['is_primary' => false]));
            $workLocation->makePrimaryFor($primary);
        });

        ActivityLogger::log(
            event: 'updated',
            description: 'Set '.count($ids).' '.str('person')->plural(count($ids))." as based at \"{$workLocation->name}\"",
            subject: $workLocation,
            properties: ['employees' => count($ids), 'primary' => count($primary)],
            logName: 'company-setup',
            subjectLabel: $workLocation->name,
        );

        return $this->respond(count($ids) === 1 ? '1 person is based here.' : count($ids).' people are based here.');
    }

    public function destroy(WorkLocation $workLocation): RedirectResponse
    {
        $name = $workLocation->name;
        $workLocation->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived work location \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Location archived. Punches are no longer checked against it.');
    }

    public function restore(string $workLocation): RedirectResponse
    {
        $model = $this->findTrashed($workLocation);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored work location \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Location restored.');
    }

    public function forceDelete(string $workLocation): RedirectResponse
    {
        $model = $this->findTrashed($workLocation);

        if (AttendancePunch::withTrashed()->where('work_location_id', $model->id)->exists()) {
            return $this->respond('Punches were made at this location, so it is kept to say where they were. It stays archived.', 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted work location \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Location permanently deleted.');
    }

    /**
     * @return Builder<WorkLocation>
     */
    private function listing(): Builder
    {
        return WorkLocation::query()
            ->with(['defaultSchedule:id,name', 'policy:id,name'])
            ->withCount(['employees', 'devices'])
            ->orderByDesc('is_active')
            ->orderBy('name');
    }

    private function findTrashed(string $hashid): WorkLocation
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return WorkLocation::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
