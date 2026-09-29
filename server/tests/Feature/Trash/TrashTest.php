<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

// ── Listing ─────────────────────────────────────────────────────────────────

test('the trash bin renders for an admin', function () {
    actingAsSuperAdmin();

    $this->get(route('system.trash.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('system/trash/index')
            ->has('items.data')
            ->has('summary')
            ->has('filters'));
});

test('it lists soft-deleted records across types', function () {
    actingAsSuperAdmin();

    Department::factory()->create()->delete();
    User::factory()->create()->delete();

    $this->get(route('system.trash.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 2)
            ->where('summary.total', 2));
});

test('it filters the trash by type', function () {
    actingAsSuperAdmin();

    Department::factory()->create()->delete();
    User::factory()->create()->delete();

    $this->get(route('system.trash.index', ['type' => 'department']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.type', 'department'));
});

// ── Single-item actions ──────────────────────────────────────────────────────

test('it restores a single record', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create();
    $department->delete();

    $this->post(route('system.trash.restore'), [
        'type' => 'department',
        'id' => $department->id,
    ])->assertSessionHasNoErrors();

    expect($department->fresh()->trashed())->toBeFalse();
});

test('it permanently deletes a single record', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create();
    $department->delete();

    $this->post(route('system.trash.force-delete'), [
        'type' => 'department',
        'id' => $department->id,
    ])->assertSessionHasNoErrors();

    expect(Department::withTrashed()->find($department->id))->toBeNull();
});

// ── Bulk actions ─────────────────────────────────────────────────────────────

test('it bulk-restores selected records', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create();
    $department->delete();
    $leaveType = LeaveType::factory()->create();
    $leaveType->delete();

    $this->post(route('system.trash.bulk'), [
        'action' => 'restore',
        'items' => [
            ['type' => 'department', 'id' => $department->id],
            ['type' => 'leave_type', 'id' => $leaveType->id],
        ],
    ])->assertSessionHasNoErrors();

    expect($department->fresh()->trashed())->toBeFalse()
        ->and($leaveType->fresh()->trashed())->toBeFalse();
});

test('it empties the trash', function () {
    actingAsSuperAdmin();
    Department::factory()->create()->delete();
    User::factory()->create()->delete();

    $this->post(route('system.trash.empty'))->assertSessionHasNoErrors();

    expect(Department::onlyTrashed()->count())->toBe(0)
        ->and(User::onlyTrashed()->count())->toBe(0);
});

// ── Authorisation ────────────────────────────────────────────────────────────

test('a user only sees trash types they can view', function () {
    actingAsUserWith(['employees.view']);

    Employee::factory()->create()->delete();
    User::factory()->create()->delete();

    $this->get(route('system.trash.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.type', 'employee'));
});

test('it forbids the trash bin when no type is viewable', function () {
    actingAsUserWith(['roles.view']);

    $this->get(route('system.trash.index'))->assertForbidden();
});

test('it denies restoring a type the user cannot restore', function () {
    actingAsUserWith(['employees.view']);
    $employee = Employee::factory()->create();
    $employee->delete();

    $this->post(route('system.trash.restore'), [
        'type' => 'employee',
        'id' => $employee->id,
    ])->assertForbidden();

    expect($employee->fresh()->trashed())->toBeTrue();
});

test('guests cannot access the trash bin', function () {
    $this->get(route('system.trash.index'))->assertRedirect(route('login'));
});

// ── Tenancy ──────────────────────────────────────────────────────────────────

test('another company’s archived accounts are not in this bin', function () {
    // Users are identities with no tenant scope of their own (ADR 0023): the bin
    // used to list, count, restore and purge every archived account on the box.
    $theirs = app(Tenancy::class)->runFor(Organization::factory()->create(), function (): User {
        $user = User::factory()->create(['first_name' => 'Nadia', 'last_name' => 'Elsewhere']);
        $user->delete();

        return $user;
    });

    actingAsSuperAdmin();

    $this->get(route('system.trash.index'))
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 0)->where('summary.total', 0));

    $this->post(route('system.trash.restore'), ['type' => 'user', 'id' => $theirs->id])->assertNotFound();
    $this->post(route('system.trash.force-delete'), ['type' => 'user', 'id' => $theirs->id])->assertNotFound();
    $this->post(route('system.trash.bulk'), ['action' => 'delete', 'items' => [['type' => 'user', 'id' => $theirs->id]]]);
    $this->post(route('system.trash.empty'));

    expect(User::onlyTrashed()->find($theirs->id))->not->toBeNull();
});

test('the bin cannot delete an account the Users screen would refuse to', function () {
    testOrganization();
    $boss = User::factory()->create();
    $boss->roles()->attach(makeRole(Role::SUPER_ADMIN, [], true));
    $boss->delete();
    actingAsUserWith(['users.view', 'users.force-delete']);

    $this->post(route('system.trash.force-delete'), ['type' => 'user', 'id' => $boss->id]);
    assertToast('error', 'has access you don\'t');

    $this->post(route('system.trash.empty'));

    expect(User::onlyTrashed()->find($boss->id))->not->toBeNull();
});
