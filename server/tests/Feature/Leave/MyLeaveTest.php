<?php

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;

/*
| My leave on the web: somebody who may only file their own leave (the Staff
| role's `leave.request`) has a screen for it — balances, their own requests,
| filing and cancelling — and the module's entry, and Attendance's, take them to
| their own instead of a 403.
*/

test('somebody who may only file their own leave lands on My leave, and sees only theirs', function () {
    $user = actingAsUserWith(['leave.request']);
    $mine = Employee::factory()->create(['user_id' => $user->id]);
    $other = Employee::factory()->create();
    $type = LeaveType::factory()->create(['default_days' => 10]);

    LeaveRequest::factory()->create(['employee_id' => $mine->id, 'leave_type_id' => $type->id, 'status' => 'pending']);
    LeaveRequest::factory()->create(['employee_id' => $other->id, 'leave_type_id' => $type->id]);

    $this->get('/leave')->assertRedirect('/leave/me');

    $this->get('/leave/me')->assertOk()->assertInertia(fn ($page) => $page
        ->component('leave/me')
        ->where('employee.id', $mine->id)
        ->has('requests', 1)
        ->where('requests.0.status', 'pending')
        ->has('balances', 1));
});

test('they file and cancel their own leave from it, and nobody else’s', function () {
    $user = actingAsUserWith(['leave.request']);
    $mine = Employee::factory()->create(['user_id' => $user->id]);
    $other = Employee::factory()->create();
    $type = LeaveType::factory()->create(['requires_approval' => true]);

    $this->from('/leave/me')->post('/leave', [
        'employee_id' => $mine->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-12-14',
        'end_date' => '2026-12-15',
        'is_half_day' => false,
    ])->assertRedirect('/leave/me');

    $filed = LeaveRequest::query()->where('employee_id', $mine->id)->sole();
    expect($filed->status)->toBe('pending');

    $this->post('/leave', [
        'employee_id' => $other->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-12-14',
        'end_date' => '2026-12-14',
    ])->assertSessionHasErrors('employee_id');

    $this->patch("/leave/{$filed->hashid}/cancel")->assertRedirect();
    expect($filed->refresh()->status)->toBe('cancelled');
});

test('HR still lands on the requests', function () {
    actingAsUserWith(['leave.view', 'leave.request']);

    $this->get('/leave')->assertOk()->assertInertia(fn ($page) => $page->component('leave/index'));
});

test('somebody who only clocks themselves in lands on their own attendance', function () {
    actingAsUserWith(['attendance.clock']);

    $this->get('/attendance')->assertRedirect('/attendance/me');
});

test('neither entry lets in somebody with neither permission', function () {
    actingAsUserWith(['events.respond']);

    $this->get('/leave')->assertForbidden();
    $this->get('/attendance')->assertForbidden();
});
