<?php

namespace App\Services\Employee;

use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\ActivityLogger;
use App\Support\Ai\GeminiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The agentic brain behind the Employee assistant.
 *
 * Wraps Gemini with a small set of typed tools (find / create / update /
 * archive) and runs a bounded function-calling loop. Every mutation flows
 * through the same validation rules, tenant scoping and activity logging as the
 * manual UI — the model only *decides*, this service *enforces*.
 */
class EmployeeAgent
{
    /** Hard ceiling on tool-calling round-trips per request. */
    private const MAX_STEPS = 6;

    public function __construct(private readonly GeminiClient $gemini) {}

    public function configured(): bool
    {
        return $this->gemini->configured();
    }

    /**
     * Handle one user turn and return the assistant's reply plus a transcript
     * of what it actually did (for the UI to animate).
     *
     * @param  array<int, array{role?: string, text?: string}>  $history
     * @param  array<int, array{mime: string, data: string}>  $fileParts  Base64 files (e.g. CVs) for multimodal input.
     * @return array{reply: string, steps: array<int, array<string, mixed>>, actions: array<int, array<string, mixed>>}
     */
    public function handle(User $user, string $message, array $history = [], array $fileParts = []): array
    {
        $contents = $this->buildHistory($history);
        $contents[] = $this->buildUserTurn($message, $fileParts);

        $steps = [];
        $actions = [];
        $reply = '';

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $response = $this->gemini->generate($contents, $this->tools(), $this->systemInstruction());
            $parts = data_get($response, 'candidates.0.content.parts', []);

            if (! is_array($parts) || $parts === []) {
                break;
            }

            // Echo the model's turn back into the transcript so a follow-up
            // function-response stays correctly paired with its call.
            $contents[] = ['role' => 'model', 'parts' => $parts];

            $calls = [];
            $texts = [];

            foreach ($parts as $part) {
                if (isset($part['functionCall'])) {
                    $calls[] = $part['functionCall'];
                } elseif (isset($part['text']) && trim((string) $part['text']) !== '') {
                    $texts[] = $part['text'];
                }
            }

            if ($texts !== []) {
                $reply = trim(implode("\n", $texts));
            }

            if ($calls === []) {
                break;
            }

            $responseParts = [];

            foreach ($calls as $call) {
                $name = (string) ($call['name'] ?? '');
                $args = (array) ($call['args'] ?? []);
                $result = $this->dispatch($user, $name, $args, $steps, $actions);
                $responseParts[] = ['functionResponse' => ['name' => $name, 'response' => $result]];
            }

            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        if ($reply === '') {
            $reply = $actions !== []
                ? $this->summariseActions($actions)
                : "I wasn't able to complete that. Could you rephrase or give me a few more details?";
        }

        return ['reply' => $reply, 'steps' => $steps, 'actions' => $actions];
    }

    // ── Tool dispatch ────────────────────────────────────────────────────────

