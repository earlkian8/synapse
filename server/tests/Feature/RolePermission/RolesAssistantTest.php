<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Assistant\Modules\RolesModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\PermissionRegistry;
use App\Support\Tenancy;

/*
| The roles capability of the assistant: what each role lets its holders do,
| named the way people say it, and changed by the Roles screen's own path —
| never granting what the asker does not hold. Gemini is never called.
*/

function rolesAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(RolesModule::class)->run($user, $tool, $args);
}

/** @return list<string> */
function grants(string $label): array
{
    return Role::query()->where('label', $label)->sole()->permissions()->orderBy('name')->pluck('name')->all();
}

test('permissions are found by what they let someone do', function () {
    expect(PermissionRegistry::lookup('leave.manage'))->toBe([['leave.manage'], ''])
        ->and(PermissionRegistry::lookup('Approve / reject leave & set balances')[0])->toBe(['leave.manage'])
        ->and(PermissionRegistry::lookup('leave management')[0])->toBe(['leave.view', 'leave.request', 'leave.manage'])
        ->and(PermissionRegistry::lookup('export employees')[0])->toBe(['employees.export'])
        ->and(PermissionRegistry::lookup('export')[1])->toContain('More than one permission matches')
        ->and(PermissionRegistry::lookup('fly a plane')[1])->toContain('No permission is called');
});

test('only the screen’s permissions open the tools; changes that reach every holder wait for a confirm', function () {
    $module = app(RolesModule::class);

    expect($module->isAvailable(actingAsUserWith(['users.view'])))->toBeFalse()
        ->and(array_column($module->tools(actingAsUserWith(['roles.view'])), 'name'))->toBe(['find_roles', 'get_role', 'find_permissions'])
        ->and($module->requiresConfirmation('grant_role_permissions'))->toBeTrue()
        ->and($module->requiresConfirmation('revoke_role_permissions'))->toBeTrue()
        ->and($module->requiresConfirmation('delete_role'))->toBeTrue()
        ->and($module->requiresConfirmation('create_role'))->toBeFalse();
});

test('roles are read with their permissions and holders, and found by what they grant', function () {
    $user = actingAsSuperAdmin();
    $approver = makeRole('leave-approver', ['leave.view', 'leave.manage']);
    User::factory()->create(['first_name' => 'Lena', 'middle_name' => null, 'last_name' => 'Approves', 'suffix' => null])->roles()->attach($approver);
    makeRole('viewer', ['users.view']);

    $meta = implode(' | ', rolesAgent($user, 'get_role', ['role' => 'leave approver'])->cards[0]['meta']);
    $granting = collect(rolesAgent($user, 'find_roles', ['permission' => 'approve leave'])->cards)->pluck('title')->all();
    $which = rolesAgent($user, 'find_permissions', ['search' => 'approve leave'])->cards;

    expect($meta)->toContain('Leave Management: View leave requests & balances, Approve / reject leave & set balances')
        ->toContain('Held by: Lena Approves')
        ->and($granting)->toContain('Leave Approver')
        ->and($granting)->not->toContain('Viewer')
        ->and($which[0]['subtitle'])->toBe('leave.manage')
        ->and($which[0]['meta'][0])->toContain('Leave Approver');
});

