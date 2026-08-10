<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\Employees\EmployeeDisclosure;
use App\Support\Employees\EmployeeNumbers;
use App\Support\Tenancy;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Employee-directory capability: answer questions about the workforce, and
 * create, update and archive people — including extraction from an attached CV.
 * Mirrors the manual UI's validation, tenant scoping and activity logging.
 *
 * The read half is the retrieval this assistant runs on: each question is
 * answered by a live, tenant-scoped, permission-checked query rather than from
 * an index built ahead of time, so an answer can never be staler than the
 * database and can never span organisations. Three rules hold it together:
 *
 * - **Tenancy is asserted, not assumed.** {@see OrganizationScope} is a no-op
 *   when no organisation is bound, so every tool here refuses outright rather
 *   than running a query that would see the whole instance.
 * - **Fields are a deny-list.** Reads project through
 *   {@see EmployeeDisclosure}; statutory numbers, bank details, pay, home
 *   address and date of birth never reach the model.
 * - **Bulk stays coarse.** List reads are capped and carry no contact details,
 *   so the cheapest way to drain the directory is not through here.
 */
class EmployeeModule extends Module
{
    public function key(): string
    {
        return 'employees';
    }

    public function isAvailable(User $user): bool
    {
        // Anyone whose own roster line exists may ask about themselves, even
        // with no directory permission at all — "when do I regularise?" is a
        // question about the asker, not about the workforce.
        return $user->can('employees.view') || $user->employee()->exists();
    }

    protected function toolMap(): array
    {
        return [
            'find_employees' => 'findEmployees',
            'get_employee_profile' => 'getEmployeeProfile',
            'list_employees' => 'listEmployees',
            'count_employees' => 'countEmployees',
            'list_direct_reports' => 'listDirectReports',
            'get_my_employee_record' => 'getMyEmployeeRecord',
            'create_employee' => 'createEmployee',
            'update_employee' => 'updateEmployee',
            'archive_employee' => 'archiveEmployee',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_employees' => 'employees.view',
            'get_employee_profile' => 'employees.view',
            'list_employees' => 'employees.view',
            'count_employees' => 'employees.view',
            'list_direct_reports' => 'employees.view',
            'create_employee' => 'employees.create',
            'update_employee' => 'employees.update',
            'archive_employee' => 'employees.delete',
            // get_my_employee_record is deliberately absent: it needs no
            // permission because it can only ever return the caller's own row.
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        // Isolation lives in a global scope that switches itself off when no
        // organisation is bound. Nothing in this module may run in that state.
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is active, so I can\'t look anything up.');
        }

        $permission = $this->permissionMap()[$tool] ?? null;

        if ($permission !== null && $user->cannot($permission)) {
            return $this->denied($this->actionFor($tool));
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        if (! $this->allows($user, 'employees.view')) {
            return <<<'TXT'
            EMPLOYEES — you can only see the signed-in user's own record here. Use get_my_employee_record for questions about their own employment (department, manager, schedule, hire date, tenure, regularisation). You cannot look up anybody else; say so plainly if asked.
            TXT;
        }

        $departments = Department::orderBy('name')->get(['id', 'name'])
            ->map(fn (Department $d): string => "#{$d->id} {$d->name}")->implode(', ') ?: 'none';
        $positions = Position::orderBy('title')->get(['id', 'title'])
            ->map(fn (Position $p): string => "#{$p->id} {$p->title}")->implode(', ') ?: 'none';
        $schedules = WorkSchedule::orderBy('name')->get(['id', 'name'])
            ->map(fn (WorkSchedule $s): string => "#{$s->id} {$s->name}")->implode(', ') ?: 'none';

        return <<<TXT
        EMPLOYEES — the staff directory: answer questions about the workforce, and create, update and archive people (including extracting details from an attached CV/resume).
        - Answering questions: count_employees for "how many / what's the headcount / breakdown by department"; list_employees for "who is …" with filters; get_employee_profile for one named person's details; list_direct_reports for "who reports to X"; get_my_employee_record for the signed-in user's own record.
        - The directory will not tell you anyone's salary, government ID numbers (TIN/SSS/PhilHealth/Pag-IBIG), bank details, home address or date of birth. Never guess these and never say you can retrieve them — say they aren't available through chat and point to the employee's 201 file.
        - When adding from one or more CVs, call create_employee once per person — never merge two people into one record. Use defaults when missing: date_hired = today, employment_type = probationary, employment_status = active.
        - To update or archive someone, pass the name/number as `match`. Never guess an id.
        - Prefer ids from these catalogs; if you only know a label, pass department_name / position_title / manager_name / work_schedule_name and the system resolves it. If a label is not in the catalog, leave it unset.
          Departments: {$departments}
          Positions: {$positions}
          Work schedules: {$schedules}
        TXT;
    }

