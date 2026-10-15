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
use App\Models\User;
use App\Queries\OffboardingCasesIndexQuery;
use App\Queries\OffboardingStatistics;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use App\Support\Offboarding\MyExit;
use App\Support\Offboarding\OffboardingDocuments;
use App\Support\Offboarding\OffboardingException;
use App\Support\Offboarding\OffboardingWorkflow;
use App\Support\OrganizationClock;
use App\Support\Users\UserAccounts;
use Carbon\CarbonImmutable;
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
    use OffboardingResponses;

    /**
     * Display the offboarding overview — a board of in-flight (and past) exits.
     */
    public function index(Request $request, OffboardingCasesIndexQuery $query, OffboardingStatistics $statistics): Response|RedirectResponse
    {
        // Somebody who only takes part goes to their own section (ADR 0076).
        if ($request->user()->cannot('offboarding.view')) {
            abort_unless($request->user()->can('offboarding.resign'), 403);

            return redirect()->route('offboarding.me');
        }

        return Inertia::render('offboarding/index', [
            'nav' => $this->navCounts($request),
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
            'employee:id,user_id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id,employment_type,employment_status,date_hired',
            'employee.department:id,name',
            'employee.position:id,title',
            'program:id,name',
            'clearanceItems' => fn ($query) => $query->with('department:id,name', 'clearedBy:id,first_name,middle_name,last_name,suffix'),
            'exitInterview.waiver:id,first_name,middle_name,last_name,suffix',
            'resignation:id,offboarding_case_id,created_at,proposed_last_day',
        ]);

        $interview = $case->exitInterview;

        return Inertia::render('offboarding/case', [
            'case' => (new OffboardingCaseResource($case))->resolve($request),
            // The survey sent to the leaver and, once answered, what they said
            // (ADR 0076) — `offboarding.view` reads it, as the route requires.
            'interview' => $interview === null ? null : [
                ...MyExit::interview($interview),
                'questions' => $interview->questions,
                'answers' => $interview->answers,
                'reminded_on' => $interview->reminded_at !== null ? OrganizationClock::localDate(CarbonImmutable::instance($interview->reminded_at)) : null,
                'waived_by' => $interview->waiver?->full_name,
                'average' => ExitInterviewQuestionnaire::averageRating($interview->answers),
            ],
            'documents' => [
                'certificate_ready' => OffboardingDocuments::certificateReady($case),
                'certificate_no' => $case->certificate_no,
            ],
            'resignation' => $case->resignation === null ? null : [
                'filed_on' => OrganizationClock::localDate(CarbonImmutable::instance($case->resignation->created_at)),
                'proposed_last_day' => $case->resignation->proposed_last_day?->toDateString(),
            ],
            // Whether the leaver can still sign in here (ADR 0089).
            'access' => $this->access($request, $case),
            'scale' => ExitInterviewQuestionnaire::SCALE,
            'tab' => $request->string('tab')->toString() === 'interview' ? 'interview' : 'clearance',
            'nav' => $this->navCounts($request),
            'options' => [
                'departments' => $this->departments(),
                'programs' => OffboardingProgram::options(),
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
     * Whether the leaver's account still opens this workspace, and whether the
     * viewer may end that (ADR 0089). Their access outlives the exit on purpose —
     * they answer the exit interview and download their certificate with it — so
     * ending it is HR's call, made here or on the Users screen. Null when they have
     * no account here.
     *
     * @return array{user_id: int, shared: bool, can_remove: bool, reason: string|null}|null
     */
    private function access(Request $request, OffboardingCase $case): ?array
    {
        $userId = $case->employee?->user_id;
        $user = $userId === null ? null : User::query()->inCurrentOrganization()->find($userId);

        if ($user === null) {
            return null;
        }

        $actor = $request->user();
        $reason = $actor->can('users.delete')
            ? UserAccounts::whyNotArchive($user, $actor)
            : 'Somebody who manages user accounts can remove it, on the User Management screen.';

        return [
            'user_id' => $user->id,
            'shared' => UserAccounts::isShared($user),
            'can_remove' => $reason === null,
            'reason' => $reason,
        ];
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
            'programs' => OffboardingProgram::options(),
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
