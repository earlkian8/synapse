<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Setup\DepartmentRequest;
use App\Http\Requests\Setup\PositionRequest;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Queries\DepartmentStatistics;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\Setup\DepartmentException;
use App\Support\Setup\DepartmentWorkflow;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Departments capability: the org structure — the department hierarchy, who
 * heads each part, and the positions under them.
 *
 * **Reading** answers "how are we organised?", "who heads Finance?", "what
 * positions does IT have, and how many hold each?". **Doing** creates, edits,
 * re-parents, archives and restores departments and manages their positions
 * through {@see DepartmentWorkflow}, the path the Departments screen takes, with
 * the screen's own rules ({@see DepartmentRequest::rulesFor()}): a per-tenant
 * unique code, and a parent that is never inside the department's own subtree.
 *
 * Deliberately left to the screen:
 *
 * - **Salary bands.** A position's band, next to a person's title, is an
 *   estimate of their pay — the assistant neither reads nor sets pay (ADR 0027).
 * - **A department's default schedule and attendance policy.** Changing either
 *   re-judges the attendance of everybody in it; that is attendance setup.
 * - **Permanent deletion**, which detaches every employee, position and
 *   sub-department at once and cannot be undone.
 *
 * Archiving a department and deleting a position wait for the user's Confirm
 * (ADR 0049). Everything needs `setup.departments.view`; changes
 * `setup.departments.manage`.
 */
class DepartmentsModule extends Module implements ContributesTopicContext
{
    /** How many results a list returns. */
    private const MAX_RESULTS = 20;

    /** How many positions or sub-departments a read-out spells out. */
    private const MAX_LISTED = 15;

    public function __construct(
        private readonly DepartmentWorkflow $workflow,
        private readonly DepartmentStatistics $statistics,
    ) {}

    public function key(): string
    {
        return 'departments';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('setup.departments.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_departments' => 'findDepartments',
            'get_department' => 'getDepartment',
            'list_positions' => 'listPositions',
            'org_structure_summary' => 'summary',
            'create_department' => 'createDepartment',
            'update_department' => 'updateDepartment',
            'archive_department' => 'archiveDepartment',
            'restore_department' => 'restoreDepartment',
            'add_position' => 'addPosition',
            'update_position' => 'updatePosition',
            'delete_position' => 'deletePosition',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_departments' => 'setup.departments.view',
            'get_department' => 'setup.departments.view',
            'list_positions' => 'setup.departments.view',
            'org_structure_summary' => 'setup.departments.view',
            'create_department' => 'setup.departments.manage',
            'update_department' => 'setup.departments.manage',
            'archive_department' => 'setup.departments.manage',
            'restore_department' => 'setup.departments.manage',
            'add_position' => 'setup.departments.manage',
            'update_position' => 'setup.departments.manage',
            'delete_position' => 'setup.departments.manage',
        ];
    }

