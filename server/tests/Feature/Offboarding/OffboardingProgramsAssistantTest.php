<?php

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\OffboardingProgram;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assistant\Modules\OffboardingProgramsModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Offboarding\OffboardingWorkflow;
use App\Support\OffboardingProvisioner;
use App\Support\Tenancy;

/*
| The offboarding-programs capability of the assistant: the clearance
| templates exits are seeded from, changed by the screen's own rules — and an
| exit in flight keeps the checklist it was given. Gemini is never called.
*/

function templatesAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(OffboardingProgramsModule::class)->run($user, $tool, $args);
}

/** @return list<string> */
function signOffs(string $template): array
{
    return OffboardingProgram::query()->where('name', $template)->firstOrFail()->items()->pluck('item')->all();
}

test('only those who manage templates get the tools; targeting and deleting wait for a confirm', function () {
    $module = app(OffboardingProgramsModule::class);

    expect($module->isAvailable(actingAsUserWith(['offboarding.view'])))->toBeFalse()
        ->and(array_column($module->tools(actingAsUserWith(['offboarding.manage-programs'])), 'name'))->toContain('create_clearance_template', 'set_clearance_template_item')
        ->and($module->requiresConfirmation('update_clearance_template'))->toBeTrue()
        ->and($module->requiresConfirmation('delete_clearance_template'))->toBeTrue()
        ->and($module->requiresConfirmation('set_clearance_template_item'))->toBeFalse();
});

test('a template is made from items, a copy, or the standard list — owners resolved in this workspace', function () {
    $user = actingAsSuperAdmin();
    $it = Department::factory()->create(['name' => 'Information Technology', 'code' => 'IT']);
    Department::factory()->create(['name' => 'Finance', 'code' => 'FIN']);

    $items = templatesAgent($user, 'create_clearance_template', ['name' => 'IT exits', 'department' => 'it', 'items' => [
        ['item' => 'Return laptop', 'owner' => 'IT'],
        ['item' => 'Hand over files', 'owner' => 'own'],
        ['item' => 'Exit interview'],
    ]]);
    $standard = templatesAgent($user, 'create_clearance_template', ['name' => 'Everyone', 'standard' => true]);
    $copy = templatesAgent($user, 'create_clearance_template', ['name' => 'Retirement', 'copy_from' => 'IT exits', 'exit_type' => 'retirement']);
    $both = templatesAgent($user, 'create_clearance_template', ['name' => 'Both', 'standard' => true, 'copy_from' => 'IT exits']);
    $unknown = templatesAgent($user, 'create_clearance_template', ['name' => 'Odd', 'items' => [['item' => 'Sign', 'owner' => 'Legal']]]);
    $taken = templatesAgent($user, 'create_clearance_template', ['name' => 'it EXITS', 'standard' => true]);

    $template = OffboardingProgram::query()->where('name', 'IT exits')->firstOrFail();
    $rows = $template->items()->get();

    expect($items->failed())->toBeFalse()
        ->and($template->department_id)->toBe($it->id)
        ->and($rows[0]->department_id)->toBe($it->id)
        ->and($rows[1]->use_employee_department)->toBeTrue()
        ->and($rows[2]->department_id)->toBeNull()
        ->and(signOffs('Everyone'))->toHaveCount(count(OffboardingProvisioner::STANDARD_ITEMS))
        ->and(signOffs('Retirement'))->toBe(['Return laptop', 'Hand over files', 'Exit interview'])
        ->and($copy->failed() || $standard->failed())->toBeFalse()
        ->and($both->failed())->toBeTrue()
        ->and($unknown->detail)->toContain('No department is called “Legal”')
        ->and($taken->detail)->toContain('already a clearance template called')
        ->and($standard->detail)->toContain('only once it is made the default')
        ->and(ActivityLog::query()->where('description', 'Created clearance template "IT exits" via assistant')->exists())->toBeTrue();
});

