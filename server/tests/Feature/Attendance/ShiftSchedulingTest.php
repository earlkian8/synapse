<?php

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\ShiftRosterEntry;
use App\Models\WorkSchedule;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\ScheduleAssigner;
use App\Support\Attendance\SchedulePatternWriter;
use App\Support\Attendance\ShiftResolver;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| ADR 0037 — a schedule is a template with a day pattern, who works it is a dated
| assignment, and one date can be overridden on the roster. A resolver answers
| "which shift applies to this person on this day", and everything else asks it.
|
| 2026-09-14 is a Monday; 2026-09-19 a Saturday. Times are wall-clock readings in
| the test tenant's zone (Asia/Manila unless a test says otherwise).
*/

/**
 * A schedule with a written day pattern.
 *
 * `$days` is a callable taking the zero-based day index and returning that day's
 * row, so a test writes only the shape it cares about.
 */
function patternedSchedule(string $name, callable $days, array $attributes = []): WorkSchedule
{
    testOrganization()->forceFill(['timezone' => $attributes['timezone'] ?? 'Asia/Manila'])->save();
    unset($attributes['timezone']);

    $schedule = WorkSchedule::create([
        'name' => $name,
        'type' => 'fixed',
        'grace_minutes' => 0,
        'cycle_length_days' => 7,
        ...$attributes,
    ]);

    app(SchedulePatternWriter::class)->write(
        $schedule,
        array_map($days, range(0, $schedule->cycle_length_days - 1)),
    );

    return $schedule->refresh();
}

/** A plain Mon–Fri 08:00–17:00 pattern. */
function nineToFive(string $name = 'Day Shift', array $attributes = []): WorkSchedule
{
    return patternedSchedule($name, fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '08:00', 'end' => '17:00']],
        'required_minutes' => 480,
    ], $attributes);
}

/** An employee with no assignment and no schedule pointer at all. */
function unscheduledEmployee(): Employee
{
    return Employee::factory()->create(['work_schedule_id' => null]);
}

/** Put an employee on a schedule from a date, through the canonical writer. */
function assignShift(Employee $employee, WorkSchedule $schedule, string $from, ?string $to = null, int $offset = 0): void
{
    app(ScheduleAssigner::class)->assign($employee, $schedule, $from, $to, $offset);
}

/** The shift one person works on one date. */
function shiftOn(Employee $employee, string $date)
{
    return (new ShiftResolver)->for($employee, $date);
}

// ── Precedence ───────────────────────────────────────────────────────────────

test('an unconfigured tenant falls back to Mon–Fri, eight hours', function () {
    $employee = unscheduledEmployee();

    $monday = shiftOn($employee, '2026-09-14');
    $sunday = shiftOn($employee, '2026-09-20');

    expect($monday->source)->toBe('fallback')
        ->and($monday->isWorkingDay)->toBeTrue()
        ->and($monday->label())->toBe('08:00–17:00')
        ->and($monday->requiredMinutes)->toBe(480)
        ->and($sunday->isWorkingDay)->toBeFalse();
});

test('the company default applies when nothing more specific does', function () {
    $schedule = nineToFive('Company Hours');
    testOrganization()->forceFill(['default_work_schedule_id' => $schedule->id])->save();

    $shift = shiftOn(unscheduledEmployee(), '2026-09-14');

    expect($shift->source)->toBe('organization')
        ->and($shift->scheduleName)->toBe('Company Hours');
});

test('a department default beats the company default', function () {
    $company = nineToFive('Company Hours');
    $support = patternedSchedule('Support Hours', fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '12:00', 'end' => '21:00']],
    ]);

    testOrganization()->forceFill(['default_work_schedule_id' => $company->id])->save();
    $department = Department::factory()->create(['default_work_schedule_id' => $support->id]);
    $employee = Employee::factory()->create(['department_id' => $department->id, 'work_schedule_id' => null]);

    $shift = shiftOn($employee, '2026-09-14');

    expect($shift->source)->toBe('department')
        ->and($shift->label())->toBe('12:00–21:00');
});