    protected function confirmTools(): array
    {
        // Archiving takes a whole part of the organisation off the screens;
        // deleting a position takes it off everybody who holds it.
        return ['archive_department', 'delete_position'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'setup.departments.view';

        if ($user->cannot($permission)) {
            return $this->denied($permission === 'setup.departments.manage' ? 'change the org structure' : 'view the org structure');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        // The Employees capability already lists the departments to anyone who
        // may see the directory; the list is only repeated here for those who
        // may not — every line of guidance is paid for on every turn.
        $departments = $this->allows($user, 'employees.view')
            ? ''
            : "\n  Departments: ".$this->catalog(Department::query()->orderBy('name')->get(['name', 'code'])->map(fn (Department $d): string => "{$d->name} ({$d->code})"));

        $manage = $this->allows($user, 'setup.departments.manage')
            ? <<<'TXT'

            - create_department / update_department set a department's name, code (unique; stored upper-case), parent department (or top_level to make it top-level), head (an employee, or remove_head) and description. A department cannot be moved under itself or one of its own sub-departments.
            - add_position / update_position manage the positions (title, description) under a department. restore_department brings an archived one back.
            - archive_department and delete_position wait for the user's confirmation.
            - Salary bands, a department's default schedule or attendance policy, and permanent deletion are done on the Departments screen (/setup/departments), not here — say so if asked.
            TXT
            : '';

        return <<<TXT
        DEPARTMENTS — the org structure: a hierarchy of departments, each with an optional head and the positions under it.
        - find_departments lists departments; get_department reads one — its parent, head, sub-departments, positions and headcount; list_positions lists positions and how many people hold each; org_structure_summary reads the whole structure.
        - Pass departments by name or code, and people by name or employee number. Pay and salary bands are not available here.{$manage}{$departments}
        TXT;
    }

    public function tools(User $user): array
    {
        $department = ['type' => 'STRING', 'description' => 'Department name or code.'];

        return $this->permitted($user, [
            [
                'name' => 'find_departments',
                'description' => 'List departments, optionally by (part of) their name or code, including archived ones when asked.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Part of the name or code.'],
                        'archived' => ['type' => 'BOOLEAN', 'description' => 'List archived departments instead.'],
                    ],
                ],
            ],
            [
                'name' => 'get_department',
                'description' => 'Read one department: code, parent, head, sub-departments, positions (with how many hold each) and headcount.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['department' => $department],
                    'required' => ['department'],
                ],
            ],
            [
                'name' => 'list_positions',
                'description' => 'List positions — in one department, or across the organisation — with how many people hold each.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'department' => $department,
                        'query' => ['type' => 'STRING', 'description' => 'Part of the position title.'],
                    ],
                ],
            ],
            [
                'name' => 'org_structure_summary',
                'description' => 'The whole org structure: counts, the department tree, departments without a head, and departments nobody is in.',
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'create_department',
                'description' => 'Create a department.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'code' => ['type' => 'STRING', 'description' => 'A short unique code, e.g. FIN.'],
                        'parent' => ['type' => 'STRING', 'description' => 'The parent department, by name or code.'],
                        'head' => ['type' => 'STRING', 'description' => 'The employee who heads it: name or employee number.'],
                        'description' => ['type' => 'STRING'],
                    ],
                    'required' => ['name', 'code'],
                ],
            ],
            [
                'name' => 'update_department',
                'description' => "Change a department's name, code, parent, head or description. Only the fields given change.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'department' => $department,
                        'new_name' => ['type' => 'STRING'],
                        'code' => ['type' => 'STRING'],
                        'parent' => ['type' => 'STRING', 'description' => 'Move it under this department.'],
                        'top_level' => ['type' => 'BOOLEAN', 'description' => 'Make it a top-level department.'],
                        'head' => ['type' => 'STRING', 'description' => 'The new head: name or employee number.'],
                        'remove_head' => ['type' => 'BOOLEAN', 'description' => 'Leave it without a head.'],
                        'description' => ['type' => 'STRING'],
                    ],
                    'required' => ['department'],
                ],
            ],
            [
                'name' => 'archive_department',
                'description' => 'Archive a department. Its people, positions and sub-departments stay assigned to it; it can be restored.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['department' => $department],
                    'required' => ['department'],
                ],
            ],
            [
                'name' => 'restore_department',
                'description' => 'Restore an archived department.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['department' => ['type' => 'STRING', 'description' => 'The archived department, by name or code.']],
                    'required' => ['department'],
                ],
            ],
            [
                'name' => 'add_position',
                'description' => 'Add a position under a department.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'department' => $department,
                        'title' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                    ],
                    'required' => ['department', 'title'],
                ],
            ],
            [
                'name' => 'update_position',
                'description' => 'Rename a position or change its description.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'department' => $department,
                        'position' => ['type' => 'STRING', 'description' => 'The position title.'],
                        'new_title' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                    ],
                    'required' => ['department', 'position'],
                ],
            ],
            [
                'name' => 'delete_position',
                'description' => 'Delete a position. People holding it keep their records but lose the position.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'department' => $department,
                        'position' => ['type' => 'STRING', 'description' => 'The position title.'],
                    ],
                    'required' => ['department', 'position'],
                ],
            ],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'department', 'departments', 'org structure', 'org chart', 'organisation structure',
            'organization structure', 'organizational structure', 'organisational structure', 'hierarchy',
            'position', 'positions', 'sub-department', 'sub-departments', 'reporting line', 'departamento',
        ];
    }

    /**
     * The org structure in a few lines: counts, the tree, and the gaps.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('setup.departments.view')) {
            return null;
        }

        return ContextSection::of('Org structure', $this->structureLines(), 'Salary bands are not part of this; they are on the Departments screen.');
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findDepartments(User $user, array $args): ToolResult
    {
        $archived = ($args['archived'] ?? false) === true;

        $departments = Department::query()
            ->when($archived, fn (Builder $q) => $q->onlyTrashed())
            ->search((string) ($args['query'] ?? ''))
            ->with(['parent:id,name', 'head:id,first_name,middle_name,last_name,suffix'])
            ->withCount(['employees', 'positions', 'children'])
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();

        $cards = $departments->map(fn (Department $d): array => $this->departmentCard($d, 'find', 'neutral', $archived ? 'Archived' : $d->code))->all();

        return ToolResult::found($archived ? 'Searched archived departments' : 'Searched departments', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getDepartment(User $user, array $args): ToolResult
    {
        [$department, $error] = $this->locate((string) ($args['department'] ?? ''));

        if ($department === null) {
            return ToolResult::error('Looked up the department', $error);
        }

        $department->load([
            'parent:id,name',
            'head:id,first_name,middle_name,last_name,suffix',
            'children' => fn ($q) => $q->withCount('employees')->orderBy('name'),
            'positions' => fn ($q) => $q->withCount('employees')->orderBy('title'),
            'defaultWorkSchedule:id,name',
            'attendancePolicy:id,name',
        ])->loadCount(['employees', 'positions', 'children']);

        $card = $this->departmentCard($department, 'insight', $department->head_id === null ? 'warning' : 'info', $department->code);
        $card['meta'] = array_values(array_filter([
            $department->employees_count.' '.Str::plural('person', $department->employees_count).' in it directly',
            $department->head ? 'Head: '.$department->head->full_name : 'No head assigned',
            $department->parent ? 'Part of '.$department->parent->name : 'Top-level',
            $this->chain($department),
            $department->children->isNotEmpty()
                ? 'Sub-departments: '.$this->listed($department->children->map(fn (Department $c): string => "{$c->name} ({$c->employees_count})"))
                : null,
            $department->positions->isNotEmpty()
                ? 'Positions: '.$this->listed($department->positions->map(fn (Position $p): string => "{$p->title} ({$p->employees_count})"))
                : 'No positions defined',
            $department->defaultWorkSchedule ? 'Default schedule: '.$department->defaultWorkSchedule->name : null,
            $department->attendancePolicy ? 'Attendance policy: '.$department->attendancePolicy->name : null,
            filled($department->description) ? 'About: '.Str::limit((string) $department->description, 240) : null,
        ]));

        return ToolResult::found("Read {$department->name}", null, [$card]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function listPositions(User $user, array $args): ToolResult
    {
        $department = null;

        if (filled($args['department'] ?? null)) {
            [$department, $error] = $this->locate((string) $args['department']);

            if ($department === null) {
                return ToolResult::error('Looked up the department', $error);
            }
        }

        $query = trim((string) ($args['query'] ?? ''));
        $like = Position::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $positions = Position::query()
            ->with('department:id,name')
            ->withCount('employees')
            ->when($department !== null, fn (Builder $q) => $q->where('department_id', $department->id))
            ->when($query !== '', fn (Builder $q) => $q->where('title', $like, '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('title')
            ->limit(self::MAX_RESULTS)
            ->get();

        // No salary band: a band beside a title is an estimate of someone's pay.
        $cards = $positions->map(fn (Position $p): array => $this->card(
            kind: 'find',
            tone: 'neutral',
            badge: $p->employees_count.' '.Str::plural('holder', $p->employees_count),
            title: $p->title,
            subtitle: $p->department?->name ?? 'No department',
            meta: [filled($p->description) ? Str::limit((string) $p->description, 160) : null],
            id: $p->id,
        ))->all();

        return ToolResult::found('Listed positions', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function summary(User $user, array $args): ToolResult
    {
        $lines = $this->structureLines();

        return ToolResult::found('Read the org structure', null, [
            $this->card(
                kind: 'insight',
                tone: 'info',
                badge: 'Org structure',
                title: 'How the organisation is structured',
                subtitle: array_shift($lines),
                meta: $lines,
            ),
        ]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function createDepartment(User $user, array $args): ToolResult
    {
        [$fields, $error] = $this->departmentFields($args, [
            'name' => trim((string) ($args['name'] ?? '')),
            'code' => DepartmentRequest::normaliseCode((string) ($args['code'] ?? '')),
        ]);

        if ($error !== null) {
            return ToolResult::error('Created the department', $error);
        }

        if (($problem = $this->invalid($fields, DepartmentRequest::rulesFor(null), (new DepartmentRequest)->messages())) !== null) {
            return ToolResult::error('Created the department', $problem);
        }

        $department = $this->workflow->create($fields, ' via assistant');

        return ToolResult::ok(
            "Created {$department->name}",
            $department->code,
            $this->departmentCard($this->fresh($department), 'add', 'positive', 'Created'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateDepartment(User $user, array $args): ToolResult
    {
        [$department, $error] = $this->locate((string) ($args['department'] ?? ''));

        if ($department === null) {
            return ToolResult::error('Looked up the department', $error);
        }

        $seed = [];

        if (filled($args['new_name'] ?? null)) {
            $seed['name'] = trim((string) $args['new_name']);
        }

        if (filled($args['code'] ?? null)) {
            $seed['code'] = DepartmentRequest::normaliseCode((string) $args['code']);
        }

        [$changes, $error] = $this->departmentFields($args, $seed);

        if ($error !== null) {
            return ToolResult::error('Updated the department', $error);
        }

        if (($args['top_level'] ?? false) === true) {
            $changes['parent_id'] = null;
        }

        if (($args['remove_head'] ?? false) === true) {
            $changes['head_id'] = null;
        }

        if ($changes === []) {
            return ToolResult::error('Updated the department', 'Say what to change: its name, code, parent, head or description.');
        }

        // The department as it would be, against the screen's own rules — the
        // code still unique, the new parent not inside its own subtree.
        $merged = [
            ...Arr::only($department->getAttributes(), ['name', 'code', 'parent_id', 'head_id', 'default_work_schedule_id', 'attendance_policy_id', 'description']),
            ...$changes,
        ];

        if (($problem = $this->invalid($merged, DepartmentRequest::rulesFor($department), (new DepartmentRequest)->messages())) !== null) {
            return ToolResult::error('Updated the department', $problem);
        }

        $this->workflow->update($department, $changes, ' via assistant');

        return ToolResult::ok(
            "Updated {$department->name}",
            implode(', ', array_map(fn (string $key): string => str_replace(['_id', '_'], ['', ' '], $key), array_keys($changes))),
            $this->departmentCard($this->fresh($department), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveDepartment(User $user, array $args): ToolResult
    {
        [$department, $error] = $this->locate((string) ($args['department'] ?? ''));

        if ($department === null) {
            return ToolResult::error('Looked up the department', $error);
        }

        $department = $this->fresh($department);
        $card = $this->departmentCard($department, 'archive', 'warning', 'Archived');

        $this->workflow->archive($department, ' via assistant');

        return ToolResult::ok(
            "Archived {$department->name}",
            sprintf(
                '%d %s, %d %s and %d %s stay assigned to it; it can be restored.',
                $department->employees_count,
                Str::plural('person', $department->employees_count),
                $department->positions_count,
                Str::plural('position', $department->positions_count),
                $department->children_count,
                Str::plural('sub-department', $department->children_count),
            ),
            $card,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function restoreDepartment(User $user, array $args): ToolResult
    {
        [$department, $error] = $this->locate((string) ($args['department'] ?? ''), archived: true);

        if ($department === null) {
            return ToolResult::error('Looked up the archived department', $error);
        }

        try {
            $this->workflow->restore($department, ' via assistant');
        } catch (DepartmentException $e) {
            return ToolResult::error('Restored the department', $e->getMessage());
        }

        return ToolResult::ok("Restored {$department->name}", $department->code, $this->departmentCard($this->fresh($department), 'start', 'positive', 'Restored'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function addPosition(User $user, array $args): ToolResult
    {
        [$department, $error] = $this->locate((string) ($args['department'] ?? ''));

        if ($department === null) {
            return ToolResult::error('Looked up the department', $error);
        }

        $data = ['title' => trim((string) ($args['title'] ?? ''))];

        if (filled($args['description'] ?? null)) {
            $data['description'] = trim((string) $args['description']);
        }

        if (($problem = $this->invalid($data, $this->positionRules())) !== null) {
            return ToolResult::error('Added the position', $problem);
        }

        if ($department->positions()->whereRaw('lower(title) = ?', [Str::lower($data['title'])])->exists()) {
            return ToolResult::error('Added the position', "{$department->name} already has a position called “{$data['title']}”.");
        }

        $position = $this->workflow->addPosition($department, $data, ' via assistant');

        return ToolResult::ok("Added {$position->title} to {$department->name}", null, $this->positionCard($position->loadCount('employees'), $department, 'add', 'positive', 'Added'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updatePosition(User $user, array $args): ToolResult
    {
        [$position, $department, $error] = $this->locatePosition($args);

        if ($position === null) {
            return ToolResult::error('Looked up the position', $error);
        }

        $changes = [];

        if (filled($args['new_title'] ?? null)) {
            $changes['title'] = trim((string) $args['new_title']);
        }

        if (filled($args['description'] ?? null)) {
            $changes['description'] = trim((string) $args['description']);
        }

        if ($changes === []) {
            return ToolResult::error('Updated the position', 'Say what to change: its title or description.');
        }

        if (($problem = $this->invalid(['title' => $position->title, 'description' => $position->description, ...$changes], $this->positionRules())) !== null) {
            return ToolResult::error('Updated the position', $problem);
        }

        $this->workflow->updatePosition($position, $changes, ' via assistant');

        return ToolResult::ok("Updated {$position->title}", $department->name, $this->positionCard($position->loadCount('employees'), $department, 'edit', 'info', 'Updated'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function deletePosition(User $user, array $args): ToolResult
    {
        [$position, $department, $error] = $this->locatePosition($args);

        if ($position === null) {
            return ToolResult::error('Looked up the position', $error);
        }

        $position->loadCount('employees');
        $card = $this->positionCard($position, $department, 'cancel', 'warning', 'Deleted');
        $holders = (int) $position->employees_count;

        $this->workflow->deletePosition($position, ' via assistant');

        return ToolResult::ok(
            "Deleted {$position->title}",
            $holders > 0 ? "{$holders} ".Str::plural('person', $holders).' no longer '.($holders === 1 ? 'has' : 'have').' a position.' : null,
            $card,
        );
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one department for a name or code — an exact name or code first,
     * then a partial name only one department has.
     *
     * @return array{0: Department|null, 1: string}
     */
    private function locate(string $needle, bool $archived = false): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, 'Say which department.'];
        }

        $base = fn (): Builder => $archived ? Department::onlyTrashed() : Department::query();

        $exact = $base()
            ->where(fn (Builder $q) => $q
                ->whereRaw('lower(name) = ?', [Str::lower($needle)])
                ->orWhereRaw('lower(code) = ?', [Str::lower($needle)]))
            ->limit(2)
            ->get();

        $matches = $exact->isNotEmpty() ? $exact : $base()->search($needle)->orderBy('name')->limit(6)->get();

        return match (true) {
            $matches->isEmpty() => [null, ($archived ? 'No archived department' : 'No department').' matches “'.Str::limit($needle, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one department matches “'.Str::limit($needle, 60).'”: '.$matches->map(fn (Department $d): string => "{$d->name} ({$d->code})")->implode(', ').'.'],
        };
    }

    /**
     * One position by its title, within one department.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: Position|null, 1: Department|null, 2: string}
     */
    private function locatePosition(array $args): array
    {
        [$department, $error] = $this->locate((string) ($args['department'] ?? ''));

        if ($department === null) {
            return [null, null, $error];
        }

        $needle = Str::lower(trim((string) ($args['position'] ?? '')));

        if ($needle === '') {
            return [null, $department, 'Say which position.'];
        }

        $positions = $department->positions()->get();
        $exact = $positions->filter(fn (Position $p): bool => Str::lower($p->title) === $needle);
        $matches = $exact->isNotEmpty() ? $exact : $positions->filter(fn (Position $p): bool => str_contains(Str::lower($p->title), $needle));

        return match (true) {
            $matches->isEmpty() => [null, $department, "{$department->name} has no position matching “".Str::limit($needle, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), $department, ''],
            default => [null, $department, 'More than one position matches: '.$matches->pluck('title')->implode(', ').'.'],
        };
    }

    /**
     * The parent, head and description in the arguments, resolved within this
     * workspace — or why one of them is not usable.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $fields  Already-chosen values (name, code).
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function departmentFields(array $args, array $fields): array
    {
        if (filled($args['parent'] ?? null)) {
            [$parent, $error] = $this->locate((string) $args['parent']);

            if ($parent === null) {
                return [[], 'Parent: '.$error];
            }

            $fields['parent_id'] = $parent->id;
        }

        if (filled($args['head'] ?? null)) {
            [$head, $error] = $this->resolveEmployee((string) $args['head']);

            if ($head === null) {
                return [[], 'Head: '.$error];
            }

            $fields['head_id'] = $head->id;
        }

        if (filled($args['description'] ?? null)) {
            $fields['description'] = trim((string) $args['description']);
        }

        return [$fields, null];
    }

    /**
     * The position form's own rules, without the salary band (not handled here).
     *
     * @return array<string, mixed>
     */
    private function positionRules(): array
    {
        return Arr::only((new PositionRequest)->rules(), ['title', 'description']);
    }

    private function fresh(Department $department): Department
    {
        return Department::withTrashed()
            ->with(['parent:id,name', 'head:id,first_name,middle_name,last_name,suffix'])
            ->withCount(['employees', 'positions', 'children'])
            ->findOrFail($department->id);
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * The structure in lines: the counts, the tree two levels deep, and the gaps
     * — departments without a head, departments nobody is in.
     *
     * @return list<string>
     */
    private function structureLines(): array
    {
        $stats = $this->statistics->toArray();

        $departments = Department::query()
            ->with('head:id,first_name,middle_name,last_name,suffix')
            ->withCount('employees')
            ->orderBy('name')
            ->get();

        if ($departments->isEmpty()) {
            return ['No departments have been set up yet.'];
        }

        $byParent = $departments->groupBy(fn (Department $d): string => (string) ($d->parent_id ?? 'top'));
        $ids = $departments->pluck('id')->all();

        // A department whose parent is archived has no visible parent; it reads
        // as top-level rather than disappearing from the tree.
        $top = $departments->filter(fn (Department $d): bool => $d->parent_id === null || ! in_array($d->parent_id, $ids, true));

        $tree = $top->take(self::MAX_LISTED)->map(function (Department $d) use ($byParent): string {
            $children = $byParent->get((string) $d->id, collect());

            return "{$d->name} ({$d->employees_count})".($children->isNotEmpty()
                ? ' → '.$children->map(fn (Department $c): string => "{$c->name} ({$c->employees_count})")->implode(', ')
                : '');
        });

        $unheaded = $departments->whereNull('head_id');
        $empty = $departments->filter(fn (Department $d): bool => (int) $d->employees_count === 0);

        return array_values(array_filter([
            sprintf(
                '%d %s, %d %s; %d without a head.',
                $stats['departments'],
                Str::plural('department', $stats['departments']),
                $stats['positions'],
                Str::plural('position', $stats['positions']),
                $stats['unheaded'],
            ),
            'Structure (people in each): '.$tree->implode('; ').($top->count() > self::MAX_LISTED ? '; and '.($top->count() - self::MAX_LISTED).' more' : '').'.',
            'Heads: '.$this->listed($departments->whereNotNull('head_id')->map(fn (Department $d): string => "{$d->name} — ".($d->head?->full_name ?? 'unknown'))).'.',
            $unheaded->isNotEmpty() ? 'No head: '.$this->listed($unheaded->pluck('name')).'.' : null,
            $empty->isNotEmpty() ? 'Nobody assigned: '.$this->listed($empty->pluck('name')).'.' : null,
        ]));
    }

    /**
     * "Engineering › Platform › Payments" — where a nested department sits.
     */
    private function chain(Department $department): ?string
    {
        if ($department->parent_id === null) {
            return null;
        }

        $names = [$department->name];
        $seen = [$department->id];
        $current = $department;

        while ($current->parent_id !== null && ! in_array($current->parent_id, $seen, true) && count($names) < 8) {
            $current = Department::query()->find($current->parent_id, ['id', 'name', 'parent_id']);

            if ($current === null) {
                break;
            }

            $seen[] = $current->id;
            array_unshift($names, $current->name);
        }

        return 'Sits under: '.implode(' › ', $names);
    }

    /**
     * @param  Collection<int, string>  $items
     */
    private function listed(Collection $items): string
    {
        return $items->take(self::MAX_LISTED)->implode(', ').($items->count() > self::MAX_LISTED ? ' and '.($items->count() - self::MAX_LISTED).' more' : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function departmentCard(Department $department, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $department->name,
            subtitle: trim(($department->parent ? 'Under '.$department->parent->name.' · ' : '').($department->head ? 'Head: '.$department->head->full_name : 'No head')),
            meta: [
                isset($department->employees_count) ? $department->employees_count.' '.Str::plural('person', (int) $department->employees_count) : null,
                isset($department->positions_count) ? $department->positions_count.' '.Str::plural('position', (int) $department->positions_count) : null,
                ! empty($department->children_count) ? $department->children_count.' '.Str::plural('sub-department', (int) $department->children_count) : null,
            ],
            id: $department->hashid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function positionCard(Position $position, Department $department, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $position->title,
            subtitle: $department->name,
            meta: [isset($position->employees_count) ? $position->employees_count.' '.Str::plural('holder', (int) $position->employees_count) : null],
            id: $position->id,
        );
    }
}
