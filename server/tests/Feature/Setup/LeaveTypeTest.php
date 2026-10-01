<?php

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

// ── Listing ──────────────────────────────────────────────────────────────────

test('the leave types page renders', function () {
    actingAsSuperAdmin();
    LeaveType::factory()->count(3)->create();

    $this->get(route('setup.leave-types.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('setup/leave-types')
            ->has('types', 3)
            ->has('archived')
            ->has('can'));
});

// ── CRUD ─────────────────────────────────────────────────────────────────────

test('it creates a leave type and uppercases the code', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.leave-types.store'), [
        'name' => 'Study Leave',
        'code' => 'sl2',
        'color' => '#0ABFBF',
        'default_days' => 5,
    ])->assertSessionHasNoErrors();

    expect(LeaveType::where('code', 'SL2')->exists())->toBeTrue();
});

test('it validates required fields', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.leave-types.store'), [])
        ->assertSessionHasErrors(['name', 'code', 'color', 'default_days']);
});

test('it rejects a duplicate code within the tenant', function () {
    actingAsSuperAdmin();
    LeaveType::factory()->create(['code' => 'VL']);

    $this->post(route('setup.leave-types.store'), [
        'name' => 'Other', 'code' => 'VL', 'color' => '#000', 'default_days' => 1,
    ])->assertSessionHasErrors('code');
});

test('a code may be reused across organisations', function () {
    actingAsSuperAdmin();
    LeaveType::factory()->create(['code' => 'VL']);

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, function () {
        expect(fn () => LeaveType::factory()->create(['code' => 'VL']))->not->toThrow(Exception::class);
    });
});

test('it updates a leave type', function () {
    actingAsSuperAdmin();
    $type = LeaveType::factory()->create();

    $this->post(route('setup.leave-types.update', $type), [
        'name' => 'Renamed', 'code' => $type->code, 'color' => $type->color, 'default_days' => 12,
    ])->assertSessionHasNoErrors();

    expect($type->fresh()->name)->toBe('Renamed')
        ->and((float) $type->fresh()->default_days)->toBe(12.0);
});

// ── Archive / restore / delete ───────────────────────────────────────────────

test('it archives, restores and force-deletes a leave type', function () {
    actingAsSuperAdmin();
    $type = LeaveType::factory()->create();

    $this->delete(route('setup.leave-types.destroy', $type))->assertSessionHasNoErrors();
    expect($type->fresh()->trashed())->toBeTrue();

    $this->patch(route('setup.leave-types.restore', $type))->assertSessionHasNoErrors();
    expect($type->fresh()->trashed())->toBeFalse();

    $this->delete(route('setup.leave-types.destroy', $type));
    $this->delete(route('setup.leave-types.force-delete', $type))->assertSessionHasNoErrors();
    expect(LeaveType::withTrashed()->find($type->id))->toBeNull();
});

test('a leave type with requests cannot be force-deleted', function () {
    actingAsSuperAdmin();
    $type = LeaveType::factory()->create();
    LeaveRequest::factory()->create([
        'leave_type_id' => $type->id,
        'employee_id' => Employee::factory(),
    ]);
    $type->delete();

    $this->delete(route('setup.leave-types.force-delete', $type));

    expect(LeaveType::withTrashed()->find($type->id))->not->toBeNull();
});

// ── Authorization & isolation ────────────────────────────────────────────────

test('leave type routes are permission gated', function () {
    actingAsUserWith([]);

    $this->get(route('setup.leave-types.index'))->assertForbidden();
});

test('viewing does not grant managing', function () {
    actingAsUserWith(['setup.leave-types.view']);

    $this->get(route('setup.leave-types.index'))->assertOk();
    $this->post(route('setup.leave-types.store'), [
        'name' => 'X', 'code' => 'X', 'color' => '#000', 'default_days' => 1,
    ])->assertForbidden();
});

test('leave types are isolated per organisation', function () {
    actingAsSuperAdmin();
    LeaveType::factory()->count(2)->create();

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => LeaveType::factory()->count(4)->create());

    $this->get(route('setup.leave-types.index'))
        ->assertInertia(fn (Assert $page) => $page->has('types', 2));
});