test('an assignment beats every default', function () {
    $company = nineToFive('Company Hours');
    $nights = patternedSchedule('Night Shift', fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '22:00', 'end' => '06:00']],
    ]);

    testOrganization()->forceFill(['default_work_schedule_id' => $company->id])->save();
    $employee = unscheduledEmployee();
    assignShift($employee, $nights, '2026-09-01');

    $shift = shiftOn($employee, '2026-09-14');

    expect($shift->source)->toBe('assignment')
        ->and($shift->scheduleName)->toBe('Night Shift');
});

test('a roster override beats the assignment, and says so', function () {
    $days = nineToFive();
    $nights = patternedSchedule('Night Shift', fn (int $i): array => [
        'is_rest_day' => false,
        'segments' => [['start' => '22:00', 'end' => '06:00']],
    ]);

    $employee = unscheduledEmployee();
    assignShift($employee, $days, '2026-09-01');

    ShiftRosterEntry::create([
        'employee_id' => $employee->id,
        'date' => '2026-09-16',
        'work_schedule_id' => $nights->id,
        'reason' => 'Covering for Ben',
    ]);

    expect(shiftOn($employee, '2026-09-15')->source)->toBe('assignment')
        ->and(shiftOn($employee, '2026-09-16')->source)->toBe('roster')
        ->and(shiftOn($employee, '2026-09-16')->label())->toBe('22:00–06:00')
        ->and(shiftOn($employee, '2026-09-17')->source)->toBe('assignment');
});

test('a roster entry can make a working day a rest day, or give it its own hours', function () {
    $employee = unscheduledEmployee();
    assignShift($employee, nineToFive(), '2026-09-01');

    ShiftRosterEntry::create(['employee_id' => $employee->id, 'date' => '2026-09-15', 'is_rest_day' => true]);
    ShiftRosterEntry::create([
        'employee_id' => $employee->id,
        'date' => '2026-09-19',
        'segments' => [['start' => '09:00', 'end' => '13:00']],
    ]);

    $restDay = shiftOn($employee, '2026-09-15');
    $saturday = shiftOn($employee, '2026-09-19');

    expect($restDay->isWorkingDay)->toBeFalse()
        ->and($restDay->label())->toBe('Rest day')
        ->and($saturday->isWorkingDay)->toBeTrue()
        ->and($saturday->label())->toBe('09:00–13:00')
        // No required minutes were given, so the hours it actually runs.
        ->and($saturday->requiredMinutes)->toBe(240);
});

// ── Assignment boundaries ────────────────────────────────────────────────────

test('an assignment applies from its first day and not the day before', function () {
    $days = nineToFive();
    $nights = patternedSchedule('Night Shift', fn (): array => [
        'is_rest_day' => false,
        'segments' => [['start' => '22:00', 'end' => '06:00']],
    ]);

    $employee = unscheduledEmployee();
    assignShift($employee, $days, '2026-09-01');
    assignShift($employee, $nights, '2026-09-16');

    expect(shiftOn($employee, '2026-09-15')->scheduleName)->toBe('Day Shift')
        ->and(shiftOn($employee, '2026-09-16')->scheduleName)->toBe('Night Shift')
        ->and(shiftOn($employee, '2026-09-30')->scheduleName)->toBe('Night Shift');
});

test('assigning from a date closes the open assignment rather than overlapping it', function () {
    $employee = unscheduledEmployee();
    assignShift($employee, nineToFive(), '2026-09-01');
    assignShift($employee, nineToFive('Night Shift'), '2026-09-16');

    $assignments = EmployeeScheduleAssignment::where('employee_id', $employee->id)
        ->orderBy('effective_from')
        ->get();

    expect($assignments)->toHaveCount(2)
        ->and($assignments[0]->effective_to->toDateString())->toBe('2026-09-15')
        ->and($assignments[1]->effective_to)->toBeNull();
});

test('a bounded assignment inside a longer one splits it, so the old shift resumes', function () {
    $days = nineToFive();
    $cover = nineToFive('Cover Shift');

    $employee = unscheduledEmployee();
    assignShift($employee, $days, '2026-09-01');
    assignShift($employee, $cover, '2026-09-14', '2026-09-20');

    expect(shiftOn($employee, '2026-09-13')->scheduleName)->toBe('Day Shift')
        ->and(shiftOn($employee, '2026-09-14')->scheduleName)->toBe('Cover Shift')
        ->and(shiftOn($employee, '2026-09-20')->scheduleName)->toBe('Cover Shift')
        ->and(shiftOn($employee, '2026-09-21')->scheduleName)->toBe('Day Shift');
});