test('a template reads out its sign-offs and whose exit it would seed', function () {
    $user = actingAsSuperAdmin();
    $sales = Department::factory()->create(['name' => 'Sales', 'code' => 'SAL']);
    Employee::factory()->count(2)->create(['department_id' => $sales->id]);
    Employee::factory()->create();
    templatesAgent($user, 'create_clearance_template', ['name' => 'Sales exits', 'department' => 'Sales', 'items' => [['item' => 'Hand over accounts', 'owner' => 'own']]]);

    $meta = implode(' | ', templatesAgent($user, 'get_clearance_template', ['template' => 'sales'])->cards[0]['meta']);

    expect($meta)->toContain("1. Hand over accounts — the leaver's own department")
        ->toContain('Would seed the exit of 2 people leaving by resignation today');

    expect(app(Retriever::class)->retrieve($user, 'what is on our exit checklist?')?->toPrompt())
        ->toContain('Sales exits (Sales leavers; 1 sign-off)');
});

test('sign-offs are added, reworded, re-owned and removed; exits in flight keep theirs', function () {
    $user = actingAsSuperAdmin();
    Department::factory()->create(['name' => 'Human Resources', 'code' => 'HR']);
    templatesAgent($user, 'create_clearance_template', ['name' => 'Default exit', 'items' => [['item' => 'Return ID', 'owner' => 'HR'], ['item' => 'Exit interview', 'owner' => 'HR']]]);
    templatesAgent($user, 'update_clearance_template', ['template' => 'Default exit', 'default' => true]);

    $leaver = Employee::factory()->create();
    $case = app(OffboardingWorkflow::class)->start($leaver, ['type' => 'resignation'], null);

    templatesAgent($user, 'set_clearance_template_item', ['template' => 'Default exit', 'item' => 'Return laptop', 'owner' => 'own']);
    templatesAgent($user, 'set_clearance_template_item', ['template' => 'Default exit', 'item' => 'return id', 'new_item' => 'Return ID and keys']);
    $ambiguous = templatesAgent($user, 'remove_clearance_template_item', ['template' => 'Default exit', 'item' => 'Return']);
    templatesAgent($user, 'remove_clearance_template_item', ['template' => 'Default exit', 'item' => 'exit interview']);

    expect(signOffs('Default exit'))->toBe(['Return ID and keys', 'Return laptop'])
        ->and($ambiguous->detail)->toContain('More than one sign-off matches')
        ->and($case->clearanceItems()->pluck('item')->all())->toBe(['Return ID', 'Exit interview']);
});

test('the card says whose exit a template would seed before it is re-targeted or deleted', function () {
    $user = actingAsSuperAdmin();
    Employee::factory()->count(3)->create();
    templatesAgent($user, 'create_clearance_template', ['name' => 'Default exit', 'standard' => true]);
    templatesAgent($user, 'update_clearance_template', ['template' => 'Default exit', 'default' => true]);
    app(OffboardingWorkflow::class)->start(Employee::query()->first(), ['type' => 'resignation'], null);

    $module = app(OffboardingProgramsModule::class);

    expect($module->consequence($user, 'delete_clearance_template', ['template' => 'Default exit']))
        ->toContain('seed the exit of 3 people leaving by resignation')
        ->toContain('1 exit in flight came from it');

    templatesAgent($user, 'delete_clearance_template', ['template' => 'Default exit']);

    expect(OffboardingProgram::query()->count())->toBe(0)
        ->and(OffboardingCase::query()->sole()->clearanceItems()->count())->toBe(count(OffboardingProvisioner::STANDARD_ITEMS));
});

test('another workspace’s templates are never found or changed', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => OffboardingProgram::create(['name' => 'Their exit', 'is_active' => true]));
    app(Tenancy::class)->set($mine);

    expect(templatesAgent($user, 'get_clearance_template', ['template' => 'Their exit'])->failed())->toBeTrue()
        ->and(templatesAgent($user, 'delete_clearance_template', ['template' => 'Their exit'])->failed())->toBeTrue()
        ->and(templatesAgent($user, 'find_clearance_templates')->cards)->toBe([]);
});