    /**
     * Route a function call to its handler, recording a step and (for
     * mutations) an action for the UI.
     *
     * @param  array<string, mixed>  $args
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    private function dispatch(User $user, string $name, array $args, array &$steps, array &$actions): array
    {
        return match ($name) {
            'find_employees' => $this->findEmployees($args, $steps),
            'create_employee' => $this->createEmployee($user, $args, $steps, $actions),
            'update_employee' => $this->updateEmployee($user, $args, $steps, $actions),
            'archive_employee' => $this->archiveEmployee($user, $args, $steps, $actions),
            default => $this->step($steps, "Unknown action “{$name}”", 'error', 'That tool is not available.'),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function findEmployees(array $args, array &$steps): array
    {
        $query = trim((string) ($args['query'] ?? ''));

        $matches = Employee::query()
            ->withTrashed()
            ->search($query)
            ->with(['department', 'position'])
            ->limit(8)
            ->get()
            ->map(fn (Employee $e): array => [
                'id' => $e->id,
                'employee_no' => $e->employee_no,
                'full_name' => $e->full_name,
                'department' => $e->department?->name,
                'position' => $e->position?->title,
                'status' => $e->trashed() ? 'archived' : $e->employment_status,
            ])
            ->all();

        $this->step(
            $steps,
            "Searched the directory for “{$query}”",
            'done',
            count($matches).' match'.(count($matches) === 1 ? '' : 'es').' found',
        );

        return ['employees' => $matches];
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    private function createEmployee(User $user, array $args, array &$steps, array &$actions): array
    {
        if ($user->cannot('employees.create')) {
            return $this->denied($steps, 'create employees');
        }

        $data = $this->mapAttributes($args);

        // Sensible defaults so a CV with only a name still produces a valid hire.
        $data['date_hired'] ??= Carbon::today()->toDateString();
        $data['employment_type'] ??= 'probationary';
        $data['employment_status'] ??= 'active';
        $data['employee_no'] = $data['employee_no'] ?? null ?: $this->nextEmployeeNo();

        $validator = Validator::make($data, (new StoreEmployeeRequest)->rules());

        if ($validator->fails()) {
            $message = $validator->errors()->first();
            $this->step($steps, 'Validated the new employee', 'error', $message);

            return ['error' => "Validation failed: {$message}"];
        }

        $employee = Employee::create($validator->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Added employee {$employee->full_name} via assistant",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        $summary = $this->summary($employee, 'created');
        $this->step($steps, "Created {$employee->full_name}", 'done', $employee->employee_no);
        $actions[] = ['type' => 'created', 'employee' => $summary];

        return ['ok' => true, 'employee' => $summary];
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    private function updateEmployee(User $user, array $args, array &$steps, array &$actions): array
    {
        if ($user->cannot('employees.update')) {
            return $this->denied($steps, 'update employees');
        }

        $employee = Employee::find($args['employee_id'] ?? null);

        if (! $employee) {
            $this->step($steps, 'Looked up the employee to update', 'error', 'No matching employee.');

            return ['error' => 'No employee was found with that id. Use find_employees first.'];
        }

        $changes = $this->mapAttributes($args);
        unset($changes['employee_no']); // never reassigned via the assistant

        if ($changes === []) {
            return ['error' => 'No fields to update were provided.'];
        }

        $rules = collect($this->updateRules($employee))
            ->only(array_keys($changes))
            ->all();

        $validator = Validator::make($changes, $rules);

        if ($validator->fails()) {
            $message = $validator->errors()->first();
            $this->step($steps, "Validated changes to {$employee->full_name}", 'error', $message);

            return ['error' => "Validation failed: {$message}"];
        }

        $employee->fill($validator->validated())->save();

        ActivityLogger::log(
            event: 'updated',
            description: "Updated employee {$employee->full_name} via assistant",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        $summary = $this->summary($employee->fresh(['department', 'position']), 'updated');
        $this->step($steps, "Updated {$employee->full_name}", 'done', implode(', ', array_keys($changes)));
        $actions[] = ['type' => 'updated', 'employee' => $summary];

        return ['ok' => true, 'employee' => $summary];
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    private function archiveEmployee(User $user, array $args, array &$steps, array &$actions): array
    {
        if ($user->cannot('employees.delete')) {
            return $this->denied($steps, 'archive employees');
        }

        $employee = Employee::find($args['employee_id'] ?? null);

        if (! $employee) {
            $this->step($steps, 'Looked up the employee to archive', 'error', 'No matching employee.');

            return ['error' => 'No employee was found with that id. Use find_employees first.'];
        }

        $summary = $this->summary($employee->load(['department', 'position']), 'archived');
        $employee->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived employee {$employee->full_name} via assistant",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        $this->step($steps, "Archived {$employee->full_name}", 'done', $employee->employee_no);
        $actions[] = ['type' => 'archived', 'employee' => $summary];

        return ['ok' => true, 'employee' => $summary];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Translate loosely-typed model arguments into a clean attribute array,
     * resolving department / position / manager / schedule by name when an id
     * was not supplied.
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

        // Name-based fallbacks (the model often has labels, not ids).
        if (empty($data['department_id']) && filled($args['department_name'] ?? null)) {
            $data['department_id'] = $this->resolveId(Department::query(), 'name', (string) $args['department_name']);
        }

        if (empty($data['position_id']) && filled($args['position_title'] ?? null)) {
            $data['position_id'] = $this->resolveId(Position::query(), 'title', (string) $args['position_title']);
        }

        if (empty($data['work_schedule_id']) && filled($args['work_schedule_name'] ?? null)) {
            $data['work_schedule_id'] = $this->resolveId(WorkSchedule::query(), 'name', (string) $args['work_schedule_name']);
        }

        if (empty($data['manager_id']) && filled($args['manager_name'] ?? null)) {
            $data['manager_id'] = Employee::query()->search((string) $args['manager_name'])->value('id');
        }

        return $data;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     */
    private function resolveId($query, string $column, string $value): ?int
    {
        return $query
            ->whereRaw('lower('.$column.') = ?', [Str::lower(trim($value))])
            ->value('id');
    }

