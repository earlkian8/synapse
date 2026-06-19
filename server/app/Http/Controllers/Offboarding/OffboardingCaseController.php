<?php

namespace App\Http\Controllers\Offboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offboarding\InitiateOffboardingRequest;
use App\Http\Resources\OffboardingCaseResource;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Queries\OffboardingCasesIndexQuery;
use App\Queries\OffboardingStatistics;
use App\Support\ActivityLogger;
use App\Support\OffboardingProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OffboardingCaseController extends Controller
{
    /**
     * Display the offboarding overview — a board of in-flight (and past) exits.
     */
    public function index(Request $request, OffboardingCasesIndexQuery $query, OffboardingStatistics $statistics): Response
    {
        return Inertia::render('offboarding/index', [
            'cases' => OffboardingCaseResource::collection($query->get($request))->resolve($request),
            'stats' => $statistics->toArray(),
            'options' => $this->indexOptions(),
            'can' => $this->permissions($request),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $query->status($request),
                'type' => $request->string('type')->toString() ?: null,
                'department' => $request->integer('department') ?: null,
            ],
        ]);
    }

    /**
     * Display a single offboarding case with its clearance checklist.
     */
    public function show(Request $request, OffboardingCase $case): Response
    {
        $case->load([
            'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id,employment_type,employment_status,date_hired',
            'employee.department:id,name',
            'employee.position:id,title',
            'clearanceItems' => fn ($query) => $query->with('department:id,name', 'clearedBy:id,first_name,middle_name,last_name,suffix'),
        ]);

        return Inertia::render('offboarding/case', [
            'case' => (new OffboardingCaseResource($case))->resolve($request),
            'options' => ['departments' => $this->departments()],
            'can' => $this->permissions($request),
        ]);
    }

    /**
     * Start offboarding for an employee, seeding the standard clearance checklist.
     */
    public function store(InitiateOffboardingRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));

        if ($employee->offboardingCase()->exists()) {
            return $this->respond('That employee is already being offboarded.', 'warning');
        }

        $case = OffboardingProvisioner::start($employee, [
            'type' => $request->string('type')->toString(),
            'notice_date' => $request->date('notice_date')?->toDateString(),
            'last_working_day' => $request->date('last_working_day')?->toDateString(),
            'reason' => $request->string('reason')->toString() ?: null,
        ]);

        ActivityLogger::log(
            event: 'created',
            description: "Started offboarding for {$employee->full_name}",
            subject: $case,
            properties: ['type' => $case->type],
            logName: 'offboarding',
            subjectLabel: $employee->full_name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Offboarding started.']);

        return redirect()->route('offboarding.show', $case);
    }

    /**
     * Update an exit's details — its kind, key dates and reason.
     */
    public function update(Request $request, OffboardingCase $case): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(OffboardingCase::TYPES)],
            'notice_date' => ['nullable', 'date'],
            'last_working_day' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $case->update($validated);

        return $this->respond('Offboarding updated.');
    }

    /**
     * Advance a case's lifecycle: complete, cancel, or reopen it. Completing the
     * exit transitions the employee's employment_status to match the exit type;
     * reopening or cancelling returns them to active (ADR 0016).
     */
    public function status(Request $request, OffboardingCase $case): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['complete', 'cancel', 'reopen'])],
        ]);

        $case->load('employee:id,first_name,middle_name,last_name,suffix,employment_status');

        match ($validated['action']) {
            'complete' => $this->complete($case),
            'cancel' => $this->reactivate($case, 'cancelled'),
            'reopen' => $this->reactivate($case, 'clearance'),
        };

        ActivityLogger::log(
            event: 'updated',
            description: ucfirst($validated['action']).'d offboarding for '.$case->employee->full_name,
            subject: $case,
            logName: 'offboarding',
            subjectLabel: $case->employee->full_name,
        );

        return $this->respond('Offboarding '.$case->status.'.');
    }

    /**
     * Delete an offboarding case (and its clearance items).
     */
    public function destroy(OffboardingCase $case): RedirectResponse
    {
        $case->load('employee:id,first_name,middle_name,last_name,suffix');
        $name = $case->employee?->full_name ?? 'employee';
        $case->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted offboarding for {$name}",
            logName: 'offboarding',
            subjectLabel: $name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Offboarding removed.']);

        return redirect()->route('offboarding.index');
    }

    /**
     * Finalise an exit and mark the employee separated.
     */
    private function complete(OffboardingCase $case): void
    {
        $case->update(['status' => 'completed', 'completed_at' => now()]);

        $case->employee?->update(['employment_status' => $case->targetEmploymentStatus()]);
    }

    /**
     * Re-open or cancel an exit and return the employee to active.
     */
    private function reactivate(OffboardingCase $case, string $status): void
    {
        $case->update(['status' => $status, 'completed_at' => null]);

        if ($case->employee && in_array($case->employee->employment_status, ['resigned', 'terminated'], true)) {
            $case->employee->update(['employment_status' => 'active']);
        }
    }

    /**
     * Permission flags shared with the front-end.
     *
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        return ['manage' => $request->user()->can('offboarding.manage')];
    }

    /**
     * Options for the overview: department filter and employees who can still be
     * put through offboarding (active and not already exiting).
     *
     * @return array<string, mixed>
     */
    private function indexOptions(): array
    {
        return [
            'departments' => $this->departments(),
            'employees' => Employee::query()
                ->whereNotIn('employment_status', ['resigned', 'terminated'])
                ->whereDoesntHave('offboardingCase')
                ->orderBy('first_name')
                ->limit(300)
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
                ->map(fn (Employee $e): array => [
                    'id' => $e->id,
                    'full_name' => $e->full_name,
                    'employee_no' => $e->employee_no,
                ]),
        ];
    }

    /**
     * The tenant's departments, for filters and the clearance department picker.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function departments()
    {
        return Department::orderBy('name')->get(['id', 'name']);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
