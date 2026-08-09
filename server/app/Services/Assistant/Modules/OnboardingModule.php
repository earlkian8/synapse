<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Onboarding\StoreOnboardingProgramRequest;
use App\Http\Requests\Onboarding\StoreOnboardingTaskRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingProgram;
use App\Models\OnboardingTask;
use App\Models\User;
use App\Queries\OnboardingStatistics;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\OnboardingProvisioner;
use App\Support\OnboardingTaskNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Onboarding capability — a new hire's whole journey, agentically.
 *
 * The tool surface mirrors the board and the case screen: run the cases (start,
 * retarget, complete / cancel / reopen, remove), work the checklist (find, add,
 * edit, tick off, remove, chase), maintain the programs those checklists are
 * seeded from, and read out how onboarding is going.
 *
 * Every mutation mirrors the onboarding controllers' validation, activity logging
 * and notifications, and reuses the canonical implementations rather than
 * re-deriving them: {@see OnboardingProvisioner} seeds a case,
 * `OnboardingCase::applyLifecycle()` / `touchProgress()` / `progressSummary()` and
 * `OnboardingTask::markStatus()` own the state transitions,
 * `OnboardingProgram::syncBlueprint()` / `enforceSingleDefault()` own the
 * templates, and {@see OnboardingTaskNotifier} owns every ping to a task's owner.
 * Tools are advertised only to users whose permissions allow them, and each
 * handler re-checks anyway.
 */
class OnboardingModule extends Module
{
    /** How many records a find_* tool returns at most. */
    private const FIND_LIMIT = 8;

    /** Statuses `find_onboarding_cases` accepts (the real four, plus "active"). */
    private const CASE_FILTERS = [...OnboardingCase::STATUSES, 'active'];

    public function __construct(private readonly OnboardingStatistics $statistics) {}

    public function key(): string
    {
        return 'onboarding';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('onboarding.view');
    }

    protected function toolMap(): array
    {
        return [
            // Cases.
            'find_onboarding_cases' => 'findCases',
            'start_onboarding' => 'startOnboarding',
            'update_onboarding_case' => 'updateCase',
            'set_onboarding_status' => 'setStatus',
            'delete_onboarding_case' => 'deleteCase',

            // Checklist.
            'find_onboarding_tasks' => 'findTasks',
            'add_onboarding_task' => 'addTask',
            'update_onboarding_task' => 'updateTask',
            'set_task_status' => 'setTaskStatus',
            'remove_onboarding_task' => 'removeTask',
            'nudge_onboarding_task' => 'nudgeTasks',

            // Programs (templates).
            'find_onboarding_programs' => 'findPrograms',
            'create_onboarding_program' => 'createProgram',
            'update_onboarding_program' => 'updateProgram',
            'delete_onboarding_program' => 'deleteProgram',

            // Decision support.
            'onboarding_summary' => 'summary',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'start_onboarding' => 'onboarding.manage',
            'update_onboarding_case' => 'onboarding.manage',
            'set_onboarding_status' => 'onboarding.manage',
            'delete_onboarding_case' => 'onboarding.manage',

            'add_onboarding_task' => 'onboarding.manage',
            'update_onboarding_task' => 'onboarding.manage',
            'set_task_status' => 'onboarding.manage',
            'remove_onboarding_task' => 'onboarding.manage',
            'nudge_onboarding_task' => 'onboarding.manage',

            'create_onboarding_program' => 'onboarding.manage-programs',
            'update_onboarding_program' => 'onboarding.manage-programs',
            'delete_onboarding_program' => 'onboarding.manage-programs',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $programs = OnboardingProgram::where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['name', 'is_default'])
            ->map(fn (OnboardingProgram $p): string => $p->name.($p->is_default ? ' (default)' : ''))
            ->implode(', ') ?: 'none';

        $categories = implode(', ', OnboardingTask::CATEGORIES);
        $taskStatuses = implode(', ', OnboardingTask::STATUSES);

        $lines = [
            "ONBOARDING — each new hire's journey: one case per employee (pending → in_progress → completed/cancelled) holding a checklist of dated tasks, seeded from a reusable program.",
            '- Reading: find_onboarding_cases (people being onboarded), find_onboarding_tasks (checklist items — filter by `overdue`, `category`, `status`, an assignee, or `mine` for the signed-in user), find_onboarding_programs.',
            "- onboarding_summary reads out how onboarding is going: org-wide, or one employee's progress (tasks done, overdue, target date, next task up).",
        ];

        if ($this->allows($user, 'onboarding.manage')) {
            $lines[] = '- start_onboarding begins a case for an employee, seeding tasks from a program. If no program is named, the best-matching active one is used. An employee can only ever have one case.';
            $lines[] = '- set_onboarding_status advances a case: complete, cancel, or reopen. update_onboarding_case sets the target completion date or notes. delete_onboarding_case removes the case and its checklist entirely.';
            $lines[] = "- Checklist: add_onboarding_task adds an ad-hoc item; update_onboarding_task edits one (this is also how you REASSIGN it or change its due date); set_task_status ticks it off ({$taskStatuses} — use `skipped` when it does not apply); remove_onboarding_task deletes it.";
            $lines[] = '- nudge_onboarding_task reminds the people responsible. Name a `task` to chase one item, or pass only the employee to chase every overdue item on their checklist. It really sends notifications — only on a clear request.';
            $lines[] = "  Task categories: {$categories}.";
        }

        if ($this->allows($user, 'onboarding.manage-programs')) {
            $lines[] = '- Programs are the templates new checklists come from (Company Setup). create_onboarding_program / update_onboarding_program take the FULL task list — each with a title, category and due_offset_days (days after the start date) — and replace the blueprint wholesale. Only one program can be the default.';
        }

        $lines[] = '- Pass `employee` as a name or employee number, `program` as a program name, and `task` as (part of) a checklist item title.';
        $lines[] = "  Programs: {$programs}";

        return implode("\n", $lines);
    }

