<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\ShiftRosterEntry;
use App\Models\WorkSchedule;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The roster's screens and endpoints (ADR 0037): the board tab, one-off overrides,
| and putting people on a schedule from a date. The resolution rules themselves
| are covered by ShiftSchedulingTest.
*/

// ── The board ────────────────────────────────────────────────────────────────

test('the roster tab renders a week of resolved shifts', function () {
    actingAsSuperAdmin();
    $schedule = nineToFive();
    Employee::factory()->count(2)->create(['work_schedule_id' => $schedule->id]);

    $this->get(route('attendance.index', ['tab' => 'roster', 'date' => '2026-09-16']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/index')
            ->where('roster.start', '2026-09-14')
            ->where('roster.end', '2026-09-20')
            ->has('roster.days', 7)
            ->has('roster.rows', 2)
            ->has('roster.rows.0.cells', 7)
            ->where('roster.rows.0.cells.0.label', '08:00–17:00')
            ->where('roster.rows.0.cells.0.source', 'employee')
            ->where('roster.rows.0.cells.5.is_working_day', false));
});

test('the roster is withheld from someone without the roster permission', function () {
    actingAsUserWith(['attendance.view']);
    Employee::factory()->create();

    $this->get(route('attendance.index', ['tab' => 'roster']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('roster', null)
            ->where('can.viewRoster', false));
});

// ── Overrides ────────────────────────────────────────────────────────────────

test('an override is written, shows on the board, and can be cleared', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create(['work_schedule_id' => nineToFive()->id]);
    $nights = patternedSchedule('Night Shift', fn (): array => [
        'is_rest_day' => false,
        'segments' => [['start' => '22:00', 'end' => '06:00']],
    ]);

    $this->post(route('attendance.roster.store'), [
        'employee_id' => $employee->id,
        'date' => '2026-09-16',
        'work_schedule_id' => $nights->id,
        'reason' => 'Covering for Ben',
    ])->assertSessionHasNoErrors();

    $entry = ShiftRosterEntry::sole();

    expect($entry->employee_id)->toBe($employee->id)
        ->and($entry->reason)->toBe('Covering for Ben');

    $this->get(route('attendance.index', ['tab' => 'roster', 'date' => '2026-09-16']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('roster.rows.0.cells.2.source', 'roster')
            ->where('roster.rows.0.cells.2.label', '22:00–06:00')
            ->where('roster.rows.0.cells.2.reason', 'Covering for Ben'));

    $this->delete(route('attendance.roster.destroy', $entry->hashid))->assertSessionHasNoErrors();

    expect(ShiftRosterEntry::count())->toBe(0);
});

test('setting an override twice corrects it rather than stacking a second', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();

    foreach (['Swap', 'Corrected'] as $reason) {
        $this->post(route('attendance.roster.store'), [
            'employee_id' => $employee->id,
            'date' => '2026-09-16',
            'segments' => [['start' => '10:00', 'end' => '19:00']],
            'reason' => $reason,
        ])->assertSessionHasNoErrors();
    }

    expect(ShiftRosterEntry::count())->toBe(1)
        ->and(ShiftRosterEntry::sole()->reason)->toBe('Corrected');
});

test('an override needs a schedule, its own hours, or a rest day', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();

    $this->post(route('attendance.roster.store'), [
        'employee_id' => $employee->id,
        'date' => '2026-09-16',
    ])->assertSessionHasErrors('segments');

    expect(ShiftRosterEntry::count())->toBe(0);
});

test('a rest-day override keeps neither hours nor a schedule', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();
    $schedule = nineToFive();

    $this->post(route('attendance.roster.store'), [
        'employee_id' => $employee->id,
        'date' => '2026-09-16',
        'is_rest_day' => true,
        'work_schedule_id' => $schedule->id,
        'segments' => [['start' => '10:00', 'end' => '19:00']],
    ])->assertSessionHasNoErrors();

    $entry = ShiftRosterEntry::sole();

    expect($entry->is_rest_day)->toBeTrue()
        ->and($entry->work_schedule_id)->toBeNull()
        ->and($entry->segments)->toBeNull();
});

// ── Assigning schedules ──────────────────────────────────────────────────────

test('the roster assigns a schedule to a selection from a date', function () {
    actingAsSuperAdmin();
    $schedule = nineToFive('Night Shift');
    $employees = Employee::factory()->count(3)->create(['work_schedule_id' => null]);

    $this->post(route('attendance.roster.assign'), [
        'employee_ids' => $employees->pluck('id')->all(),
        'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-09-16',
    ])->assertSessionHasNoErrors();

    expect(EmployeeScheduleAssignment::count())->toBe(3)
        ->and(EmployeeScheduleAssignment::where('work_schedule_id', $schedule->id)->count())->toBe(3);
});

test('the employee profile assigns a schedule and lists the history', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create(['work_schedule_id' => null]);
    $days = nineToFive();
    $nights = nineToFive('Night Shift');

    $this->post(route('employees.schedule.store', $employee), [
        'work_schedule_id' => $days->id,
        'effective_from' => '2026-09-01',
    ])->assertSessionHasNoErrors();

    $this->post(route('employees.schedule.store', $employee), [
        'work_schedule_id' => $nights->id,
        'effective_from' => '2026-10-01',
    ])->assertSessionHasNoErrors();

    $history = $this->getJson(route('employees.show', $employee))->json('data.schedule_history');

    expect($history)->toHaveCount(2)
        // Newest range first.
        ->and($history[0]['effective_from'])->toBe('2026-10-01')
        ->and($history[1]['effective_to'])->toBe('2026-09-30');
});