    public function tools(User $user): array
    {
        $employeeFields = [
            'first_name' => ['type' => 'STRING'],
            'middle_name' => ['type' => 'STRING'],
            'last_name' => ['type' => 'STRING'],
            'suffix' => ['type' => 'STRING', 'description' => 'e.g. Jr., III'],
            'email' => ['type' => 'STRING'],
            'phone' => ['type' => 'STRING'],
            'address' => ['type' => 'STRING'],
            'birth_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD'],
            'gender' => ['type' => 'STRING', 'enum' => StoreEmployeeRequest::GENDERS],
            'civil_status' => ['type' => 'STRING', 'enum' => StoreEmployeeRequest::CIVIL_STATUSES],
            'department_id' => ['type' => 'INTEGER'],
            'department_name' => ['type' => 'STRING'],
            'position_id' => ['type' => 'INTEGER'],
            'position_title' => ['type' => 'STRING'],
            'manager_id' => ['type' => 'INTEGER'],
            'manager_name' => ['type' => 'STRING'],
            'work_schedule_id' => ['type' => 'INTEGER'],
            'work_schedule_name' => ['type' => 'STRING'],
            'employment_type' => ['type' => 'STRING', 'enum' => StoreEmployeeRequest::EMPLOYMENT_TYPES],
            'employment_status' => ['type' => 'STRING', 'enum' => StoreEmployeeRequest::EMPLOYMENT_STATUSES],
            'date_hired' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD; defaults to today'],
            'date_regularized' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD'],
            'basic_salary' => ['type' => 'NUMBER'],
            'bank_name' => ['type' => 'STRING'],
            'bank_account_no' => ['type' => 'STRING'],
            'tin' => ['type' => 'STRING'],
            'sss_no' => ['type' => 'STRING'],
            'philhealth_no' => ['type' => 'STRING'],
            'pagibig_no' => ['type' => 'STRING'],
        ];

        $filters = [
            'department' => ['type' => 'STRING', 'description' => 'Department name.'],
            'position' => ['type' => 'STRING', 'description' => 'Position title.'],
            'manager' => ['type' => 'STRING', 'description' => "Manager's name or employee number."],
            'employment_type' => ['type' => 'STRING', 'enum' => StoreEmployeeRequest::EMPLOYMENT_TYPES],
            'employment_status' => ['type' => 'STRING', 'enum' => StoreEmployeeRequest::EMPLOYMENT_STATUSES],
            'hired_after' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD.'],
            'hired_before' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD.'],
            'include_archived' => ['type' => 'BOOLEAN', 'description' => 'Defaults to false.'],
        ];

