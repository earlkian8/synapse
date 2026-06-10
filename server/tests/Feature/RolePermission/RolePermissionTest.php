<?php

use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// ── Listing ─────────────────────────────────────────────────────────────────

test('the roles index renders with roles, stats, groups and filters', function () {
    actingAsSuperAdmin();
    makeRole('hr-manager', ['users.view']);

    $this->get(route('system.roles.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('system/roles/index')
            ->has('roles.data')
            ->has('roles.meta')
            ->has('stats')
            ->has('permissionGroups')
            ->has('filters'));
});

test('it filters roles by type', function () {
    actingAsSuperAdmin(); // creates a system role
    makeRole('custom-one'); // custom role

    $this->get(route('system.roles.index', ['type' => 'custom']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles.data', fn ($rows) => collect($rows)->every(fn ($r) => $r['is_system'] === false)));
});

test('it searches roles', function () {
    actingAsSuperAdmin();
    makeRole('warehouse-lead');

    $this->get(route('system.roles.index', ['search' => 'warehouse']))
        ->assertInertia(fn (Assert $page) => $page->has('roles.data', 1));
});

// ── Mutations ───────────────────────────────────────────────────────────────

test('it creates a role with permissions', function () {
    actingAsSuperAdmin();

    $this->post(route('system.roles.store'), [
        'label' => 'Auditor',
        'name' => 'auditor',
        'permissions' => ['activity-logs.view', 'activity-logs.export'],
    ])->assertSessionHasNoErrors();

    $role = Role::where('name', 'auditor')->first();

    expect($role)->not->toBeNull()
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['activity-logs.export', 'activity-logs.view']);
});

test('it derives the role key from the label when omitted', function () {
    actingAsSuperAdmin();

    $this->post(route('system.roles.store'), [
        'label' => 'Branch Manager',
        'name' => '',
    ])->assertSessionHasNoErrors();

    expect(Role::where('name', 'branch-manager')->exists())->toBeTrue();
});

test('it rejects a duplicate role key', function () {
    actingAsSuperAdmin();
    makeRole('auditor');

    $this->post(route('system.roles.store'), [
        'label' => 'Auditor',
        'name' => 'auditor',
    ])->assertSessionHasErrors('name');
});

test('it rejects an unknown permission', function () {
    actingAsSuperAdmin();

    $this->post(route('system.roles.store'), [
        'label' => 'Bad',
        'name' => 'bad',
        'permissions' => ['nope.invalid'],
    ])->assertSessionHasErrors('permissions.0');
});

test('it updates a role and syncs its permissions', function () {
    actingAsSuperAdmin();
    $role = makeRole('auditor', ['activity-logs.view']);

    $this->patch(route('system.roles.update', $role), [
        'label' => 'Senior Auditor',
        'permissions' => ['activity-logs.view', 'activity-logs.delete'],
    ])->assertSessionHasNoErrors();

    $role->refresh();

    expect($role->label)->toBe('Senior Auditor')
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['activity-logs.delete', 'activity-logs.view']);
});

test('the super admin role cannot be modified', function () {
    actingAsSuperAdmin();
    $role = Role::where('name', Role::SUPER_ADMIN)->first();

    $this->patch(route('system.roles.update', $role), [
        'label' => 'Hacked',
        'permissions' => [],
    ]);

    expect($role->fresh()->label)->not->toBe('Hacked');
});

test('it deletes a custom role', function () {
    actingAsSuperAdmin();
    $role = makeRole('temp-role');

    $this->delete(route('system.roles.destroy', $role))->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('roles', ['id' => $role->id]);
});

test('a system role cannot be deleted', function () {
    actingAsSuperAdmin();
    $role = makeRole('locked', [], system: true);

    $this->delete(route('system.roles.destroy', $role));

    $this->assertDatabaseHas('roles', ['id' => $role->id]);
});

test('it exports roles as a csv download', function () {
    actingAsSuperAdmin();
    makeRole('exportable-role');

    $response = $this->get(route('system.roles.export'));

    $response->assertOk();
    $response->assertDownload();
    expect($response->streamedContent())
        ->toContain('Label')
        ->toContain('exportable-role');
});

// ── Authorization ───────────────────────────────────────────────────────────

test('a user without roles.view cannot see the roles index', function () {
    actingAsUserWith(['users.view']);

    $this->get(route('system.roles.index'))->assertForbidden();
});

test('a user with only roles.view cannot create a role', function () {
    actingAsUserWith(['roles.view']);

    $this->post(route('system.roles.store'), [
        'label' => 'Nope',
        'name' => 'nope',
    ])->assertForbidden();

    expect(Role::where('name', 'nope')->exists())->toBeFalse();
});

test('a user without users.view is denied user management', function () {
    actingAsUserWith(['roles.view']);

    $this->get(route('system.users.index'))->assertForbidden();
});

test('a user without users.force-delete cannot permanently delete', function () {
    actingAsUserWith(['users.view', 'users.delete']);
    $target = User::factory()->create();
    $target->delete();

    $this->delete(route('system.users.force-delete', $target->id))->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $target->id]);
});

test('a super admin bypasses every gate', function () {
    actingAsSuperAdmin();

    $this->get(route('system.users.index'))->assertOk();
    $this->get(route('system.activity-logs.index'))->assertOk();
    $this->get(route('system.roles.index'))->assertOk();
});

// ── Role assignment through user management ──────────────────────────────────

test('a permitted actor can assign roles when creating a user', function () {
    actingAsUserWith(['users.create', 'roles.assign']);
    $role = makeRole('staff');

    $this->post(route('system.users.store'), [
        'first_name' => 'Role',
        'last_name' => 'Holder',
        'email' => 'holder@example.com',
        'is_active' => true,
        'manage_roles' => true,
        'roles' => [$role->id],
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'holder@example.com')->first();

    expect($user->roles->pluck('name')->all())->toContain('staff');
});

test('an actor without roles.assign cannot attach roles', function () {
    actingAsUserWith(['users.create']);
    $role = makeRole('staff');

    $this->post(route('system.users.store'), [
        'first_name' => 'No',
        'last_name' => 'Roles',
        'email' => 'noroles@example.com',
        'is_active' => true,
        'manage_roles' => true,
        'roles' => [$role->id],
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'noroles@example.com')->first();

    expect($user->roles)->toHaveCount(0);
});

// ── Model behaviour ─────────────────────────────────────────────────────────

test('hasPermissionTo reflects the user roles', function () {
    seedPermissions();
    $role = makeRole('viewer', ['users.view']);
    $user = User::factory()->create();
    $user->roles()->attach($role);

    expect($user->hasPermissionTo('users.view'))->toBeTrue()
        ->and($user->hasPermissionTo('users.delete'))->toBeFalse();
});

test('a role assignment is reflected in shared permission names', function () {
    $role = makeRole('viewer', ['users.view', 'roles.view']);
    $user = User::factory()->create();
    $user->roles()->attach($role);

    expect($user->permissionNames()->sort()->values()->all())
        ->toBe(['roles.view', 'users.view']);
});