test('withdrawing an assignment drops the employee back to their department default', function () {
    actingAsSuperAdmin();
    $fallback = nineToFive('Department Hours');
    $department = Department::factory()->create(['default_work_schedule_id' => $fallback->id]);
    $employee = Employee::factory()->create(['department_id' => $department->id, 'work_schedule_id' => null]);
    $special = nineToFive('Special');

    assignShift($employee, $special, '2026-09-01');
    $assignment = EmployeeScheduleAssignment::sole();

    $this->delete(route('employees.schedule.destroy', [$employee, $assignment]))->assertSessionHasNoErrors();

    expect(EmployeeScheduleAssignment::count())->toBe(0)
        ->and(shiftOn($employee->refresh(), '2026-09-14')->scheduleName)->toBe('Department Hours');
});

// ── Templates ────────────────────────────────────────────────────────────────

test('a day pattern is saved and summarised back onto the legacy columns', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.schedule.work-schedules.store'), [
        'name' => 'Mon–Sat',
        'type' => 'fixed',
        'cycle_length_days' => 7,
        'grace_minutes' => 15,
        'days' => [
            ...array_fill(0, 5, ['is_rest_day' => false, 'segments' => [['start' => '08:00', 'end' => '17:00']], 'required_minutes' => 480]),
            ['is_rest_day' => false, 'segments' => [['start' => '08:00', 'end' => '12:00']], 'required_minutes' => 240],
            ['is_rest_day' => true],
        ],
    ])->assertSessionHasNoErrors();

    $schedule = WorkSchedule::where('name', 'Mon–Sat')->sole();

    expect($schedule->days()->count())->toBe(7)
        ->and($schedule->days()->where('day_index', 6)->value('required_minutes'))->toBe(240)
        ->and($schedule->days()->where('day_index', 7)->value('is_rest_day'))->toBeTrue()
        ->and($schedule->work_days)->toBe(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])
        ->and(substr((string) $schedule->start_time, 0, 5))->toBe('08:00');
});

test('a rotation must say which date its cycle starts on', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.schedule.work-schedules.store'), [
        'name' => '4 on 4 off',
        'type' => 'fixed',
        'cycle_length_days' => 8,
        'grace_minutes' => 0,
        'days' => array_fill(0, 8, ['is_rest_day' => false, 'segments' => [['start' => '06:00', 'end' => '18:00']]]),
    ])->assertSessionHasErrors('cycle_anchor_date');
});

test('a flexible working day must state its core hours', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.schedule.work-schedules.store'), [
        'name' => 'Flexible',
        'type' => 'flexible',
        'cycle_length_days' => 7,
        'grace_minutes' => 0,
        'days' => array_fill(0, 7, ['is_rest_day' => false, 'segments' => [['start' => '07:00', 'end' => '19:00']]]),
    ])->assertSessionHasErrors('days.0.core_start');
});

test('a schedule needs at least one working day', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.schedule.work-schedules.store'), [
        'name' => 'Nothing',
        'type' => 'fixed',
        'cycle_length_days' => 7,
        'grace_minutes' => 0,
        'days' => array_fill(0, 7, ['is_rest_day' => true]),
    ])->assertSessionHasErrors('days');
});

test('the company default schedule can be chosen and cleared', function () {
    actingAsSuperAdmin();
    $schedule = nineToFive('Company Hours');

    $this->patch(route('setup.schedule.default'), ['work_schedule_id' => $schedule->id])->assertSessionHasNoErrors();
    expect(testOrganization()->refresh()->default_work_schedule_id)->toBe($schedule->id);

    $this->patch(route('setup.schedule.default'), ['work_schedule_id' => null])->assertSessionHasNoErrors();
    expect(testOrganization()->refresh()->default_work_schedule_id)->toBeNull();
});

// ── Authorization ────────────────────────────────────────────────────────────

test('setting an override requires the roster manage permission', function () {
    actingAsUserWith(['attendance.view', 'attendance.roster.view']);
    $employee = Employee::factory()->create();

    $this->post(route('attendance.roster.store'), [
        'employee_id' => $employee->id,
        'date' => '2026-09-16',
        'is_rest_day' => true,
    ])->assertForbidden();
});

test('assigning a schedule from the roster requires the roster manage permission', function () {
    actingAsUserWith(['attendance.view']);
    $employee = Employee::factory()->create();

    $this->post(route('attendance.roster.assign'), [
        'employee_ids' => [$employee->id],
        'work_schedule_id' => nineToFive()->id,
        'effective_from' => '2026-09-16',
    ])->assertForbidden();
});

test('assigning a schedule on the profile requires the employee edit permission', function () {
    actingAsUserWith(['employees.view']);
    $employee = Employee::factory()->create();

    $this->post(route('employees.schedule.store', $employee), [
        'work_schedule_id' => nineToFive()->id,
        'effective_from' => '2026-09-16',
    ])->assertForbidden();
});
