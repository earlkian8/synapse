<?php

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Services\Assistant\Modules\DepartmentsModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Tenancy;

/*
| The org-structure capability of the assistant: the department tree, its
| heads and positions, changed by the Departments screen's own rules — and
| never a word about pay. Gemini is never called.
*/

function departmentsAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(DepartmentsModule::class)->run($user, $tool, $args);
}

function departmentsAgentTools(User $user): array
{
    return array_column(app(DepartmentsModule::class)->tools($user), 'name');
}

function unit(string $name, string $code, ?Department $parent = null): Department
{
    return Department::factory()->create(['name' => $name, 'code' => $code, 'parent_id' => $parent?->id]);
}

// ── Permissions ──────────────────────────────────────────────────────────────

test('a viewer reads; a manager changes; permanent deletion is never offered', function () {
    $viewer = actingAsUserWith(['setup.departments.view']);

    expect(departmentsAgentTools($viewer))->toEqualCanonicalizing(['find_departments', 'get_department', 'list_positions', 'org_structure_summary']);

    $manager = actingAsUserWith(['setup.departments.view', 'setup.departments.manage']);

    expect(departmentsAgentTools($manager))->toContain('create_department', 'update_department', 'archive_department', 'add_position')
        ->not->toContain('delete_department', 'force_delete_department');
});

test('a write is refused at run time without setup.departments.manage', function () {
    actingAsSuperAdmin();
    unit('Finance', 'FIN');

    $viewer = actingAsUserWith(['setup.departments.view']);

    expect(departmentsAgent($viewer, 'archive_department', ['department' => 'Finance'])->detail)->toContain('permission')
        ->and(Department::query()->where('code', 'FIN')->exists())->toBeTrue();
});

test('archiving a department and deleting a position wait for confirmation', function () {
    $module = app(DepartmentsModule::class);

    expect($module->requiresConfirmation('archive_department'))->toBeTrue()
        ->and($module->requiresConfirmation('delete_position'))->toBeTrue()
        ->and($module->requiresConfirmation('update_department'))->toBeFalse()
        ->and($module->isReadOnly('org_structure_summary'))->toBeTrue();
});

// ── Pay stays out ────────────────────────────────────────────────────────────

test('no read, and no tool, carries a salary band', function () {
    $user = actingAsSuperAdmin();
    $finance = unit('Finance', 'FIN');
    Position::factory()->create(['department_id' => $finance->id, 'title' => 'Analyst', 'salary_grade_min' => 45000, 'salary_grade_max' => 70000]);

    $read = json_encode([
        departmentsAgent($user, 'get_department', ['department' => 'Finance'])->cards,
        departmentsAgent($user, 'list_positions', ['department' => 'FIN'])->cards,
        app(DepartmentsModule::class)->tools($user),
    ]);

    expect($read)->toContain('Analyst')
        ->not->toContain('45000')->not->toContain('45,000')->not->toContain('70000')
        ->not->toContain('salary_grade');
});

// ── Reading ──────────────────────────────────────────────────────────────────

test('a department read-out has its head, place in the tree, sub-departments and positions', function () {
    $user = actingAsSuperAdmin();
    $ops = unit('Operations', 'OPS');
    $logistics = unit('Logistics', 'LOG', $ops);
    unit('Fleet', 'FLT', $logistics);
    $head = Employee::factory()->create(['first_name' => 'Rosa', 'middle_name' => null, 'last_name' => 'Diaz', 'suffix' => null, 'department_id' => $logistics->id]);
    $logistics->update(['head_id' => $head->id]);
    Position::factory()->create(['department_id' => $logistics->id, 'title' => 'Dispatcher']);

    $meta = implode(' | ', departmentsAgent($user, 'get_department', ['department' => 'log'])->cards[0]['meta']);

    expect($meta)->toContain('Head: Rosa Diaz')
        ->toContain('Sits under: Operations › Logistics')
        ->toContain('Sub-departments: Fleet (0)')
        ->toContain('Positions: Dispatcher (0)');
});

test('a question about the structure reads the tree and its gaps', function () {
    $user = actingAsSuperAdmin();
    $ops = unit('Operations', 'OPS');
    unit('Logistics', 'LOG', $ops);
    Employee::factory()->create(['department_id' => $ops->id]);

    $brief = app(Retriever::class)->retrieve($user, 'how is our org structure set up?');

    expect($brief?->isAboutWorkspace())->toBeTrue()
        ->and($brief->toPrompt())
        ->toContain('Operations (1) → Logistics (0)')
        ->toContain('No head: Logistics, Operations')
        ->toContain('Nobody assigned: Logistics');
});

