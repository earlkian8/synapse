<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Position;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

// ── Listing ─────────────────────────────────────────────────────────────────

test('the departments page renders', function () {
    actingAsSuperAdmin();
    Department::factory()->count(2)->create();

    $this->get(route('setup.departments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('setup/departments')
            ->has('departments', 2)
            ->has('archived')
            ->has('stats')
            ->has('options.employees')
            ->has('can'));
});

test('show returns a department with its positions', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create();
    Position::factory()->count(3)->create(['department_id' => $department->id]);

    $this->getJson(route('setup.departments.show', $department))
        ->assertOk()
        ->assertJsonPath('data.code', $department->code)
        ->assertJsonCount(3, 'data.positions');
});

// ── Departments ──────────────────────────────────────────────────────────────

test('it creates a department and uppercases the code', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.departments.store'), [
        'name' => 'Quality Assurance',
        'code' => 'qa',
    ])->assertSessionHasNoErrors();

    expect(Department::where('code', 'QA')->exists())->toBeTrue();
});

test('it validates a required name and code', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.departments.store'), [])
        ->assertSessionHasErrors(['name', 'code']);
});

test('it rejects a duplicate code within the tenant', function () {
    actingAsSuperAdmin();
    Department::factory()->create(['code' => 'OPS']);

    $this->post(route('setup.departments.store'), ['name' => 'Other', 'code' => 'OPS'])
        ->assertSessionHasErrors('code');
});

test('a code may be reused across organisations', function () {
    actingAsSuperAdmin();
    Department::factory()->create(['code' => 'HR']);

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, function () {
        expect(fn () => Department::factory()->create(['code' => 'HR']))->not->toThrow(Exception::class);
    });
});

test('it updates a department', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create();

    $this->post(route('setup.departments.update', $department), [
        'name' => 'Renamed',
        'code' => $department->code,
    ])->assertSessionHasNoErrors();

    expect($department->fresh()->name)->toBe('Renamed');
});

test('a department cannot be parented under one of its descendants', function () {
    actingAsSuperAdmin();
    $parent = Department::factory()->create(['code' => 'P']);
    $child = Department::factory()->create(['code' => 'C', 'parent_id' => $parent->id]);

    $this->post(route('setup.departments.update', $parent), [
        'name' => $parent->name,
        'code' => $parent->code,
        'parent_id' => $child->id,
    ])->assertSessionHasErrors('parent_id');
});

test('it sets a department head', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create();
    $head = Employee::factory()->create();

    $this->post(route('setup.departments.update', $department), [
        'name' => $department->name,
        'code' => $department->code,
        'head_id' => $head->id,
    ])->assertSessionHasNoErrors();

    expect($department->fresh()->head_id)->toBe($head->id);
});

// ── Archive / restore / delete ───────────────────────────────────────────────

test('it archives, restores and force-deletes a department', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create();

    $this->delete(route('setup.departments.destroy', $department))->assertSessionHasNoErrors();
    expect($department->fresh()->trashed())->toBeTrue();

    $this->patch(route('setup.departments.restore', $department))->assertSessionHasNoErrors();
    expect($department->fresh()->trashed())->toBeFalse();

    $this->delete(route('setup.departments.destroy', $department));
    $this->delete(route('setup.departments.force-delete', $department))->assertSessionHasNoErrors();
    expect(Department::withTrashed()->find($department->id))->toBeNull();
});

test('it will not restore a department whose code was reused', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create(['code' => 'DUP']);
    $department->delete();
    Department::factory()->create(['code' => 'DUP']); // allowed: the archived one freed the code

    $this->patch(route('setup.departments.restore', $department))->assertSessionHasNoErrors();

    expect($department->fresh()->trashed())->toBeTrue();
});

// ── Positions ────────────────────────────────────────────────────────────────

test('it adds, updates and deletes a position', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create();

    $this->post(route('setup.positions.store', $department), [
        'title' => 'QA Engineer',
        'salary_grade_min' => 25000,
        'salary_grade_max' => 60000,
    ])->assertSessionHasNoErrors();

    $position = Position::where('title', 'QA Engineer')->first();
    expect($position)->not->toBeNull()
        ->and($position->department_id)->toBe($department->id);

    $this->post(route('setup.positions.update', $position), ['title' => 'Senior QA Engineer'])
        ->assertSessionHasNoErrors();
    expect($position->fresh()->title)->toBe('Senior QA Engineer');

    $this->delete(route('setup.positions.destroy', $position))->assertSessionHasNoErrors();
    expect(Position::find($position->id))->toBeNull();
});

test('it rejects a max salary below the minimum', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create();

    $this->post(route('setup.positions.store', $department), [
        'title' => 'Bad band',
        'salary_grade_min' => 50000,
        'salary_grade_max' => 10000,
    ])->assertSessionHasErrors('salary_grade_max');
});

// ── Authorization & isolation ────────────────────────────────────────────────

test('department routes are permission gated', function () {
    actingAsUserWith([]);

    $this->get(route('setup.departments.index'))->assertForbidden();
});

test('viewing does not grant managing', function () {
    actingAsUserWith(['setup.departments.view']);

    $this->get(route('setup.departments.index'))->assertOk();
    $this->post(route('setup.departments.store'), ['name' => 'X', 'code' => 'X'])->assertForbidden();
});

test('departments are isolated per organisation', function () {
    actingAsSuperAdmin();
    Department::factory()->count(2)->create();

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => Department::factory()->count(4)->create());

    $this->get(route('setup.departments.index'))
        ->assertInertia(fn (Assert $page) => $page->has('departments', 2));
});
