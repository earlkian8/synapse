<?php

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

// Mondays / weekdays used so day counts are deterministic regardless of when tests run.
const MON = '2026-06-15';
const TUE = '2026-06-16';
const FRI = '2026-06-19';

// ── Inbox ────────────────────────────────────────────────────────────────────

test('the leave inbox renders', function () {
    actingAsSuperAdmin();
    LeaveType::factory()->create();
    LeaveRequest::factory()->count(2)->create(['employee_id' => Employee::factory()]);

    $this->get(route('leave.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('leave/index')
            ->has('requests')
            ->has('stats')
            ->has('options.types')
            ->has('options.employees')
            ->has('can'));
});

// ── Filing ───────────────────────────────────────────────────────────────────

test('it files a leave request and computes working days', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $this->post(route('leave.store'), [
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => MON,
        'end_date' => FRI,
    ])->assertSessionHasNoErrors();

    $leave = LeaveRequest::first();
    expect($leave->status)->toBe('pending')
        ->and((float) $leave->days)->toBe(5.0);
});

test('a type that does not require approval is auto-approved', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->autoApproved()->create();

    $this->post(route('leave.store'), [
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => MON,
        'end_date' => MON,
    ])->assertSessionHasNoErrors();

    expect(LeaveRequest::first()->status)->toBe('approved');
});

test('a half day must be a single day', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $this->post(route('leave.store'), [
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => MON,
        'end_date' => TUE,
        'is_half_day' => true,
        'half_day_period' => 'morning',
    ])->assertSessionHasErrors('is_half_day');
});

// ── Review ───────────────────────────────────────────────────────────────────

test('it approves a pending request', function () {
    actingAsSuperAdmin();
    $leave = LeaveRequest::factory()->create(['employee_id' => Employee::factory()]);

    $this->patch(route('leave.review', $leave), ['action' => 'approve', 'review_note' => 'Enjoy'])
        ->assertSessionHasNoErrors();

    expect($leave->fresh()->status)->toBe('approved')
        ->and($leave->fresh()->reviewed_by)->not->toBeNull();
});

test('it rejects a pending request', function () {
    actingAsSuperAdmin();
    $leave = LeaveRequest::factory()->create(['employee_id' => Employee::factory()]);

    $this->patch(route('leave.review', $leave), ['action' => 'reject'])
        ->assertSessionHasNoErrors();

    expect($leave->fresh()->status)->toBe('rejected');
});

test('an already-reviewed request is not reviewed again', function () {
    actingAsSuperAdmin();
    $leave = LeaveRequest::factory()->approved()->create(['employee_id' => Employee::factory()]);

    $this->patch(route('leave.review', $leave), ['action' => 'reject']);

    expect($leave->fresh()->status)->toBe('approved');
});

test('it cancels and deletes a request', function () {
    actingAsSuperAdmin();
    $leave = LeaveRequest::factory()->approved()->create(['employee_id' => Employee::factory()]);

    $this->patch(route('leave.cancel', $leave))->assertSessionHasNoErrors();
    expect($leave->fresh()->status)->toBe('cancelled');

    $this->delete(route('leave.destroy', $leave))->assertSessionHasNoErrors();
    expect(LeaveRequest::find($leave->id))->toBeNull();
});

// ── Balances ─────────────────────────────────────────────────────────────────

test('the balances page renders', function () {
    actingAsSuperAdmin();
    LeaveType::factory()->create();
    Employee::factory()->count(2)->create();

    $this->get(route('leave.balances.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('leave/balances')
            ->has('types')
            ->has('employees')
            ->has('year')
            ->has('years'));
});

test('it sets an employee entitlements for a year', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $this->post(route('leave.balances.store'), [
        'employee_id' => $employee->id,
        'year' => 2026,
        'balances' => [
            ['leave_type_id' => $type->id, 'entitled_days' => 18],
        ],
    ])->assertSessionHasNoErrors();

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->entitled_days)->toBe(18.0);
});

test('used and pending days are derived from requests', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    LeaveRequest::factory()->approved()->create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => MON, 'end_date' => FRI, 'days' => 5,
    ]);
    LeaveRequest::factory()->create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => MON, 'end_date' => MON, 'days' => 1, 'status' => 'pending',
    ]);

    $this->get(route('leave.balances.index', ['year' => 2026]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('employees.0.balances', fn ($balances) => (float) collect($balances)
                ->firstWhere('leave_type_id', $type->id)['used'] === 5.0));
});

// ── Authorization & isolation ────────────────────────────────────────────────

test('viewing does not grant filing', function () {
    actingAsUserWith(['leave.view']);
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $this->get(route('leave.index'))->assertOk();
    $this->post(route('leave.store'), [
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => MON, 'end_date' => MON,
    ])->assertForbidden();
});

test('filing does not grant reviewing', function () {
    $user = actingAsUserWith(['leave.request']);
    $leave = LeaveRequest::factory()->create(['employee_id' => Employee::factory()]);

    $this->patch(route('leave.review', $leave), ['action' => 'approve'])->assertForbidden();
});

test('requests are isolated per organisation', function () {
    actingAsSuperAdmin();
    LeaveRequest::factory()->count(2)->create(['employee_id' => Employee::factory()]);

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => LeaveRequest::factory()->count(3)->create([
        'employee_id' => Employee::factory(),
    ]));

    $this->get(route('leave.index', ['status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('requests', 2));
});