test('assignments never overlap, whatever order they are written in', function () {
    $employee = unscheduledEmployee();

    assignShift($employee, nineToFive('A'), '2026-09-01');
    assignShift($employee, nineToFive('B'), '2026-09-10', '2026-09-20');
    assignShift($employee, nineToFive('C'), '2026-09-15');

    $ranges = EmployeeScheduleAssignment::where('employee_id', $employee->id)
        ->orderBy('effective_from')
        ->get();

    $previousEnd = null;

    foreach ($ranges as $range) {
        if ($previousEnd !== null) {
            expect($range->effective_from->toDateString())->toBeGreaterThan($previousEnd);
        }

        $previousEnd = $range->effective_to?->toDateString() ?? '9999-12-31';
    }

    expect($ranges->count())->toBeGreaterThan(0);
});

test('the denormalised pointer follows whichever assignment covers today', function () {
    $today = OrganizationClock::today();
    $future = CarbonImmutable::parse($today)->addMonth()->toDateString();

    $now = nineToFive('Now');
    $later = nineToFive('Later');
    $employee = unscheduledEmployee();

    assignShift($employee, $now, $today);
    expect($employee->refresh()->work_schedule_id)->toBe($now->id);

    // An assignment that has not started yet must not move the pointer.
    assignShift($employee, $later, $future);
    expect($employee->refresh()->work_schedule_id)->toBe($now->id);
});

// ── Rotations ────────────────────────────────────────────────────────────────

test('two crews on one four-on-four-off template never work the same day', function () {
    $rotation = patternedSchedule('4 on 4 off', fn (int $i): array => [
        'is_rest_day' => $i >= 4,
        'segments' => [['start' => '06:00', 'end' => '18:00']],
        'required_minutes' => 720,
    ], ['cycle_length_days' => 8, 'cycle_anchor_date' => '2026-09-14']);

    $crewA = unscheduledEmployee();
    $crewB = unscheduledEmployee();

    assignShift($crewA, $rotation, '2026-09-01', null, 0);
    assignShift($crewB, $rotation, '2026-09-01', null, 4);

    $resolver = new ShiftResolver;
    $shifts = $resolver->forMany(collect([$crewA, $crewB]), '2026-09-14', '2026-09-29');

    $pattern = '';

    for ($i = 0; $i < 16; $i++) {
        $date = CarbonImmutable::parse('2026-09-14')->addDays($i)->toDateString();
        $a = $shifts[$crewA->id][$date]->isWorkingDay;
        $b = $shifts[$crewB->id][$date]->isWorkingDay;

        expect($a)->not->toBe($b);
        $pattern .= $a ? 'A' : 'B';
    }

    expect($pattern)->toBe('AAAABBBBAAAABBBB');
});

test('a rotation reads correctly for dates before its anchor', function () {
    $rotation = patternedSchedule('4 on 4 off', fn (int $i): array => [
        'is_rest_day' => $i >= 4,
        'segments' => [['start' => '06:00', 'end' => '18:00']],
    ], ['cycle_length_days' => 8, 'cycle_anchor_date' => '2026-09-14']);

    $employee = unscheduledEmployee();
    assignShift($employee, $rotation, '2026-08-01');

    // Eight days before the anchor is the same point in the cycle as the anchor.
    expect(shiftOn($employee, '2026-09-06')->isWorkingDay)->toBeTrue()
        ->and(shiftOn($employee, '2026-09-10')->isWorkingDay)->toBeFalse();
});

// ── Per-day patterns, split and overnight shifts ─────────────────────────────