    /**
     * The update validation rules, mirroring UpdateEmployeeRequest but bound to
     * a concrete employee (no request context available here).
     *
     * @return array<string, mixed>
     */
    private function updateRules(Employee $employee): array
    {
        $rules = (new StoreEmployeeRequest)->rules();

        $rules['employee_no'] = ['nullable', 'string', 'max:50', \Illuminate\Validation\Rule::unique('employees', 'employee_no')->ignore($employee->id)];
        $rules['manager_id'] = ['nullable', 'integer', \Illuminate\Validation\Rule::exists('employees', 'id'), \Illuminate\Validation\Rule::notIn([$employee->id])];
        $rules['user_id'] = ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id'), \Illuminate\Validation\Rule::unique('employees', 'user_id')->ignore($employee->id)];

        // Updates are partial — required fields only validate when present.
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
     * Compact employee payload the chat UI renders and animates.
     *
     * @return array<string, mixed>
     */
    private function summary(Employee $employee, string $action): array
    {
        return [
            'id' => $employee->id,
            'employee_no' => $employee->employee_no,
            'full_name' => $employee->full_name,
            'initials' => $employee->initials(),
            'photo' => $employee->photo_url,
            'position' => $employee->position?->title,
            'department' => $employee->department?->name,
            'employment_status' => $employee->employment_status,
            'employment_type' => $employee->employment_type,
            'action' => $action,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function step(array &$steps, string $label, string $status, ?string $detail = null): array
    {
        $steps[] = ['label' => $label, 'status' => $status, 'detail' => $detail];

        return ['ok' => $status === 'done'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function denied(array &$steps, string $action): array
    {
        $this->step($steps, 'Permission check', 'error', "Not allowed to {$action}.");

        return ['error' => "You don't have permission to {$action}."];
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function summariseActions(array $actions): string
    {
        return collect($actions)
            ->map(fn (array $a): string => ucfirst($a['type']).' '.($a['employee']['full_name'] ?? 'employee').'.')
            ->implode(' ');
    }

    // ── Conversation builders ────────────────────────────────────────────────

    /**
     * @param  array<int, array{role?: string, text?: string}>  $history
     * @return array<int, array<string, mixed>>
     */
    private function buildHistory(array $history): array
    {
        $contents = [];

        foreach ($history as $turn) {
            $text = trim((string) ($turn['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $contents[] = [
                'role' => ($turn['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $text]],
            ];
        }

        return $contents;
    }

    /**
     * @param  array<int, array{mime: string, data: string}>  $fileParts
     * @return array<string, mixed>
     */
    private function buildUserTurn(string $message, array $fileParts): array
    {
        $fallback = count($fileParts) > 1
            ? 'Please review the attached documents and add each person as an employee.'
            : 'Please review the attached document and add the employee.';

        $parts = [['text' => $message !== '' ? $message : $fallback]];

        foreach ($fileParts as $filePart) {
            $parts[] = ['inline_data' => ['mime_type' => $filePart['mime'], 'data' => $filePart['data']]];
        }

        return ['role' => 'user', 'parts' => $parts];
    }

    private function systemInstruction(): string
    {
        $today = Carbon::today()->toDateString();

        $departments = Department::orderBy('name')->get(['id', 'name'])
            ->map(fn (Department $d): string => "#{$d->id} {$d->name}")->implode(', ') ?: 'none';
        $positions = Position::orderBy('title')->get(['id', 'title'])
            ->map(fn (Position $p): string => "#{$p->id} {$p->title}")->implode(', ') ?: 'none';
        $schedules = WorkSchedule::orderBy('name')->get(['id', 'name'])
            ->map(fn (WorkSchedule $s): string => "#{$s->id} {$s->name}")->implode(', ') ?: 'none';

        $genders = implode(', ', StoreEmployeeRequest::GENDERS);
        $civil = implode(', ', StoreEmployeeRequest::CIVIL_STATUSES);
        $types = implode(', ', StoreEmployeeRequest::EMPLOYMENT_TYPES);
        $statuses = implode(', ', StoreEmployeeRequest::EMPLOYMENT_STATUSES);

        return <<<PROMPT
        You are Nexo Assistant, an agentic HR helper embedded in the Nexo HR platform.

        STRICT SCOPE — employee management ONLY. You may find, create, update and archive employee records by calling the provided tools, and answer questions about employees. You CANNOT do payroll, leave, attendance, recruitment, benefits, performance, analytics, or anything else, and you must not answer general/unrelated questions. If a request is outside this scope, reply with one short, polite sentence saying it's outside what you can do today, and DO NOT call any tool. Never invent capabilities or data.

        Today is {$today}.

        Behaviour:
        - When the user asks to add/onboard someone (including from an attached CV/resume), extract every detail you can and call create_employee. If MULTIPLE CVs/resumes or people are provided, call create_employee once per person — never merge two people into one record. Use sensible defaults when something is missing: date_hired = today, employment_type = probationary, employment_status = active. Briefly mention any assumptions you made.
        - To update or archive someone, FIRST call find_employees to resolve the exact employee_id (unless the user already gave an employee number you can match). Never guess an id. If find_employees returns no match, say so and stop — do not fabricate one.
        - Only set fields you were actually given or can read from a document. Do not invent emails, salaries, ids or government numbers.
        - Prefer ids from the catalogs below. If you only know a label, you may pass department_name, position_title, manager_name or work_schedule_name and the system will resolve it. If a label doesn't match the catalog, leave it unset rather than guessing.
        - After acting, reply in 1–3 short, warm, accurate sentences describing exactly what you did (or why you couldn't). Reply in the user's language (English or Filipino).

        Catalogs (use these exact ids/labels):
        - Departments: {$departments}
        - Positions: {$positions}
        - Work schedules: {$schedules}

        Allowed values:
        - gender: {$genders}
        - civil_status: {$civil}
        - employment_type: {$types}
        - employment_status: {$statuses}
        PROMPT;
    }

    /**
     * The tool schemas exposed to Gemini (OpenAPI subset).
     *
     * @return array<int, array<string, mixed>>
     */
    private function tools(): array
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
                    'properties' => array_merge(['employee_id' => ['type' => 'INTEGER']], $employeeFields),
                    'required' => ['employee_id'],
                ],
            ],
            [
                'name' => 'archive_employee',
                'description' => 'Archive (soft-delete) an employee, removing them from the active directory.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee_id' => ['type' => 'INTEGER'],
                        'reason' => ['type' => 'STRING'],
                    ],
                    'required' => ['employee_id'],
                ],
            ],
        ];
    }
}
