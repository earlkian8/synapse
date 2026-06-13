<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
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
