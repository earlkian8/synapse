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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Employee-directory capability: find, create, update and archive employees,
 * including extraction from an attached CV. Mirrors the manual UI's validation,
 * tenant scoping and activity logging exactly.
 */
class EmployeeModule extends Module
{
    public function key(): string
    {
        return 'employees';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('employees.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_employees' => 'findEmployees',
            'create_employee' => 'createEmployee',
            'update_employee' => 'updateEmployee',
            'archive_employee' => 'archiveEmployee',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(): string
    {
        $departments = Department::orderBy('name')->get(['id', 'name'])
            ->map(fn (Department $d): string => "#{$d->id} {$d->name}")->implode(', ') ?: 'none';
        $positions = Position::orderBy('title')->get(['id', 'title'])
            ->map(fn (Position $p): string => "#{$p->id} {$p->title}")->implode(', ') ?: 'none';
        $schedules = WorkSchedule::orderBy('name')->get(['id', 'name'])
            ->map(fn (WorkSchedule $s): string => "#{$s->id} {$s->name}")->implode(', ') ?: 'none';

        return <<<TXT
        EMPLOYEES — the staff directory. Find, create, update and archive employees, including extracting details from an attached CV/resume.
        - When adding from one or more CVs, call create_employee once per person — never merge two people into one record. Use defaults when missing: date_hired = today, employment_type = probationary, employment_status = active.
        - To update or archive someone, first find_employees to get the exact employee_id (or pass the name/number as `match`). Never guess an id.
        - Prefer ids from these catalogs; if you only know a label, pass department_name / position_title / manager_name / work_schedule_name and the system resolves it. If a label is not in the catalog, leave it unset.
          Departments: {$departments}
          Positions: {$positions}
          Work schedules: {$schedules}
        TXT;
    }

    public function tools(): array
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

        return [
            [
                'name' => 'find_employees',
                'description' => 'Search existing employees by name, employee number, email or phone. Use before updating or archiving to get the exact employee_id.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['query' => ['type' => 'STRING', 'description' => 'A name, employee number, email or phone fragment.']],
                    'required' => ['query'],
                ],
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
        ];
    }

    // ── Tools ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findEmployees(User $user, array $args): ToolResult
    {
        $query = trim((string) ($args['query'] ?? ''));

        $matches = $this->matchByTokens(Employee::query()->withTrashed(), $query)
            ->with(['department', 'position'])
            ->limit(8)
            ->get();

        $cards = $matches->map(fn (Employee $e): array => $this->employeeCard($e, 'find', 'neutral', 'Match'))->all();

        return ToolResult::found(
            "Searched the directory for “{$query}”",
            count($cards).' match'.(count($cards) === 1 ? '' : 'es').' found',
            $cards,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function createEmployee(User $user, array $args): ToolResult
    {
        if ($user->cannot('employees.create')) {
            return $this->denied('create employees');
        }

        $data = $this->mapAttributes($args);
        $data['date_hired'] ??= Carbon::today()->toDateString();
        $data['employment_type'] ??= 'probationary';
        $data['employment_status'] ??= 'active';
        $data['employee_no'] = $data['employee_no'] ?? null ?: $this->nextEmployeeNo();

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
            $this->employeeCard($employee->load(['department', 'position']), 'add', 'positive', 'Added'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateEmployee(User $user, array $args): ToolResult
    {
        if ($user->cannot('employees.update')) {
            return $this->denied('update employees');
        }

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
            $this->employeeCard($employee->fresh(['department', 'position']), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveEmployee(User $user, array $args): ToolResult
    {
        if ($user->cannot('employees.delete')) {
            return $this->denied('archive employees');
        }

        $employee = $this->locate($args);

        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        $card = $this->employeeCard($employee->load(['department', 'position']), 'archive', 'warning', 'Archived');
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

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Resolve the target employee from a `match` / employee_no / id arg.
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

        $rules['employee_no'] = ['nullable', 'string', 'max:50', Rule::unique('employees', 'employee_no')->ignore($employee->id)];
        $rules['manager_id'] = ['nullable', 'integer', Rule::exists('employees', 'id'), Rule::notIn([$employee->id])];
        $rules['user_id'] = ['nullable', 'integer', Rule::exists('users', 'id'), Rule::unique('employees', 'user_id')->ignore($employee->id)];

        foreach (['first_name', 'last_name', 'employment_type', 'employment_status', 'date_hired'] as $field) {
            $rules[$field] = array_values(array_diff($rules[$field], ['required']));
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }

    private function nextEmployeeNo(): string
    {
        $next = (int) Employee::withTrashed()->max('id') + 1;

        return 'EMP-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeCard(Employee $employee, string $kind, string $tone, string $badge): array
    {
        $subtitle = collect([$employee->position?->title, $employee->department?->name])->filter()->implode(' · ');

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $employee->full_name,
            subtitle: $subtitle ?: null,
            meta: [$employee->employee_no, $employee->trashed() ? 'archived' : $employee->employment_status],
            avatar: ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url],
            id: $employee->id,
        );
    }
}