test('a Saturday half-day is judged against its own four hours', function () {
    $schedule = patternedSchedule('Mon–Sat', fn (int $i): array => match (true) {
        $i <= 4 => ['segments' => [['start' => '08:00', 'end' => '17:00']], 'required_minutes' => 480],
        $i === 5 => ['segments' => [['start' => '08:00', 'end' => '12:00']], 'required_minutes' => 240],
        default => ['is_rest_day' => true],
    });

    $employee = unscheduledEmployee();
    assignShift($employee, $schedule, '2026-09-01');

    $saturday = shiftOn($employee, '2026-09-19');

    expect($saturday->isWorkingDay)->toBeTrue()
        ->and($saturday->label())->toBe('08:00–12:00')
        ->and($saturday->requiredMinutes)->toBe(240);

    actingAsSuperAdmin();

    $this->post(route('attendance.store'), [
        'employee_id' => $employee->id,
        'work_date' => '2026-09-19',
        'time_in' => '08:00',
        'time_out' => '12:00',
    ])->assertSessionHasNoErrors();

    $record = AttendanceRecord::sole();

    expect($record->worked_minutes)->toBe(240)
        ->and($record->late_minutes)->toBe(0)
        ->and($record->undertime_minutes)->toBe(0)
        ->and($record->overtime_minutes)->toBe(0)
        ->and($record->status)->toBe('present');
});

test('a split shift is late against its first half and short against its second', function () {
    $schedule = patternedSchedule('Split', fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '08:00', 'end' => '12:00'], ['start' => '17:00', 'end' => '21:00']],
        'required_minutes' => 480,
    ]);

    $employee = unscheduledEmployee();
    assignShift($employee, $schedule, '2026-09-01');

    $shift = shiftOn($employee, '2026-09-14');

    expect($shift->label())->toBe('08:00–12:00 · 17:00–21:00')
        ->and($shift->startsAt()->toIso8601String())->toBe(OrganizationClock::at('2026-09-14', '08:00')->toIso8601String())
        ->and($shift->endsAt()->toIso8601String())->toBe(OrganizationClock::at('2026-09-14', '21:00')->toIso8601String());

    $employee->refresh();
    $clock = app(AttendanceClock::class);
    $record = $clock->openRecord($employee, '2026-09-14');

    // In at 08:15, out of the morning half at 12:00, back at 17:00, away at 20:30.
    foreach ([
        ['clock_in', '08:15'], ['clock_out', '12:00'],
        ['clock_in', '17:00'], ['clock_out', '20:30'],
    ] as [$type, $time]) {
        $record->punches()->create([
            'employee_id' => $employee->id,
            'type' => $type,
            'punched_at' => OrganizationClock::at('2026-09-14', $time),
            'source' => 'manual',
        ]);
    }

    $clock->refresh($record);

    expect($record->late_minutes)->toBe(15)
        // The gap between the halves is neither worked nor break.
        ->and($record->worked_minutes)->toBe(225 + 210)
        ->and($record->break_minutes)->toBe(0)
        ->and($record->undertime_minutes)->toBe(30)
        ->and($record->status)->toBe('late');
});

test('an overnight segment inside a per-day pattern ends the next morning', function () {
    $schedule = patternedSchedule('Fri nights', fn (int $i): array => $i === 4
        ? ['segments' => [['start' => '22:00', 'end' => '06:00']], 'required_minutes' => 480]
        : ['is_rest_day' => true]);

    $employee = unscheduledEmployee();
    assignShift($employee, $schedule, '2026-09-01');

    // 2026-09-18 is a Friday.
    $friday = shiftOn($employee, '2026-09-18');

    expect($friday->isWorkingDay)->toBeTrue()
        ->and($friday->endsAt()->toIso8601String())->toBe(OrganizationClock::at('2026-09-19', '06:00')->toIso8601String())
        ->and(shiftOn($employee, '2026-09-19')->isWorkingDay)->toBeFalse();
});

// ── Schedule types ───────────────────────────────────────────────────────────