test('a role is created from permissions or a copy, and renamed — within the actor’s own access', function () {
    makeRole('clerk', ['users.view', 'employees.view']);
    $user = actingAsUserWith(['roles.view', 'roles.create', 'roles.update', 'users.view', 'employees.view']);

    $made = rolesAgent($user, 'create_role', ['label' => 'Records Clerk', 'permissions' => ['view users']]);
    $copy = rolesAgent($user, 'create_role', ['label' => 'Senior Clerk', 'copy_from' => 'Clerk', 'permissions' => ['view roles']]);
    $beyond = rolesAgent($user, 'create_role', ['label' => 'Leave Boss', 'permissions' => ['approve leave']]);
    $taken = rolesAgent($user, 'create_role', ['label' => 'records clerk']);
    $renamed = rolesAgent($user, 'update_role', ['role' => 'Records Clerk', 'new_label' => 'Records Officer']);

    expect($made->failed())->toBeFalse()
        ->and(grants('Records Officer'))->toBe(['users.view'])
        ->and(grants('Senior Clerk'))->toBe(['employees.view', 'roles.view', 'users.view'])
        ->and($copy->failed())->toBeFalse()
        ->and($beyond->detail)->toContain('You can only grant access you have yourself')
        ->and(Role::query()->where('label', 'Leave Boss')->exists())->toBeFalse()
        ->and($taken->failed())->toBeTrue()
        ->and($renamed->label)->toBe('Updated the Records Officer role')
        ->and(ActivityLog::query()->where('description', 'Created role Records Clerk via assistant')->exists())->toBeTrue();
});

test('granting and revoking reach every holder, and the card says so', function () {
    $user = actingAsSuperAdmin();
    $clerk = makeRole('clerk', ['users.view']);
    User::factory()->count(2)->create()->each(fn (User $u) => $u->roles()->attach($clerk));
    $module = app(RolesModule::class);

    expect($module->consequence($user, 'grant_role_permissions', ['role' => 'Clerk', 'permissions' => ['leave management']]))
        ->toBe('2 people hold the Clerk role and would gain View leave requests & balances, File & cancel leave requests, Approve / reject leave & set balances.');

    $granted = rolesAgent($user, 'grant_role_permissions', ['role' => 'Clerk', 'permissions' => ['leave management']]);
    $revoked = rolesAgent($user, 'revoke_role_permissions', ['role' => 'Clerk', 'permissions' => ['file & cancel leave requests']]);
    $nothing = rolesAgent($user, 'revoke_role_permissions', ['role' => 'Clerk', 'permissions' => ['export employees']]);
    $manager = rolesAgent($user, 'grant_role_permissions', ['role' => 'Hr Manager', 'permissions' => ['users.view']]);

    expect($granted->detail)->toBe('2 holders gained 3 permissions.')
        ->and($revoked->failed())->toBeFalse()
        ->and(grants('Clerk'))->toBe(['leave.manage', 'leave.view', 'users.view'])
        ->and($nothing->detail)->toContain('does not grant Export employees')
        ->and($manager->failed())->toBeTrue();
});

test('built-in roles stay; a custom one is deleted and its holders lose it', function () {
    $user = actingAsSuperAdmin();
    makeRole('staff', [], system: true);
    $temp = makeRole('temp', ['users.view']);
    User::factory()->create()->roles()->attach($temp);

    $builtIn = rolesAgent($user, 'delete_role', ['role' => 'Staff']);

    expect(app(RolesModule::class)->consequence($user, 'delete_role', ['role' => 'Temp']))->toBe('1 person holds the Temp role and would lose what only it gives them.');

    $deleted = rolesAgent($user, 'delete_role', ['role' => 'Temp']);

    expect($builtIn->detail)->toBe('Built-in system roles cannot be deleted.')
        ->and($deleted->detail)->toBe('1 person no longer holds it.')
        ->and(Role::query()->where('name', 'temp')->exists())->toBeFalse();
});

test('another workspace’s roles are never found or changed, and a question is answered from the brief', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();
    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => Role::create(['name' => 'their-role', 'label' => 'Their Role']));
    app(Tenancy::class)->set($mine);

    expect(rolesAgent($user, 'get_role', ['role' => 'Their Role'])->failed())->toBeTrue()
        ->and(rolesAgent($user, 'delete_role', ['role' => 'Their Role'])->failed())->toBeTrue()
        ->and(collect(rolesAgent($user, 'find_roles')->cards)->pluck('title'))->not->toContain('Their Role')
        ->and(app(Retriever::class)->retrieve($user, 'which role can export employees?')?->toPrompt())
        ->toContain('Hr Manager (every permission; 1 holder; built-in)');
});
