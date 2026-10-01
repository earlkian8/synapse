<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\StartOnboardingRequest;
use App\Http\Resources\OnboardingCaseResource;
use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingProgram;
use App\Models\User;
use App\Queries\OnboardingProgramsOverviewQuery;
use App\Queries\OnboardingStatistics;
use App\Support\ActivityLogger;
use App\Support\OnboardingProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingCaseController extends Controller
{
    /**
     * Display the onboarding overview: every program, with how many people it is
     * onboarding and how that is going. Each opens onto the people it covers
     * ({@see OnboardingProgramCasesController}).
     */
    public function index(Request $request, OnboardingProgramsOverviewQuery $programs, OnboardingStatistics $statistics): Response
    {
        return Inertia::render('onboarding/index', [
            'programs' => $programs->rows($request),
            'stats' => $statistics->toArray(),
            'options' => $this->startOptions(),
            'can' => self::permissions($request),
            'filters' => ['search' => $request->string('search')->toString()],
        ]);
    }

    /**
     * Display a single onboarding case with its checklist.
     */
    public function show(Request $request, OnboardingCase $case): Response
    {
        $case->load([
            'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id,employment_type,date_hired',
            'employee.department:id,name',
            'employee.position:id,title',
            'program:id,name',
            'tasks' => fn ($query) => $query->with('assignee:id,first_name,middle_name,last_name,suffix'),
        ]);

        return Inertia::render('onboarding/case', [
            'case' => (new OnboardingCaseResource($case))->resolve($request),
            'options' => ['assignees' => $this->assignableUsers()],
            'can' => self::permissions($request),
        ]);
    }

    /**
     * Start onboarding for an employee, optionally from a chosen program.
     */
    public function store(StartOnboardingRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));

        if ($employee->onboardingCase()->exists()) {
            return $this->respond('That employee is already being onboarded.', 'warning');
        }

        $program = $request->filled('program_id')
            ? OnboardingProgram::find($request->integer('program_id'))
            : null;

        $case = OnboardingProvisioner::start($employee, $program);

        ActivityLogger::log(
            event: 'created',
            description: "Started onboarding for {$employee->full_name}",
            subject: $case,
            properties: ['program' => $program?->name],
            logName: 'onboarding',
            subjectLabel: $employee->full_name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Onboarding started.']);

        return redirect()->route('onboarding.show', $case);
    }

    /**
     * Update a case's notes and target completion date.
     */
    public function update(Request $request, OnboardingCase $case): RedirectResponse
    {
        $validated = $request->validate([
            'target_end_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $case->update($validated);

        return $this->respond('Onboarding updated.');
    }

    /**
     * Advance a case's lifecycle: complete, cancel, or reopen it.
     */
    public function status(Request $request, OnboardingCase $case): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(OnboardingCase::LIFECYCLE_ACTIONS)],
        ]);

        $case->load('employee:id,first_name,middle_name,last_name,suffix');

        $case->applyLifecycle($validated['action']);

        ActivityLogger::log(
            event: 'updated',
            description: ucfirst($validated['action']).'d onboarding for '.$case->employee->full_name,
            subject: $case,
            logName: 'onboarding',
            subjectLabel: $case->employee->full_name,
        );

        return $this->respond(match ($validated['action']) {
            'complete' => 'Onboarding completed.',
            'cancel' => 'Onboarding cancelled.',
            default => 'Onboarding reopened.',
        });
    }

    /**
     * Delete an onboarding case (and its tasks).
     */
    public function destroy(OnboardingCase $case): RedirectResponse
    {
        $case->load(['employee:id,first_name,middle_name,last_name,suffix', 'program:id']);
        $program = $case->program;
        $name = $case->employee?->full_name ?? 'employee';
        $case->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted onboarding for {$name}",
            logName: 'onboarding',
            subjectLabel: $name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Onboarding removed.']);

        // Back to the people the case was listed with.
        return $program !== null
            ? redirect()->route('onboarding.programs.show', $program)
            : redirect()->route('onboarding.programs.unassigned');
    }

    /**
     * Permission flags shared with the front-end.
     *
     * @return array<string, bool>
     */
    public static function permissions(Request $request): array
    {
        $user = $request->user();

        return [
            'manage' => $user->can('onboarding.manage'),
            'managePrograms' => $user->can('onboarding.manage-programs'),
        ];
    }

    /**
     * What starting onboarding offers: the active programs, and the employees who
     * can still be put through onboarding — on the roster, and without a case.
     * Shared with {@see OnboardingProgramCasesController}.
     *
     * @return array<string, mixed>
     */
    public static function startOptions(): array
    {
        return [
            'programs' => OnboardingProgram::where('is_active', true)
                ->withCount('tasks')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (OnboardingProgram $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'tasks_count' => $p->tasks_count,
                    'is_default' => $p->is_default,
                ]),
            'employees' => Employee::query()
                ->where('employment_status', 'active')
                ->whereDoesntHave('onboardingCase')
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
     * Active users that a task may be assigned to.
     *
     * @return list<array{id: int, full_name: string}>
     */
    private function assignableUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix'])
            ->map(fn (User $u): array => ['id' => $u->id, 'full_name' => $u->full_name])
            ->all();
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