    public function tools(User $user): array
    {
        $employeeArg = ['type' => 'STRING', 'description' => 'Employee name or employee number.'];
        $taskArg = ['type' => 'STRING', 'description' => 'Checklist item title (or part of it).'];
        $programArg = ['type' => 'STRING', 'description' => 'Program name.'];

        return $this->permitted($user, [
            // ── Cases ────────────────────────────────────────────────────────
            [
                'name' => 'find_onboarding_cases',
                'description' => 'List onboarding cases, optionally filtered by employee, status, department, overdue work, or how soon they are due to finish.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Employee name or number.'],
                        'status' => ['type' => 'STRING', 'enum' => self::CASE_FILTERS, 'description' => '"active" covers pending + in_progress.'],
                        'department' => ['type' => 'STRING', 'description' => 'Department name.'],
                        'overdue' => ['type' => 'BOOLEAN', 'description' => 'Only cases with at least one overdue task.'],
                        'due_within_days' => ['type' => 'INTEGER', 'description' => 'Only cases whose target completion date falls within this many days.'],
                    ],
                ],
            ],
            [
                'name' => 'start_onboarding',
                'description' => 'Start an onboarding case for an employee, seeding the checklist from a program.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employeeArg,
                        'program' => ['type' => 'STRING', 'description' => 'Program name; omit to use the best-matching active program.'],
                        'start_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD; defaults to the hire date.'],
                    ],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'update_onboarding_case',
                'description' => "Set an onboarding case's target completion date and/or notes.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employeeArg,
                        'target_end_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD'],
                        'notes' => ['type' => 'STRING'],
                    ],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'set_onboarding_status',
                'description' => "Advance an employee's onboarding case: complete, cancel, or reopen it.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employeeArg,
                        'action' => ['type' => 'STRING', 'enum' => OnboardingCase::LIFECYCLE_ACTIONS],
                    ],
                    'required' => ['employee', 'action'],
                ],
            ],
            [
                'name' => 'delete_onboarding_case',
                'description' => "Delete an employee's onboarding case and its whole checklist. Only on an explicit request.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employeeArg],
                    'required' => ['employee'],
                ],
            ],

            // ── Checklist ────────────────────────────────────────────────────
            [
                'name' => 'find_onboarding_tasks',
                'description' => 'List onboarding checklist items across cases — filter by employee, assignee, status, category, or whether they are overdue.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Whose checklist (name or employee number).'],
                        'query' => ['type' => 'STRING', 'description' => 'Text to match in the task title.'],
                        'assignee' => ['type' => 'STRING', 'description' => 'The responsible user’s name.'],
                        'mine' => ['type' => 'BOOLEAN', 'description' => 'Only tasks assigned to the signed-in user.'],
                        'status' => ['type' => 'STRING', 'enum' => OnboardingTask::STATUSES],
                        'category' => ['type' => 'STRING', 'enum' => OnboardingTask::CATEGORIES],
                        'overdue' => ['type' => 'BOOLEAN', 'description' => 'Only unresolved items past their due date.'],
                        'due_within_days' => ['type' => 'INTEGER', 'description' => 'Only unresolved items due within this many days.'],
                    ],
                ],
            ],
            [
                'name' => 'add_onboarding_task',
                'description' => "Add an ad-hoc item to an employee's onboarding checklist.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employeeArg,
                        'title' => ['type' => 'STRING'],
                        'category' => ['type' => 'STRING', 'enum' => OnboardingTask::CATEGORIES, 'description' => 'Defaults to other.'],
                        'description' => ['type' => 'STRING'],
                        'assignee' => ['type' => 'STRING', 'description' => 'The user responsible.'],
                        'due_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD'],
                    ],
                    'required' => ['employee', 'title'],
                ],
            ],
            [
                'name' => 'update_onboarding_task',
                'description' => 'Edit a checklist item — retitle it, recategorise it, reassign it, or change its due date. Only pass what changes.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employeeArg,
                        'task' => $taskArg,
                        'title' => ['type' => 'STRING', 'description' => 'A new title.'],
                        'category' => ['type' => 'STRING', 'enum' => OnboardingTask::CATEGORIES],
                        'description' => ['type' => 'STRING'],
                        'assignee' => ['type' => 'STRING', 'description' => 'Reassign to this user.'],
                        'due_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD'],
                    ],
                    'required' => ['employee', 'task'],
                ],
            ],
            [
                'name' => 'set_task_status',
                'description' => 'Tick a checklist item off (done), start it, put it back to pending, or skip it as not applicable.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employeeArg,
                        'task' => $taskArg,
                        'status' => ['type' => 'STRING', 'enum' => OnboardingTask::STATUSES],
                    ],
                    'required' => ['employee', 'task', 'status'],
                ],
            ],
            [
                'name' => 'remove_onboarding_task',
                'description' => "Delete an item from an employee's onboarding checklist.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employeeArg, 'task' => $taskArg],
                    'required' => ['employee', 'task'],
                ],
            ],
            [
                'name' => 'nudge_onboarding_task',
                'description' => 'Remind the people responsible about outstanding checklist items. Names one task, or every overdue item on an employee’s checklist. Sends real notifications.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employeeArg,
                        'task' => ['type' => 'STRING', 'description' => 'One item to chase; omit to chase all overdue items.'],
                    ],
                    'required' => ['employee'],
                ],
            ],

            // ── Programs ─────────────────────────────────────────────────────
            [
                'name' => 'find_onboarding_programs',
                'description' => 'List onboarding programs (the templates checklists are seeded from), with what they target and how many tasks they carry.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Program name or keyword.'],
                        'for_employee' => ['type' => 'STRING', 'description' => 'Show which program this employee would be onboarded with.'],
                        'include_inactive' => ['type' => 'BOOLEAN'],
                    ],
                ],
            ],
            [
                'name' => 'create_onboarding_program',
                'description' => 'Create an onboarding program (template) with its blueprint tasks.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => $this->programProperties(),
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'update_onboarding_program',
                'description' => 'Edit an onboarding program. Passing `tasks` REPLACES the whole blueprint; omit it to leave the tasks alone.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['program' => $programArg] + $this->programProperties(),
                    'required' => ['program'],
                ],
            ],
            [
                'name' => 'delete_onboarding_program',
                'description' => 'Delete an onboarding program. In-flight cases keep the tasks already seeded from it. Only on an explicit request.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['program' => $programArg],
                    'required' => ['program'],
                ],
            ],

            // ── Decision support ─────────────────────────────────────────────
            [
                'name' => 'onboarding_summary',
                'description' => "How onboarding is going: the org-wide picture, or one employee's progress (done / overdue / target date / the next task up).",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => ['type' => 'STRING', 'description' => 'Employee name or number; omit for the whole organisation.']],
                ],
            ],
        ]);
    }

    // ── Cases ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findCases(User $user, array $args): ToolResult
    {
        $query = trim((string) $this->firstFilled($args, ['query', 'employee', 'match']));
        $status = $this->normaliseCaseFilter($args['status'] ?? null);
        $department = $this->firstFilled($args, ['department', 'department_name']);
        $overdue = (bool) ($args['overdue'] ?? false);
        $withinDays = isset($args['due_within_days']) ? max(0, (int) $args['due_within_days']) : null;

        $cases = OnboardingCase::query()
            ->with(['employee.department', 'employee.position', 'program'])
            ->withCount([
                'tasks',
                'tasks as done_tasks_count' => fn (Builder $q) => $q->where('status', 'done'),
                'tasks as resolved_tasks_count' => fn (Builder $q) => $q->whereIn('status', OnboardingTask::RESOLVED_STATUSES),
                'tasks as overdue_tasks_count' => fn (Builder $q) => $q->overdue(),
            ])
            ->when($query !== '', fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $this->matchByTokens($e, $query)))
            ->when($status === 'active', fn (Builder $q) => $q->active())
            ->when($status !== null && $status !== 'active', fn (Builder $q) => $q->where('status', $status))
            ->when($department, fn (Builder $q) => $q->whereHas('employee.department', fn (Builder $d) => $d->whereRaw('lower(name) = ?', [mb_strtolower($department)])))
            ->when($overdue, fn (Builder $q) => $q->whereHas('tasks', fn (Builder $t) => $t->overdue()))
            ->when($withinDays !== null, fn (Builder $q) => $q
                ->whereNotNull('target_end_date')
                ->whereDate('target_end_date', '<=', now()->addDays($withinDays)->toDateString()))
            ->latest()
            ->limit(self::FIND_LIMIT)
            ->get();

        $cards = $cases->map(fn (OnboardingCase $c): array => $this->caseCard($c, 'find', 'neutral', $this->label($c->status)))->all();

        $label = match (true) {
            $overdue => 'Looked for onboarding that has slipped',
            $query !== '' => "Searched onboarding for “{$query}”",
            default => 'Listed onboarding cases',
        };

        return ToolResult::found($label, count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function startOnboarding(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage')) {
            return $this->denied('start onboarding');
        }

        $employee = $this->locateEmployee($args);
        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        if ($employee->onboardingCase()->exists()) {
            return ToolResult::error("Checked {$employee->full_name}", 'That employee is already being onboarded.');
        }

        $program = null;
        $programName = $this->firstFilled($args, ['program', 'program_name']);

        if ($programName !== null) {
            $program = $this->locateProgram($args);

            if (! $program) {
                return ToolResult::error("Looked up “{$programName}”", 'No onboarding program by that name — use one of the programs I listed.');
            }
        }

        $startDate = $this->date($args['start_date'] ?? null);

        $case = OnboardingProvisioner::start($employee, $program, $startDate ? Carbon::parse($startDate) : null);
        $case->setRelation('employee', $employee)->setRelation('program', $program ?? $case->program);

        $this->log('created', "Started onboarding for {$employee->full_name} via assistant", $case, $employee->full_name, ['program' => $case->program?->name]);

        return ToolResult::ok(
            "Started onboarding for {$employee->full_name}",
            $case->program?->name,
            $this->caseCard($case->loadCount('tasks'), 'start', 'positive', 'Started'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateCase(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage')) {
            return $this->denied('manage onboarding');
        }

        [$case, $error] = $this->requireCase($args);
        if ($error) {
            return $error;
        }

        $changes = array_filter([
            'target_end_date' => $this->date($args['target_end_date'] ?? null),
            'notes' => $this->firstFilled($args, ['notes', 'note']),
        ], fn ($value): bool => $value !== null);

        if ($changes === []) {
            return ToolResult::error('Updated onboarding', 'Tell me what to set — a target completion date, or notes.');
        }

        $validator = Validator::make($changes, [
            'target_end_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validated onboarding', $validator->errors()->first());
        }

        $case->update($validator->validated());

        $name = $case->employee->full_name;
        $this->log('updated', "Updated onboarding for {$name} via assistant", $case, $name, $changes);

        return ToolResult::ok(
            "Updated onboarding for {$name}",
            isset($changes['target_end_date']) ? 'Target '.Carbon::parse($changes['target_end_date'])->format('M j, Y') : 'Notes saved',
            $this->caseCard($case->fresh(['employee', 'program']), 'edit', 'info', $this->label($case->status)),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setStatus(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage')) {
            return $this->denied('manage onboarding');
        }

        $action = strtolower(trim((string) ($args['action'] ?? '')));
        if (! in_array($action, OnboardingCase::LIFECYCLE_ACTIONS, true)) {
            return ToolResult::error('Updated onboarding', 'Action must be '.implode(', ', OnboardingCase::LIFECYCLE_ACTIONS).'.');
        }

        [$case, $error] = $this->requireCase($args);
        if ($error) {
            return $error;
        }

        $name = $case->employee->full_name;

        if ($action === 'complete') {
            $progress = $case->progressSummary();
            $outstanding = $progress['total'] - $progress['resolved'];

            if ($outstanding > 0) {
                // Not a refusal — HR may legitimately close a case early — but the
                // reply says what was left, so nobody discovers it later.
                $this->log('updated', "Completed onboarding for {$name} with {$outstanding} task(s) outstanding, via assistant", $case, $name);
            }
        }

        $case->applyLifecycle($action);

        $this->log('updated', ucfirst($action).'d onboarding for '.$name.' via assistant', $case, $name);

        $fresh = $case->fresh(['employee', 'program']);
        $progress = $fresh->progressSummary();
        $outstanding = $progress['total'] - $progress['resolved'];

        return ToolResult::ok(
            "Onboarding {$fresh->status} for {$name}",
            $action === 'complete' && $outstanding > 0
                ? "{$outstanding} task".($outstanding === 1 ? '' : 's').' left unresolved'
                : null,
            $this->caseCard(
                $fresh,
                match ($action) {
                    'complete' => 'approve',
                    'cancel' => 'archive',
                    default => 'edit',
                },
                match ($action) {
                    'complete' => 'positive',
                    'cancel' => 'warning',
                    default => 'info',
                },
                $this->label($fresh->status),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function deleteCase(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage')) {
            return $this->denied('manage onboarding');
        }

        [$case, $error] = $this->requireCase($args);
        if ($error) {
            return $error;
        }

        $name = $case->employee->full_name;
        $card = $this->caseCard($case, 'archive', 'danger', 'Removed');

        $case->delete();

        $this->log('deleted', "Deleted onboarding for {$name} via assistant", null, $name);

        return ToolResult::ok("Removed onboarding for {$name}", null, $card);
    }

    // ── Checklist ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findTasks(User $user, array $args): ToolResult
    {
        $employeeNeedle = $this->firstFilled($args, ['employee', 'employee_name']);
        $query = trim((string) $this->firstFilled($args, ['query', 'task', 'title']));
        $assignee = $this->firstFilled($args, ['assignee', 'assigned_to', 'owner']);
        $mine = (bool) ($args['mine'] ?? false);
        $status = $this->normalise($args['status'] ?? null, OnboardingTask::STATUSES);
        $category = $this->normalise($args['category'] ?? null, OnboardingTask::CATEGORIES);
        $overdue = (bool) ($args['overdue'] ?? false);
        $withinDays = isset($args['due_within_days']) ? max(0, (int) $args['due_within_days']) : null;

        $tasks = OnboardingTask::query()
            ->with(['case.employee', 'assignee'])
            ->when($employeeNeedle, fn (Builder $q) => $q->whereHas('case.employee', fn (Builder $e) => $this->matchByTokens($e, $employeeNeedle)))
            ->when($query !== '', fn (Builder $q) => $q->search($query))
            ->when($mine, fn (Builder $q) => $q->where('assigned_to', $user->id))
            ->when(! $mine && $assignee, fn (Builder $q) => $q->whereHas('assignee', fn (Builder $u) => $this->matchByTokens($u, $assignee)))
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($category, fn (Builder $q) => $q->where('category', $category))
            ->when($overdue, fn (Builder $q) => $q->overdue()->onActiveCase())
            ->when($withinDays !== null, fn (Builder $q) => $q->unresolved()
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', now()->addDays($withinDays)->toDateString()))
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('sort_order')
            ->limit(self::FIND_LIMIT)
            ->get();

        $cards = $tasks->map(fn (OnboardingTask $t): array => $this->taskCard($t, 'find', $t->isOverdue() ? 'warning' : 'neutral', $this->label($t->status)))->all();

        $label = match (true) {
            $mine => 'Listed your onboarding tasks',
            $overdue => 'Looked for overdue onboarding tasks',
            $employeeNeedle !== null => "Listed the checklist for “{$employeeNeedle}”",
            default => 'Listed onboarding tasks',
        };

        return ToolResult::found($label, count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function addTask(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage')) {
            return $this->denied('manage onboarding checklists');
        }

        [$case, $error] = $this->requireCase($args);
        if ($error) {
            return $error;
        }

        $assignee = $this->resolveAssignee($args);
        if ($assignee instanceof ToolResult) {
            return $assignee;
        }

        $data = [
            'title' => trim((string) $this->firstFilled($args, ['title', 'task'])),
            'description' => $args['description'] ?? null,
            'category' => $this->normalise($args['category'] ?? null, OnboardingTask::CATEGORIES) ?? 'other',
            'assigned_to' => $assignee,
            'due_date' => $this->date($args['due_date'] ?? null),
        ];

        $validator = Validator::make($data, (new StoreOnboardingTaskRequest)->rules());

        if ($validator->fails()) {
            return ToolResult::error('Validated the task', $validator->errors()->first());
        }

        $task = $case->tasks()->create([
            ...$validator->validated(),
            'status' => 'pending',
            'sort_order' => (int) $case->tasks()->max('sort_order') + 1,
        ]);

        $case->touchProgress();
        OnboardingTaskNotifier::assigned($task, null, $user);

        $name = $case->employee->full_name;
        $this->log('created', "Added onboarding task \"{$task->title}\" for {$name} via assistant", $case, $name);

        $task->setRelation('case', $case);

        return ToolResult::ok(
            "Added “{$task->title}” to {$name}'s checklist",
            $task->due_date?->format('M j, Y'),
            $this->taskCard($task->load('assignee'), 'add', 'positive', 'Added'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateTask(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage')) {
            return $this->denied('manage onboarding checklists');
        }

        [$task, $error] = $this->requireTask($args);
        if ($error) {
            return $error;
        }

        $assignee = $this->resolveAssignee($args);
        if ($assignee instanceof ToolResult) {
            return $assignee;
        }

        $changes = array_filter([
            'title' => $this->firstFilled($args, ['title', 'new_title']),
            'description' => $args['description'] ?? null,
            'category' => $this->normalise($args['category'] ?? null, OnboardingTask::CATEGORIES),
            'assigned_to' => $assignee,
            'due_date' => $this->date($args['due_date'] ?? null),
        ], fn ($value): bool => $value !== null);

        // `task` is the lookup key, so re-sending the same title is not an edit.
        if (($changes['title'] ?? null) === $task->title) {
            unset($changes['title']);
        }

        if ($changes === []) {
            return ToolResult::error("Checked “{$task->title}”", 'Tell me what to change — the title, category, assignee, due date, or description.');
        }

        $validator = Validator::make(
            $changes,
            collect((new StoreOnboardingTaskRequest)->rules())->only(array_keys($changes))->all(),
        );

        if ($validator->fails()) {
            return ToolResult::error('Validated the task', $validator->errors()->first());
        }

        $previousAssignee = $task->assigned_to;
        $task->update($validator->validated());

        OnboardingTaskNotifier::assigned($task, $previousAssignee, $user);

        $name = $task->case?->employee?->full_name ?? 'the employee';
        $this->log('updated', "Updated onboarding task \"{$task->title}\" for {$name} via assistant", $task->case, $name, $changes);

        return ToolResult::ok(
            "Updated “{$task->title}”",
            isset($changes['assigned_to']) ? 'Reassigned' : implode(', ', array_keys($changes)),
            $this->taskCard($task->fresh(['case.employee', 'assignee']), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setTaskStatus(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage')) {
            return $this->denied('manage onboarding checklists');
        }

        $status = $this->normalise($args['status'] ?? null, OnboardingTask::STATUSES);
        if ($status === null) {
            return ToolResult::error('Updated the task', 'Status must be one of: '.implode(', ', OnboardingTask::STATUSES).'.');
        }

        [$task, $error] = $this->requireTask($args);
        if ($error) {
            return $error;
        }

        $task->markStatus($status, $user->id);
        $task->case?->touchProgress();

        $name = $task->case?->employee?->full_name ?? 'the employee';
        $this->log('updated', "Marked \"{$task->title}\" {$status} for {$name} via assistant", $task->case, $name, ['status' => $status]);

        [$kind, $tone] = match ($status) {
            'done' => ['approve', 'positive'],
            'skipped' => ['cancel', 'warning'],
            'in_progress' => ['move', 'info'],
            default => ['edit', 'neutral'],
        };

        $progress = $task->case?->fresh()->progressSummary();

        return ToolResult::ok(
            "Marked “{$task->title}” {$this->label($status)}",
            $progress ? "{$progress['resolved']} of {$progress['total']} done" : null,
            $this->taskCard($task->fresh(['case.employee', 'assignee']), $kind, $tone, $this->label($status)),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function removeTask(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage')) {
            return $this->denied('manage onboarding checklists');
        }

        [$task, $error] = $this->requireTask($args);
        if ($error) {
            return $error;
        }

        $title = $task->title;
        $case = $task->case;
        $name = $case?->employee?->full_name ?? 'the employee';
        $card = $this->taskCard($task, 'archive', 'danger', 'Removed');

        $task->delete();

        $this->log('deleted', "Removed onboarding task \"{$title}\" for {$name} via assistant", $case, $name);

        return ToolResult::ok("Removed “{$title}” from {$name}'s checklist", null, $card);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function nudgeTasks(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage')) {
            return $this->denied('manage onboarding checklists');
        }

        [$case, $error] = $this->requireCase($args);
        if ($error) {
            return $error;
        }

        $name = $case->employee->full_name;

        // One named item, or everything on this checklist that has slipped.
        if ($this->firstFilled($args, ['task', 'title']) !== null) {
            [$task, $taskError] = $this->requireTask($args);
            if ($taskError) {
                return $taskError;
            }

            if ($task->assigned_to === null) {
                return ToolResult::error("Checked “{$task->title}”", 'Nobody is assigned to that task yet — assign it first and I can chase them.');
            }

            if ($task->isResolved()) {
                return ToolResult::error("Checked “{$task->title}”", 'That task is already '.$this->label($task->status).'.');
            }

            OnboardingTaskNotifier::nudge($task, $user);

            $this->log('updated', "Reminded the owner of \"{$task->title}\" for {$name} via assistant", $case, $name);

            return ToolResult::ok(
                "Reminded {$task->assignee?->full_name} about “{$task->title}”",
                $task->due_date?->format('M j, Y'),
                $this->taskCard($task->fresh(['case.employee', 'assignee']), 'remind', 'warning', 'Reminded'),
            );
        }

        $overdue = $case->tasks()->overdue()->whereNotNull('assigned_to')->with('assignee')->get();

        if ($overdue->isEmpty()) {
            return ToolResult::error("Checked {$name}'s checklist", 'Nothing is overdue and assigned — there is nobody to chase.');
        }

        $overdue->each(fn (OnboardingTask $task) => $task->setRelation('case', $case));
        $reminded = OnboardingTaskNotifier::nudgeMany($overdue, $user);

        $this->log('updated', "Reminded {$reminded} owner(s) about {$overdue->count()} overdue task(s) for {$name} via assistant", $case, $name);

        return ToolResult::ok(
            "Reminded {$reminded} ".($reminded === 1 ? 'person' : 'people')." about {$name}'s overdue tasks",
            $overdue->count().' task'.($overdue->count() === 1 ? '' : 's'),
            $this->card(
                kind: 'remind',
                tone: 'warning',
                badge: 'Reminded',
                title: $name,
                subtitle: $overdue->count().' overdue task'.($overdue->count() === 1 ? '' : 's').' chased',
                meta: $overdue->take(3)->pluck('title')->all(),
                avatar: $this->avatarFor($case->employee),
                id: $case->id,
            ),
        );
    }

    // ── Programs ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findPrograms(User $user, array $args): ToolResult
    {
        $query = trim((string) $this->firstFilled($args, ['query', 'program', 'name']));
        $forEmployee = $this->firstFilled($args, ['for_employee', 'employee']);

        // "Which program would this hire get?" is answered by the same resolver the
        // hire bridge uses, so the preview can never disagree with reality.
        if ($forEmployee !== null) {
            $employee = $this->locateEmployee(['employee' => $forEmployee]);

            if (! $employee) {
                return ToolResult::error('Looked up the employee', 'No matching employee found.');
            }

            $match = OnboardingProvisioner::programFor($employee);

            if (! $match) {
                return ToolResult::error("Checked {$employee->full_name}", 'No active program matches them — their checklist would start empty.');
            }

            return ToolResult::found(
                "Matched a program for {$employee->full_name}",
                $match->name,
                [$this->programCard($match->loadCount(['tasks', 'cases']), 'insight', 'info', 'Best match')],
            );
        }

        $programs = OnboardingProgram::query()
            ->with('department')
            ->withCount(['tasks', 'cases'])
            ->when($query !== '', fn (Builder $q) => $q->search($query))
            ->when(! ($args['include_inactive'] ?? false), fn (Builder $q) => $q->where('is_active', true))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->limit(self::FIND_LIMIT)
            ->get();

        $cards = $programs->map(fn (OnboardingProgram $p): array => $this->programCard($p, 'find', 'neutral', $p->is_default ? 'Default' : ($p->is_active ? 'Active' : 'Inactive')))->all();

        return ToolResult::found(
            $query !== '' ? "Searched programs for “{$query}”" : 'Listed onboarding programs',
            count($cards).' found',
            $cards,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function createProgram(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage-programs')) {
            return $this->denied('manage onboarding programs');
        }

        $department = $this->resolveDepartment($args);
        if ($department instanceof ToolResult) {
            return $department;
        }

        $data = [
            'name' => trim((string) ($args['name'] ?? '')),
            'description' => $args['description'] ?? null,
            'department_id' => $department,
            'employment_type' => $this->normalise($args['employment_type'] ?? null, StoreOnboardingProgramRequest::EMPLOYMENT_TYPES),
            'is_default' => (bool) ($args['is_default'] ?? false),
            'is_active' => (bool) ($args['is_active'] ?? true),
            'tasks' => $this->blueprint($args['tasks'] ?? null) ?? [],
        ];

        $validator = Validator::make($data, (new StoreOnboardingProgramRequest)->rules());

        if ($validator->fails()) {
            return ToolResult::error('Validated the program', $validator->errors()->first());
        }

        $validated = $validator->validated();

        $program = DB::transaction(function () use ($validated): OnboardingProgram {
            $program = OnboardingProgram::create(collect($validated)->except('tasks')->all());
            $program->enforceSingleDefault();
            $program->syncBlueprint($validated['tasks'] ?? []);

            return $program;
        });

        $this->log('created', "Created onboarding program \"{$program->name}\" via assistant", $program, $program->name);

        return ToolResult::ok(
            "Created program “{$program->name}”",
            count($validated['tasks'] ?? []).' task'.(count($validated['tasks'] ?? []) === 1 ? '' : 's'),
            $this->programCard($program->load('department')->loadCount(['tasks', 'cases']), 'post', 'positive', 'Created'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateProgram(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage-programs')) {
            return $this->denied('manage onboarding programs');
        }

        $program = $this->locateProgram($args);
        if (! $program) {
            return ToolResult::error('Looked up the program', 'No matching onboarding program found.');
        }

        $data = [
            'name' => $this->firstFilled($args, ['name', 'new_name']) ?? $program->name,
            'description' => array_key_exists('description', $args) ? $args['description'] : $program->description,
            'department_id' => $program->department_id,
            'employment_type' => array_key_exists('employment_type', $args)
                ? $this->normalise($args['employment_type'], StoreOnboardingProgramRequest::EMPLOYMENT_TYPES)
                : $program->employment_type,
            'is_default' => array_key_exists('is_default', $args) ? (bool) $args['is_default'] : $program->is_default,
            'is_active' => array_key_exists('is_active', $args) ? (bool) $args['is_active'] : $program->is_active,
        ];

        if (filled($args['department'] ?? null)) {
            $department = $this->resolveDepartment($args);
            if ($department instanceof ToolResult) {
                return $department;
            }
            $data['department_id'] = $department;
        }

        // Only a supplied task list touches the blueprint; an omitted one leaves
        // the existing template alone rather than silently emptying it.
        $blueprint = $this->blueprint($args['tasks'] ?? null);
        $rules = collect((new StoreOnboardingProgramRequest)->rules());

        $validator = Validator::make(
            $blueprint === null ? $data : [...$data, 'tasks' => $blueprint],
            $blueprint === null ? $rules->reject(fn ($rule, $key): bool => str_starts_with($key, 'tasks'))->all() : $rules->all(),
        );

        if ($validator->fails()) {
            return ToolResult::error('Validated the program', $validator->errors()->first());
        }

        $validated = $validator->validated();

        DB::transaction(function () use ($program, $validated, $blueprint): void {
            $program->update(collect($validated)->except('tasks')->all());
            $program->enforceSingleDefault();

            if ($blueprint !== null) {
                $program->syncBlueprint($validated['tasks'] ?? []);
            }
        });

        $this->log('updated', "Updated onboarding program \"{$program->name}\" via assistant", $program, $program->name);

        return ToolResult::ok(
            "Updated program “{$program->name}”",
            $blueprint !== null ? count($blueprint).' task'.(count($blueprint) === 1 ? '' : 's').' in the blueprint' : null,
            $this->programCard($program->fresh(['department'])->loadCount(['tasks', 'cases']), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function deleteProgram(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage-programs')) {
            return $this->denied('manage onboarding programs');
        }

        $program = $this->locateProgram($args);
        if (! $program) {
            return ToolResult::error('Looked up the program', 'No matching onboarding program found.');
        }

        $name = $program->name;
        $card = $this->programCard($program->load('department')->loadCount(['tasks', 'cases']), 'archive', 'danger', 'Deleted');

        $program->delete();

        $this->log('deleted', "Deleted onboarding program \"{$name}\" via assistant", null, $name);

        return ToolResult::ok("Deleted program “{$name}”", 'In-flight checklists keep their tasks', $card);
    }

    // ── Decision support ─────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function summary(User $user, array $args): ToolResult
    {
        $needle = $this->firstFilled($args, ['employee', 'employee_name', 'name']);

        if ($needle === null) {
            return $this->organisationSummary();
        }

        [$case, $error] = $this->requireCase($args);
        if ($error) {
            return $error;
        }

        $case->load(['employee', 'program', 'tasks.assignee']);
        $progress = $case->progressSummary();
        $name = $case->employee->full_name;

        // Soonest due first; undated items fall to the back but keep checklist order.
        $next = $case->tasks
            ->reject(fn (OnboardingTask $task): bool => $task->isResolved())
            ->sortBy(fn (OnboardingTask $task): int => $task->due_date?->timestamp ?? PHP_INT_MAX)
            ->first();

        $daysToTarget = $case->target_end_date !== null
            ? (int) now()->startOfDay()->diffInDays($case->target_end_date->startOfDay(), false)
            : null;

        $meta = [
            "{$progress['resolved']} of {$progress['total']} done ({$progress['percent']}%)",
            $progress['overdue'] > 0 ? "{$progress['overdue']} overdue" : 'nothing overdue',
            match (true) {
                $daysToTarget === null => null,
                $daysToTarget < 0 => 'target passed '.abs($daysToTarget).'d ago',
                $daysToTarget === 0 => 'target is today',
                default => "target in {$daysToTarget}d",
            },
            $next ? 'Next: '.$next->title.($next->assignee ? ' ('.$next->assignee->full_name.')' : '') : null,
            $case->program?->name,
        ];

        return ToolResult::found(
            "Reviewed {$name}'s onboarding",
            "{$progress['percent']}% complete",
            [$this->card(
                kind: 'insight',
                tone: match (true) {
                    $progress['overdue'] > 0 => 'warning',
                    $progress['percent'] >= 100 => 'positive',
                    default => 'info',
                },
                badge: $this->label($case->status),
                title: $name,
                subtitle: "{$progress['resolved']} of {$progress['total']} tasks done · {$progress['percent']}% complete",
                meta: array_values(array_filter($meta, fn ($m): bool => filled($m))),
                avatar: $this->avatarFor($case->employee),
                id: $case->id,
            )],
        );
    }

    /**
     * The org-wide picture, from the same statistics the board shows.
     */
    private function organisationSummary(): ToolResult
    {
        $stats = $this->statistics->toArray();
        $unassigned = OnboardingTask::query()->unresolved()->onActiveCase()->whereNull('assigned_to')->count();

        $subtitle = $stats['active'].' active case'.($stats['active'] === 1 ? '' : 's')
            .', '.$stats['overdue_tasks'].' overdue task'.($stats['overdue_tasks'] === 1 ? '' : 's');

        $meta = [
            $stats['completing_soon'].' finishing within a week',
            $stats['completed_this_month'].' completed this month',
            $unassigned > 0 ? "{$unassigned} unassigned task".($unassigned === 1 ? '' : 's') : null,
        ];

        return ToolResult::found(
            'Summarised onboarding',
            $subtitle,
            [$this->card(
                kind: 'insight',
                tone: $stats['overdue_tasks'] > 0 ? 'warning' : 'info',
                badge: 'Overview',
                title: 'Onboarding overview',
                subtitle: $subtitle,
                meta: array_values(array_filter($meta, fn ($m): bool => filled($m))),
            )],
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function locateEmployee(array $args): ?Employee
    {
        $needle = $this->firstFilled($args, ['employee', 'match', 'employee_name', 'name']);

        return $needle !== null ? $this->matchByTokens(Employee::query(), $needle)->first() : null;
    }

    /**
     * Resolve the employee *and* their onboarding case in one go, returning the
     * right error result when either is missing. An employee has at most one case.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: ?OnboardingCase, 1: ?ToolResult}
     */
    private function requireCase(array $args): array
    {
        $employee = $this->locateEmployee($args);

        if (! $employee) {
            return [null, ToolResult::error('Looked up the employee', 'No matching employee found.')];
        }

        $case = OnboardingCase::query()
            ->with(['employee', 'program'])
            ->where('employee_id', $employee->id)
            ->latest()
            ->first();

        if (! $case) {
            return [null, ToolResult::error("Checked {$employee->full_name}", 'That employee has no onboarding case.')];
        }

        return [$case, null];
    }

    /**
     * Resolve one checklist item on an employee's case. Unresolved items win over
     * ticked-off ones, so "mark the laptop task done" targets the open one.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: ?OnboardingTask, 1: ?ToolResult}
     */
    private function requireTask(array $args): array
    {
        [$case, $error] = $this->requireCase($args);

        if ($error) {
            return [null, $error];
        }

        $needle = $this->firstFilled($args, ['task', 'task_title', 'title']);

        if ($needle === null) {
            return [null, ToolResult::error('Looked up the task', 'Tell me which checklist item you mean.')];
        }

        // Queried off the model rather than the relation: `tasks()` carries its own
        // display ordering, which would win over the unresolved-first preference.
        $task = OnboardingTask::query()
            ->with(['case.employee', 'assignee'])
            ->where('onboarding_case_id', $case->id)
            ->search($needle)
            ->orderByRaw('case when status in (?, ?) then 1 else 0 end', OnboardingTask::RESOLVED_STATUSES)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (! $task) {
            return [null, ToolResult::error("Searched {$case->employee->full_name}'s checklist", "No task matching “{$needle}”.")];
        }

        return [$task, null];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function locateProgram(array $args): ?OnboardingProgram
    {
        $needle = $this->firstFilled($args, ['program', 'program_name']);

        if ($needle === null) {
            return null;
        }

        return OnboardingProgram::query()->whereRaw('lower(name) = ?', [mb_strtolower($needle)])->first()
            ?? OnboardingProgram::query()->search($needle)->orderByDesc('is_active')->first();
    }

    /**
     * Resolve an assignee name to a user id: null when not asked for, an error
     * result when asked for but unknown — a typo must never silently unassign.
     *
     * @param  array<string, mixed>  $args
     */
    private function resolveAssignee(array $args): int|ToolResult|null
    {
        $name = $this->firstFilled($args, ['assignee', 'assigned_to', 'owner']);

        if ($name === null) {
            return null;
        }

        $id = $this->matchByTokens(User::query()->where('is_active', true), $name)->value('id');

        return $id !== null
            ? (int) $id
            : ToolResult::error("Looked up “{$name}”", 'No active user by that name to assign the task to.');
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resolveDepartment(array $args): int|ToolResult|null
    {
        $name = $this->firstFilled($args, ['department', 'department_name']);

        if ($name === null) {
            return null;
        }

        $id = $this->resolveId(Department::query(), 'name', $name);

        return $id ?? ToolResult::error("Looked up “{$name}”", 'No department by that name.');
    }

    /**
     * Normalise a blueprint task list from the model. Returns null when none was
     * supplied (leave the template alone) and [] when an empty list was.
     *
     * @return list<array<string, mixed>>|null
     */
    private function blueprint(mixed $tasks): ?array
    {
        if (! is_array($tasks)) {
            return null;
        }

        $blueprint = [];

        foreach ($tasks as $task) {
            if (! is_array($task) || blank($task['title'] ?? null)) {
                continue;
            }

            $blueprint[] = [
                'title' => trim((string) $task['title']),
                'description' => $task['description'] ?? null,
                'category' => $this->normalise($task['category'] ?? null, OnboardingTask::CATEGORIES) ?? 'other',
                'due_offset_days' => max(0, (int) ($task['due_offset_days'] ?? 0)),
            ];
        }

        return $blueprint;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalise(mixed $value, array $allowed): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function normaliseCaseFilter(mixed $status): ?string
    {
        return $this->normalise($status, self::CASE_FILTERS);
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        try {
            return $value === '' ? null : Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Human label for a snake_case status.
     */
    private function label(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * The Gemini properties shared by creating and editing a program.
     *
     * @return array<string, array<string, mixed>>
     */
    private function programProperties(): array
    {
        return [
            'name' => ['type' => 'STRING'],
            'description' => ['type' => 'STRING'],
            'department' => ['type' => 'STRING', 'description' => 'Target this program at one department.'],
            'employment_type' => ['type' => 'STRING', 'enum' => StoreOnboardingProgramRequest::EMPLOYMENT_TYPES, 'description' => 'Target this program at one employment type.'],
            'is_default' => ['type' => 'BOOLEAN', 'description' => 'Make this the tenant default (only one can be).'],
            'is_active' => ['type' => 'BOOLEAN'],
            'tasks' => [
                'type' => 'ARRAY',
                'description' => 'The full blueprint checklist; replaces any existing one.',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'title' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                        'category' => ['type' => 'STRING', 'enum' => OnboardingTask::CATEGORIES],
                        'due_offset_days' => ['type' => 'INTEGER', 'description' => 'Days after the start date.'],
                    ],
                    'required' => ['title', 'category', 'due_offset_days'],
                ],
            ],
        ];
    }

    /**
     * Record an onboarding mutation, tagged so the audit trail shows the assistant
     * did it.
     *
     * @param  array<string, mixed>  $properties
     */
    private function log(string $event, string $description, ?Model $subject, string $label, array $properties = []): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $subject,
            properties: $properties,
            logName: 'onboarding',
            subjectLabel: $label,
        );
    }

    // ── Cards ────────────────────────────────────────────────────────────────

    /**
     * @return array{name: string, initials: string, photo: ?string}|null
     */
    private function avatarFor(?Employee $employee): ?array
    {
        return $employee
            ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function caseCard(OnboardingCase $case, string $kind, string $tone, string $badge): array
    {
        $employee = $case->employee;
        $progress = $case->progressSummary();

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $employee?->full_name ?? 'Employee',
            subtitle: $case->program?->name ?? 'Onboarding',
            meta: [
                $this->label($case->status),
                $progress['total'] > 0 ? "{$progress['resolved']}/{$progress['total']} done" : 'no tasks',
                $progress['overdue'] > 0 ? "{$progress['overdue']} overdue" : null,
                $case->target_end_date?->format('M j, Y'),
            ],
            avatar: $this->avatarFor($employee),
            id: $case->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taskCard(OnboardingTask $task, string $kind, string $tone, string $badge): array
    {
        $employee = $task->case?->employee;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $task->title,
            subtitle: $employee?->full_name,
            meta: [
                ucfirst((string) $task->category),
                $task->assignee?->full_name,
                match (true) {
                    $task->due_date === null => null,
                    $task->isOverdue() => 'overdue '.$task->due_date->format('M j'),
                    default => 'due '.$task->due_date->format('M j'),
                },
            ],
            avatar: $this->avatarFor($employee),
            id: $task->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function programCard(OnboardingProgram $program, string $kind, string $tone, string $badge): array
    {
        $target = collect([
            $program->department?->name,
            $program->employment_type ? ucfirst(str_replace('_', ' ', $program->employment_type)) : null,
        ])->filter()->implode(' · ');

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $program->name,
            subtitle: $target !== '' ? "For {$target}" : 'For everyone',
            meta: [
                ($program->tasks_count ?? 0).' task'.(($program->tasks_count ?? 0) === 1 ? '' : 's'),
                isset($program->cases_count) ? $program->cases_count.' case'.($program->cases_count === 1 ? '' : 's') : null,
                $program->is_default ? 'default' : null,
                $program->is_active ? null : 'inactive',
            ],
            id: $program->id,
        );
    }
}
