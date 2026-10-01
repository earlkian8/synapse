<?php

namespace App\Http\Controllers\Offboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offboarding\InitiateOffboardingRequest;
use App\Http\Requests\Offboarding\OffboardingStatusRequest;
use App\Http\Requests\Offboarding\UpdateOffboardingCaseRequest;
use App\Http\Resources\OffboardingCaseResource;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\OffboardingProgram;
use App\Queries\OffboardingCasesIndexQuery;
use App\Queries\OffboardingStatistics;
use App\Support\Offboarding\OffboardingException;
use App\Support\Offboarding\OffboardingWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The offboarding board and a case's page, and the exit's lifecycle. Every write
 * goes through {@see OffboardingWorkflow} — the path the assistant takes too — so
 * the rules and the audit trail are the same however an exit is changed. Thin
 * (route gates `offboarding.view` / `offboarding.manage`).
 */
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
            'program:id,name',
            'clearanceItems' => fn ($query) => $query->with('department:id,name', 'clearedBy:id,first_name,middle_name,last_name,suffix'),
        ]);

        return Inertia::render('offboarding/case', [
            'case' => (new OffboardingCaseResource($case))->resolve($request),
            'options' => [
                'departments' => $this->departments(),
                'programs' => $this->programs(),
            ],
            'can' => $this->permissions($request),
        ]);
    }

    /**
     * Start offboarding for an employee, seeding the clearance checklist.
     */
    public function store(InitiateOffboardingRequest $request, OffboardingWorkflow $workflow): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));

        $program = $request->filled('offboarding_program_id')
            ? OffboardingProgram::where('is_active', true)->find($request->integer('offboarding_program_id'))
            : null;

        try {
            $case = $workflow->start($employee, [
                'type' => $request->string('type')->toString(),
                'notice_date' => $request->date('notice_date')?->toDateString(),
                'last_working_day' => $request->date('last_working_day')?->toDateString(),
                'reason' => $request->string('reason')->toString() ?: null,
            ], $program);
        } catch (OffboardingException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Offboarding started.']);

        return redirect()->route('offboarding.show', $case);
    }

    /**
     * Update an exit's details — its kind, key dates and reason.
     */
    public function update(UpdateOffboardingCaseRequest $request, OffboardingCase $case, OffboardingWorkflow $workflow): RedirectResponse
    {
        $workflow->update($case, $request->validated());

        return $this->respond('Offboarding updated.');
    }

    /**
     * Advance a case's lifecycle: complete, cancel, or reopen it. Completing the
     * exit transitions the employee's employment_status to match the exit type;
     * reopening or cancelling returns them to active (ADR 0016).
     */
    public function status(OffboardingStatusRequest $request, OffboardingCase $case, OffboardingWorkflow $workflow): RedirectResponse
    {
        $action = $request->validated('action');

        try {
            $workflow->transition($case, $action);
        } catch (OffboardingException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Offboarding '.strtolower(OffboardingWorkflow::ACTIONS[$action]).'.');
    }

    /**
     * Delete an offboarding case (and its clearance items).
     */
    public function destroy(OffboardingCase $case, OffboardingWorkflow $workflow): RedirectResponse
    {
        $workflow->delete($case);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Offboarding removed.']);

        return redirect()->route('offboarding.index');
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
            'programs' => $this->programs(),
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

    /**
     * Active clearance templates, for the start-offboarding picker and the
     * apply-template dialog. Default first, then alphabetical.
     *
     * @return list<array{id: int, name: string, is_default: bool, items_count: int}>
     */
    private function programs(): array
    {
        return OffboardingProgram::query()
            ->where('is_active', true)
            ->withCount('items')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default'])
            ->map(fn (OffboardingProgram $program): array => [
                'id' => $program->id,
                'name' => $program->name,
                'is_default' => $program->is_default,
                'items_count' => (int) $program->items_count,
            ])
            ->all();
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