        return $this->permitted($user, [
            [
                'name' => 'find_employees',
                'description' => 'Search employees by name, employee number, email or phone. Use for "look up X".',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['query' => ['type' => 'STRING', 'description' => 'A name, employee number, email or phone fragment.']],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_employee_profile',
                'description' => "One employee's work details: department, position, manager, schedule, employment type and status, hire and regularisation dates, tenure and work contact. Does NOT include salary, government IDs, bank details, home address or date of birth.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'match' => ['type' => 'STRING', 'description' => 'The name or employee number.'],
                        'employee_id' => ['type' => 'INTEGER'],
                    ],
                ],
            ],
            [
                'name' => 'list_employees',
                'description' => 'List employees matching filters — use for "who is in X", "who joined since Y", "who is still probationary". Returns names and placement only, capped at '.EmployeeDisclosure::MAX_ROWS.'.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => $filters + [
                        'limit' => ['type' => 'INTEGER', 'description' => 'Max rows, up to '.EmployeeDisclosure::MAX_ROWS.'.'],
                    ],
                ],
            ],
            [
                'name' => 'count_employees',
                'description' => 'Headcount, optionally broken down. Use for "how many …", "what is the headcount", "breakdown by department". Returns numbers only, never names.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => $filters + [
                        'group_by' => ['type' => 'STRING', 'enum' => ['department', 'position', 'employment_type', 'employment_status']],
                    ],
                ],
            ],
            [
                'name' => 'list_direct_reports',
                'description' => 'The people who report to a given manager.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'manager' => ['type' => 'STRING', 'description' => "The manager's name or employee number."],
                        'manager_id' => ['type' => 'INTEGER'],
                    ],
                ],
            ],
            [
                'name' => 'get_my_employee_record',
                'description' => "The signed-in user's own employee record. Use whenever they ask about their own employment — their department, manager, schedule, hire date, tenure or regularisation.",
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'create_employee',
                'description' => 'Create a new employee record in the directory.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => array_merge($employeeFields, [
                        'employee_no' => ['type' => 'STRING', 'description' => 'Leave empty to auto-generate.'],
                    ]),
                    'required' => ['first_name', 'last_name'],
                ],
            ],
            [
                'name' => 'update_employee',
                'description' => 'Update fields on an existing employee. Only include the fields that change.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => array_merge([
                        'employee_id' => ['type' => 'INTEGER'],
                        'match' => ['type' => 'STRING', 'description' => 'Name or employee number, if the id is unknown.'],
                    ], $employeeFields),
                ],
            ],
            [
                'name' => 'archive_employee',
                'description' => 'Archive (soft-delete) an employee, removing them from the active directory.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee_id' => ['type' => 'INTEGER'],
                        'match' => ['type' => 'STRING', 'description' => 'Name or employee number, if the id is unknown.'],
                        'reason' => ['type' => 'STRING'],
                    ],
                ],
            ],
        ]);
    }

    // ── Read tools ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findEmployees(User $user, array $args): ToolResult
    {
        $query = trim((string) ($args['query'] ?? ''));

        if ($query === '') {
            return ToolResult::error('Searched the directory', 'Tell me a name, number, email or phone to search for.');
        }

        $matches = $this->matchByTokens(Employee::query()->withTrashed(), $query)
            ->with(['department', 'position'])
            ->limit(8)
            ->get();

        $cards = $matches->map(fn (Employee $e): array => $this->summaryCard($e, 'find', 'neutral', 'Match'))->all();

        return ToolResult::found(
            'Searched the directory for “'.EmployeeDisclosure::text($query).'”',
            count($cards).' match'.(count($cards) === 1 ? '' : 'es').' found',
            $cards,
        );
    }

    /**
     * One person's work profile. Reading a named individual's record is itself
     * recorded — "who looked up whom" is the question an audit asks first.
     *
     * @param  array<string, mixed>  $args
     */
    private function getEmployeeProfile(User $user, array $args): ToolResult
    {
        $employee = $this->locate($args);

        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        $employee->load(['department', 'position', 'manager', 'workSchedule']);

        ActivityLogger::log(
            event: 'viewed',
            description: "Read {$employee->full_name}'s employee profile via assistant",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        return ToolResult::ok(
            $employee->full_name,
            'Profile read',
            $this->profileCard($employee),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function listEmployees(User $user, array $args): ToolResult
    {
        $limit = (int) ($args['limit'] ?? EmployeeDisclosure::MAX_ROWS);
        $limit = max(1, min($limit, EmployeeDisclosure::MAX_ROWS));

        $query = $this->filtered($args);
        $total = (clone $query)->count();

        $rows = $query->with(['department', 'position'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit($limit)
            ->get();

        $cards = $rows->map(fn (Employee $e): array => $this->summaryCard($e, 'find', 'neutral', 'Match'))->all();

        $detail = $total > count($cards)
            ? $total.' match; showing the first '.count($cards)
            : $total.' match'.($total === 1 ? '' : 'es');

        return ToolResult::found(
            'Listed employees'.$this->filterLabel($args),
            $detail,
            $cards,
        );
    }

    /**
     * Headcount, optionally grouped. Returns counts and never a person, so it
     * stays answerable without putting anybody's name in front of the model.
     *
     * @param  array<string, mixed>  $args
     */
    private function countEmployees(User $user, array $args): ToolResult
    {
        $groupBy = (string) ($args['group_by'] ?? '');
        $query = $this->filtered($args);

        if ($groupBy === '') {
            $total = $query->count();

            return ToolResult::ok(
                'Counted employees'.$this->filterLabel($args),
                (string) $total,
                $this->card(
                    kind: 'insight',
                    tone: 'info',
                    badge: 'Headcount',
                    title: $total.' employee'.($total === 1 ? '' : 's'),
                    subtitle: trim($this->filterLabel($args), ' ') ?: 'across the organisation',
                ),
            );
        }

        $breakdown = $this->breakdown($query, $groupBy);
        $total = array_sum($breakdown);

        $meta = [];

        foreach ($breakdown as $label => $count) {
            $meta[] = $label.': '.$count;
        }

        return ToolResult::ok(
            'Counted employees by '.str_replace('_', ' ', $groupBy),
            $total.' total',
            $this->card(
                kind: 'insight',
                tone: 'info',
                badge: 'Headcount',
                title: $total.' employee'.($total === 1 ? '' : 's'),
                subtitle: 'by '.str_replace('_', ' ', $groupBy),
                meta: array_slice($meta, 0, 12),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function listDirectReports(User $user, array $args): ToolResult
    {
        $manager = $this->locate([
            'employee_id' => $args['manager_id'] ?? null,
            'match' => $args['manager'] ?? null,
        ]);

        if (! $manager) {
            return ToolResult::error('Looked up the manager', 'No matching manager found.');
        }

        $reports = Employee::query()
            ->where('manager_id', $manager->id)
            ->with(['department', 'position'])
            ->orderBy('last_name')
            ->limit(EmployeeDisclosure::MAX_ROWS)
            ->get();

        $cards = $reports->map(fn (Employee $e): array => $this->summaryCard($e, 'find', 'neutral', 'Reports to'))->all();

        return ToolResult::found(
            'Listed who reports to '.$manager->full_name,
            count($cards) === 0
                ? 'No direct reports'
                : count($cards).' direct report'.(count($cards) === 1 ? '' : 's'),
            $cards,
        );
    }

    /**
     * The caller's own record. Resolved from the signed-in user, never from an
     * argument, so there is nothing here for the model to point somewhere else.
     *
     * @param  array<string, mixed>  $args
     */
    private function getMyEmployeeRecord(User $user, array $args): ToolResult
    {
        $employee = Employee::query()
            ->where('user_id', $user->id)
            ->with(['department', 'position', 'manager', 'workSchedule'])
            ->first();

        if (! $employee) {
            return ToolResult::error(
                'Looked up your record',
                'Your account isn\'t linked to an employee record yet.',
            );
        }

        return ToolResult::ok('Your employee record', 'Profile read', $this->profileCard($employee));
    }

    // ── Write tools ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function createEmployee(User $user, array $args): ToolResult
    {
        $data = $this->mapAttributes($args);
        $data['date_hired'] ??= Carbon::today()->toDateString();
        $data['employment_type'] ??= 'probationary';
        $data['employment_status'] ??= 'active';
        $data['employee_no'] = $data['employee_no'] ?? null ?: EmployeeNumbers::next();

        $validator = Validator::make($data, (new StoreEmployeeRequest)->rules());

        if ($validator->fails()) {
            return ToolResult::error('Validated the new employee', $validator->errors()->first());
        }

        $employee = Employee::create($validator->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Added employee {$employee->full_name} via assistant",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        return ToolResult::ok(
            "Created {$employee->full_name}",
            $employee->employee_no,
            $this->summaryCard($employee->load(['department', 'position']), 'add', 'positive', 'Added'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateEmployee(User $user, array $args): ToolResult
    {
        $employee = $this->locate($args);

        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        $changes = $this->mapAttributes($args);
        unset($changes['employee_no']); // never reassigned via the assistant

        if ($changes === []) {
            return ToolResult::error("Reviewed changes to {$employee->full_name}", 'No fields to update were provided.');
        }

        $rules = collect($this->updateRules($employee))->only(array_keys($changes))->all();
        $validator = Validator::make($changes, $rules);

        if ($validator->fails()) {
            return ToolResult::error("Validated changes to {$employee->full_name}", $validator->errors()->first());
        }

        $employee->fill($validator->validated())->save();

        ActivityLogger::log(
            event: 'updated',
            description: "Updated employee {$employee->full_name} via assistant",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        return ToolResult::ok(
            "Updated {$employee->full_name}",
            implode(', ', array_keys($changes)),
            $this->summaryCard($employee->fresh(['department', 'position']), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveEmployee(User $user, array $args): ToolResult
    {
        $employee = $this->locate($args);

        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        $card = $this->summaryCard($employee->load(['department', 'position']), 'archive', 'warning', 'Archived');
        $employee->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived employee {$employee->full_name} via assistant",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        return ToolResult::ok("Archived {$employee->full_name}", $employee->employee_no, $card);
    }

    // ── Query helpers ────────────────────────────────────────────────────────

    /**
     * Build the filtered directory query shared by the list and count tools.
     * Every filter resolves to a concrete id or an enum value first — a label
     * that matches nothing narrows to nothing rather than being ignored, so a
     * count is never quietly wider than what was asked for.
     *
     * @param  array<string, mixed>  $args
     * @return Builder<Employee>
     */
    private function filtered(array $args): Builder
    {
        $query = Employee::query();

        if (filled($args['include_archived'] ?? null) && $args['include_archived'] === true) {
            $query->withTrashed();
        }

        if (($department = $this->firstFilled($args, ['department', 'department_name'])) !== null) {
            $query->where('department_id', $this->resolveId(Department::query(), 'name', $department) ?? 0);
        }

        if (($position = $this->firstFilled($args, ['position', 'position_title'])) !== null) {
            $query->where('position_id', $this->resolveId(Position::query(), 'title', $position) ?? 0);
        }

        if (($manager = $this->firstFilled($args, ['manager', 'manager_name'])) !== null) {
            $query->where('manager_id', $this->matchByTokens(Employee::query(), $manager)->value('id') ?? 0);
        }

        foreach (['employment_type' => StoreEmployeeRequest::EMPLOYMENT_TYPES, 'employment_status' => StoreEmployeeRequest::EMPLOYMENT_STATUSES] as $column => $allowed) {
            $value = $this->firstFilled($args, [$column]);

            if ($value !== null) {
                // Enum columns only ever take a value from their own list; an
                // unknown one matches nothing instead of reaching the database.
                $query->where($column, in_array($value, $allowed, true) ? $value : '__none__');
            }
        }

        foreach (['hired_after' => '>=', 'hired_before' => '<='] as $key => $operator) {
            $date = $this->date($args[$key] ?? null);

            if ($date !== null) {
                $query->whereDate('date_hired', $operator, $date);
            }
        }

        return $query;
    }

    /**
     * Group a filtered directory query into label => count, resolving foreign
     * keys to names. `group_by` is matched against a fixed list, never
     * interpolated, so it cannot become a column name of the model's choosing.
     *
     * @param  Builder<Employee>  $query
     * @return array<string, int>
     */
    private function breakdown(Builder $query, string $groupBy): array
    {
        $column = match ($groupBy) {
            'department' => 'department_id',
            'position' => 'position_id',
            'employment_type' => 'employment_type',
            'employment_status' => 'employment_status',
            default => null,
        };

        if ($column === null) {
            return [];
        }

        $counts = (clone $query)
            ->selectRaw($column.' as bucket, count(*) as tally')
            ->groupBy($column)
            ->pluck('tally', 'bucket');

        $labels = match ($groupBy) {
            'department' => Department::withTrashed()->pluck('name', 'id'),
            'position' => Position::withTrashed()->pluck('title', 'id'),
            default => null,
        };

        $breakdown = [];

        foreach ($counts as $bucket => $tally) {
            $label = $labels !== null
                ? ($labels[$bucket] ?? 'Unassigned')
                : (string) ($bucket ?: 'Unassigned');

            $breakdown[(string) EmployeeDisclosure::text((string) $label)] = (int) $tally;
        }

        arsort($breakdown);

        return $breakdown;
    }

    /**
     * A short human label for whichever filters were applied, for the step line.
     *
     * @param  array<string, mixed>  $args
     */
    private function filterLabel(array $args): string
    {
        $parts = [];

        foreach (['department', 'position', 'manager', 'employment_type', 'employment_status'] as $key) {
            if (filled($args[$key] ?? null)) {
                $parts[] = EmployeeDisclosure::text((string) $args[$key]);
            }
        }

        if (filled($args['hired_after'] ?? null)) {
            $parts[] = 'hired on/after '.$this->date($args['hired_after']);
        }

        if (filled($args['hired_before'] ?? null)) {
            $parts[] = 'hired on/before '.$this->date($args['hired_before']);
        }

        return $parts === [] ? '' : ' in '.implode(', ', array_filter($parts));
    }

    /**
     * Accept a date only in the shape the tool advertised; anything else is
     * dropped rather than handed to the database to interpret.
     */
    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Resolve the target employee from a `match` / employee_no / id arg. Both
     * paths run through the organisation scope, so an id belonging to another
     * tenant resolves to nothing rather than to their record.
     *
     * @param  array<string, mixed>  $args
     */
    private function locate(array $args): ?Employee
    {
        if (filled($args['employee_id'] ?? null) && is_numeric($args['employee_id'])) {
            return Employee::find((int) $args['employee_id']);
        }

        $needle = $this->firstFilled($args, ['match', 'employee_no', 'query', 'name']);

        return $needle ? $this->matchByTokens(Employee::query(), $needle)->first() : null;
    }

    /**
     * Translate loose model arguments into a clean attribute array, resolving
     * department / position / manager / schedule by label when no id was given.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function mapAttributes(array $args): array
    {
        $direct = [
            'employee_no', 'first_name', 'middle_name', 'last_name', 'suffix',
            'birth_date', 'gender', 'civil_status', 'email', 'phone', 'address',
            'employment_type', 'employment_status', 'date_hired', 'date_regularized',
            'basic_salary', 'bank_name', 'bank_account_no', 'tin', 'sss_no',
            'philhealth_no', 'pagibig_no', 'department_id', 'position_id',
            'manager_id', 'work_schedule_id',
        ];

        $data = [];

        foreach ($direct as $key) {
            if (array_key_exists($key, $args) && $args[$key] !== '' && $args[$key] !== null) {
                $data[$key] = $args[$key];
            }
        }

        $department = $this->firstFilled($args, ['department', 'department_name']);
        if (empty($data['department_id']) && $department !== null) {
            $data['department_id'] = $this->resolveId(Department::query(), 'name', $department);
        }

        $position = $this->firstFilled($args, ['position', 'position_title']);
        if (empty($data['position_id']) && $position !== null) {
            $data['position_id'] = $this->resolveId(Position::query(), 'title', $position);
        }

        $schedule = $this->firstFilled($args, ['work_schedule', 'work_schedule_name']);
        if (empty($data['work_schedule_id']) && $schedule !== null) {
            $data['work_schedule_id'] = $this->resolveId(WorkSchedule::query(), 'name', $schedule);
        }

        $manager = $this->firstFilled($args, ['manager', 'manager_name']);
        if (empty($data['manager_id']) && $manager !== null) {
            $data['manager_id'] = $this->matchByTokens(Employee::query(), $manager)->value('id');
        }

        return $data;
    }

    /**
     * Update validation rules, mirroring UpdateEmployeeRequest but bound to a
     * concrete employee (no request context here).
     *
     * @return array<string, mixed>
     */
    private function updateRules(Employee $employee): array
    {
        $rules = (new StoreEmployeeRequest)->rules();

        // These three re-declare rules the store request already pins to the
        // tenant, so they have to be re-pinned here too — a bare Rule::exists
        // would answer for the whole instance. See App\Support\TenantRule.
        $rules['employee_no'] = ['nullable', 'string', 'max:50', TenantRule::unique('employees', 'employee_no')->ignore($employee->id)];
        $rules['manager_id'] = ['nullable', 'integer', TenantRule::exists('employees'), Rule::notIn([$employee->id])];
        $rules['user_id'] = ['nullable', 'integer', Rule::exists('users', 'id'), TenantRule::unique('employees', 'user_id')->ignore($employee->id)];

        foreach (['first_name', 'last_name', 'employment_type', 'employment_status', 'date_hired'] as $field) {
            $rules[$field] = array_values(array_diff($rules[$field], ['required']));
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }

    /**
     * The action name used in a "you don't have permission to …" reply.
     */
    private function actionFor(string $tool): string
    {
        return match ($tool) {
            'create_employee' => 'create employees',
            'update_employee' => 'update employees',
            'archive_employee' => 'archive employees',
            default => 'view the employee directory',
        };
    }

    /**
     * The list/row card: name, placement, status. Contact details deliberately
     * absent — see {@see EmployeeDisclosure::summary()}.
     *
     * @return array<string, mixed>
     */
    private function summaryCard(Employee $employee, string $kind, string $tone, string $badge): array
    {
        $view = EmployeeDisclosure::summary($employee);

        $subtitle = collect([$view['position'], $view['department']])->filter()->implode(' · ');

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: (string) $view['name'],
            subtitle: $subtitle ?: null,
            meta: EmployeeDisclosure::meta($view, ['employee_no', 'status']),
            avatar: ['name' => (string) $view['name'], 'initials' => $employee->initials(), 'photo' => $employee->photo_url],
            id: $employee->id,
        );
    }

    /**
     * The single-record card: the operational detail somebody asks an HR
     * assistant for, and nothing from the withheld list.
     *
     * @return array<string, mixed>
     */
    private function profileCard(Employee $employee): array
    {
        $view = EmployeeDisclosure::profile($employee);

        $subtitle = collect([$view['position'], $view['department']])->filter()->implode(' · ');

        return $this->card(
            kind: 'insight',
            tone: 'info',
            badge: 'Profile',
            title: (string) $view['name'],
            subtitle: $subtitle ?: null,
            meta: EmployeeDisclosure::meta($view, [
                'employee_no', 'status', 'employment_type', 'manager',
                'work_schedule', 'date_hired', 'date_regularized', 'tenure',
                'email', 'phone',
            ]),
            avatar: ['name' => (string) $view['name'], 'initials' => $employee->initials(), 'photo' => $employee->photo_url],
            id: $employee->id,
        );
    }
}
