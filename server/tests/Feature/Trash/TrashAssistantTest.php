<?php

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Assistant\Modules\TrashModule;
use App\Services\Assistant\ToolResult;
use App\Support\Tenancy;

/*
| The trash-bin capability of the assistant: this workspace's archived
| records, restored or deleted one at a time by the Trash Bin's own path —
| each type by its own module's permissions. Gemini is never called.
*/

function binAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(TrashModule::class)->run($user, $tool, $args);
}

test('each type is offered by its own module’s permissions, and both writes wait for a confirm', function () {
    $module = app(TrashModule::class);
    $viewer = actingAsUserWith(['setup.departments.view']);
    $manager = actingAsUserWith(['setup.departments.view', 'setup.departments.manage']);

    expect($module->isAvailable(actingAsUserWith(['leave.view'])))->toBeFalse()
        ->and(array_column($module->tools($viewer), 'name'))->toBe(['find_trash'])
        ->and(array_column($module->tools($manager), 'name'))->toBe(['find_trash', 'restore_from_trash', 'delete_from_trash'])
        ->and($module->tools($viewer)[0]['parameters']['properties']['type']['enum'])->toBe(['department'])
        ->and($module->requiresConfirmation('restore_from_trash'))->toBeTrue()
        ->and($module->requiresConfirmation('delete_from_trash'))->toBeTrue()
        ->and(binAgent($viewer, 'restore_from_trash', ['type' => 'department', 'item' => 'x'])->detail)->toContain("don't have permission to restore departments");
});

test('archived records are listed, restored and deleted for good', function () {
    $user = actingAsSuperAdmin();
    $finance = Department::factory()->create(['name' => 'Finance', 'code' => 'FIN']);
    $finance->delete();
    $legal = Department::factory()->create(['name' => 'Legal', 'code' => 'LEG']);
    $legal->delete();
    $leaver = Employee::factory()->create(['first_name' => 'Lou', 'last_name' => 'Leaver']);
    $leaver->delete();

    $listed = collect(binAgent($user, 'find_trash')->cards)->pluck('title')->all();
    $module = app(TrashModule::class);

    expect($listed)->toContain('Finance', 'Legal', $leaver->full_name)
        ->and($module->consequence($user, 'delete_from_trash', ['type' => 'employee', 'item' => 'Lou Leaver']))->toContain('everything recorded against them')
        ->and($module->consequence($user, 'restore_from_trash', ['type' => 'department', 'item' => 'finance']))->toBe('Finance would be back in the lists it appears in.');

    $restored = binAgent($user, 'restore_from_trash', ['type' => 'department', 'item' => 'FIN']);
    $deleted = binAgent($user, 'delete_from_trash', ['type' => 'department', 'item' => 'Legal']);
    $missing = binAgent($user, 'restore_from_trash', ['type' => 'department', 'item' => 'Marketing']);

    expect($restored->label)->toBe('Restored Finance')
        ->and($finance->fresh()->trashed())->toBeFalse()
        ->and($deleted->label)->toBe('Deleted Legal for good')
        ->and(Department::withTrashed()->find($legal->id))->toBeNull()
        ->and($missing->detail)->toContain('No archived department matches')
        ->and(ActivityLog::query()->where('description', 'Restored Department Finance from the trash via assistant')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('description', 'Permanently deleted Department Legal via assistant')->exists())->toBeTrue();
});

test('an account is restored and deleted by the Users screen’s rules', function () {
    testOrganization();
    $boss = User::factory()->create(['first_name' => 'Bea', 'middle_name' => null, 'last_name' => 'Boss', 'suffix' => null]);
    $boss->roles()->attach(makeRole(Role::SUPER_ADMIN, [], true));
    $boss->delete();
    $pat = User::factory()->create(['first_name' => 'Pat', 'middle_name' => null, 'last_name' => 'Plain', 'suffix' => null]);
    $pat->delete();
    $user = actingAsUserWith(['users.view', 'users.restore', 'users.force-delete']);

    $bossGone = binAgent($user, 'delete_from_trash', ['type' => 'user', 'item' => 'Bea Boss']);
    $patBack = binAgent($user, 'restore_from_trash', ['type' => 'user', 'item' => $pat->email]);

    expect($bossGone->detail)->toContain("has access you don't")
        ->and(User::onlyTrashed()->find($boss->id))->not->toBeNull()
        ->and($patBack->failed())->toBeFalse()
        ->and($pat->fresh()->trashed())->toBeFalse()
        ->and(ActivityLog::query()->where('description', 'Restored user Pat Plain via assistant')->exists())->toBeTrue();
});

test('another workspace’s archived records are never found', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();

    app(Tenancy::class)->runFor(Organization::factory()->create(), function (): void {
        User::factory()->create(['first_name' => 'Nadia', 'last_name' => 'Elsewhere'])->delete();
        Department::factory()->create(['name' => 'Their Dept'])->delete();
    });
    app(Tenancy::class)->set($mine);

    expect(binAgent($user, 'find_trash')->cards)->toBe([])
        ->and(binAgent($user, 'restore_from_trash', ['type' => 'user', 'item' => 'Nadia Elsewhere'])->failed())->toBeTrue()
        ->and(binAgent($user, 'delete_from_trash', ['type' => 'department', 'item' => 'Their Dept'])->failed())->toBeTrue()
        ->and(User::onlyTrashed()->where('first_name', 'Nadia')->exists())->toBeTrue();
});