// ── Doing ────────────────────────────────────────────────────────────────────

test('creating follows the screen’s rules: upper-cased code, unique in this workspace', function () {
    $user = actingAsSuperAdmin();
    unit('Finance', 'FIN');

    $created = departmentsAgent($user, 'create_department', ['name' => 'Treasury', 'code' => ' trs ', 'parent' => 'Finance']);
    $duplicate = departmentsAgent($user, 'create_department', ['name' => 'Fintech', 'code' => 'fin']);

    expect($created->failed())->toBeFalse()
        ->and(Department::query()->where('name', 'Treasury')->first()?->code)->toBe('TRS')
        ->and(Department::query()->where('name', 'Treasury')->first()?->parent?->name)->toBe('Finance')
        ->and($duplicate->failed())->toBeTrue()
        ->and(ActivityLog::query()->where('log_name', 'company-setup')->latest('id')->value('description'))->toBe('Created department "Treasury" via assistant');
});

test('a department cannot be moved under its own subtree, and can be made top-level', function () {
    $user = actingAsSuperAdmin();
    $ops = unit('Operations', 'OPS');
    $logistics = unit('Logistics', 'LOG', $ops);
    unit('Fleet', 'FLT', $logistics);

    $cycle = departmentsAgent($user, 'update_department', ['department' => 'Operations', 'parent' => 'Fleet']);

    expect($cycle->failed())->toBeTrue()
        ->and($cycle->detail)->toBe('A department cannot be nested under itself or one of its sub-departments.')
        ->and($ops->refresh()->parent_id)->toBeNull();

    expect(departmentsAgent($user, 'update_department', ['department' => 'Logistics', 'top_level' => true])->failed())->toBeFalse()
        ->and($logistics->refresh()->parent_id)->toBeNull();
});

test('a head is one employee of this workspace, and can be removed', function () {
    $user = actingAsSuperAdmin();
    $finance = unit('Finance', 'FIN');
    Employee::factory()->create(['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos', 'suffix' => null]);
    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => Employee::factory()->create(['first_name' => 'Zed', 'last_name' => 'Outsider']));

    expect(departmentsAgent($user, 'update_department', ['department' => 'Finance', 'head' => 'Zed Outsider'])->failed())->toBeTrue()
        ->and(departmentsAgent($user, 'update_department', ['department' => 'Finance', 'head' => 'Maria Santos'])->failed())->toBeFalse()
        ->and($finance->refresh()->head?->full_name)->toBe('Maria Santos')
        ->and(departmentsAgent($user, 'update_department', ['department' => 'Finance', 'remove_head' => true])->failed())->toBeFalse()
        ->and($finance->refresh()->head_id)->toBeNull();
});

test('archiving says what stays assigned; restoring refuses a code taken meanwhile', function () {
    $user = actingAsSuperAdmin();
    $finance = unit('Finance', 'FIN');
    Employee::factory()->count(2)->create(['department_id' => $finance->id]);

    $archived = departmentsAgent($user, 'archive_department', ['department' => 'Finance']);

    expect($archived->detail)->toContain('2 people')
        ->and(Department::query()->find($finance->id))->toBeNull();

    unit('Fintech', 'FIN');

    expect(departmentsAgent($user, 'restore_department', ['department' => 'Finance'])->detail)->toBe('Another department already uses the code "FIN".');
});

test('positions are added once, renamed, and deleted with the holder count', function () {
    $user = actingAsSuperAdmin();
    $finance = unit('Finance', 'FIN');
    departmentsAgent($user, 'add_position', ['department' => 'Finance', 'title' => 'Analyst']);

    expect(departmentsAgent($user, 'add_position', ['department' => 'Finance', 'title' => 'analyst'])->failed())->toBeTrue();

    departmentsAgent($user, 'update_position', ['department' => 'Finance', 'position' => 'Analyst', 'new_title' => 'Financial Analyst']);
    $position = $finance->positions()->firstOrFail();
    Employee::factory()->create(['department_id' => $finance->id, 'position_id' => $position->id]);

    expect($position->title)->toBe('Financial Analyst')
        ->and(departmentsAgent($user, 'delete_position', ['department' => 'Finance', 'position' => 'financial'])->detail)->toBe('1 person no longer has a position.')
        ->and($finance->positions()->count())->toBe(0);
});

test('another workspace’s departments are invisible', function () {
    $user = actingAsSuperAdmin();

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => unit('Secret Ops', 'SEC'));

    expect(departmentsAgent($user, 'get_department', ['department' => 'Secret Ops'])->failed())->toBeTrue()
        ->and(departmentsAgent($user, 'find_departments', ['query' => 'Secret'])->cards)->toBe([]);
});