test('a flexible schedule is late only after the core window opens', function () {
    $schedule = patternedSchedule('Flexible', fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '07:00', 'end' => '19:00']],
        'required_minutes' => 480,
        'core_start' => '10:00',
        'core_end' => '15:00',
        'earliest_start' => '06:00',
        'latest_end' => '22:00',
    ], ['type' => 'flexible']);

    $employee = unscheduledEmployee();
    assignShift($employee, $schedule, '2026-09-01');
    $employee->refresh();

    actingAsSuperAdmin();

    // In at 09:45 — before the core window — and seven of eight hours worked.
    $this->post(route('attendance.store'), [
        'employee_id' => $employee->id,
        'work_date' => '2026-09-14',
        'time_in' => '09:45',
        'time_out' => '16:45',
    ])->assertSessionHasNoErrors();

    $record = AttendanceRecord::sole();

    expect($record->late_minutes)->toBe(0)
        ->and($record->worked_minutes)->toBe(420)
        // Left after the core window closed, but an hour short of the day.
        ->and($record->undertime_minutes)->toBe(60)
        ->and($record->status)->toBe('undertime');
});

test('a flexible schedule is late when the core window is missed', function () {
    $schedule = patternedSchedule('Flexible', fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '07:00', 'end' => '19:00']],
        'required_minutes' => 480,
        'core_start' => '10:00',
        'core_end' => '15:00',
    ], ['type' => 'flexible']);

    $employee = unscheduledEmployee();
    assignShift($employee, $schedule, '2026-09-01');
    $employee->refresh();

    actingAsSuperAdmin();

    $this->post(route('attendance.store'), [
        'employee_id' => $employee->id,
        'work_date' => '2026-09-14',
        'time_in' => '10:20',
        'time_out' => '18:20',
    ])->assertSessionHasNoErrors();

    $record = AttendanceRecord::sole();

    expect($record->late_minutes)->toBe(20)
        ->and($record->undertime_minutes)->toBe(0)
        ->and($record->status)->toBe('late');
});

test('an hours-only schedule is never late, only short', function () {
    $schedule = patternedSchedule('Hours only', fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '00:00', 'end' => '23:59']],
        'required_minutes' => 480,
    ], ['type' => 'hours_only']);

    $employee = unscheduledEmployee();
    assignShift($employee, $schedule, '2026-09-01');
    $employee->refresh();

    actingAsSuperAdmin();

    // Started at 14:00 and worked six hours.
    $this->post(route('attendance.store'), [
        'employee_id' => $employee->id,
        'work_date' => '2026-09-14',
        'time_in' => '14:00',
        'time_out' => '20:00',
    ])->assertSessionHasNoErrors();

    $record = AttendanceRecord::sole();

    expect($record->late_minutes)->toBe(0)
        ->and($record->worked_minutes)->toBe(360)
        ->and($record->undertime_minutes)->toBe(120)
        ->and($record->status)->toBe('undertime');
});

// ── The migration keeps existing tenants' numbers ────────────────────────────

test('a schedule with no written pattern still answers the way it always did', function () {
    // What the factory and the pre-pattern seeders leave behind: the flat columns
    // and no day rows at all.
    $legacy = WorkSchedule::factory()->create([
        'name' => 'Legacy',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'work_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
        'grace_minutes' => 10,
        'required_hours' => 8,
    ]);
    $legacy->days()->delete();

    $employee = Employee::factory()->create(['work_schedule_id' => $legacy->id]);

    $monday = shiftOn($employee, '2026-09-14');
    $sunday = shiftOn($employee, '2026-09-20');

    expect($monday->source)->toBe('employee')
        ->and($monday->label())->toBe('09:00–18:00')
        ->and($monday->graceMinutes)->toBe(10)
        ->and($monday->requiredMinutes)->toBe(480)
        ->and($sunday->isWorkingDay)->toBeFalse();
});

// ── Query budget ─────────────────────────────────────────────────────────────

test('resolving a month for fifty people costs a fixed number of queries', function () {
    $schedule = nineToFive();
    $department = Department::factory()->create(['default_work_schedule_id' => $schedule->id]);
    $employees = Employee::factory()->count(50)->create([
        'department_id' => $department->id,
        'work_schedule_id' => null,
    ]);

    foreach ($employees->take(10) as $employee) {
        assignShift($employee, $schedule, '2026-09-01');
    }

    $resolver = new ShiftResolver;
    $roster = $employees->fresh();

    DB::enableQueryLog();
    $shifts = $resolver->forMany($roster, '2026-09-01', '2026-10-01');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(5)
        ->and($shifts)->toHaveCount(50)
        ->and($shifts[$employees->first()->id])->toHaveCount(31);
});
