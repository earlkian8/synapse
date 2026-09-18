<?php

use App\Models\ActivityLog;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\WorkSchedule;
use App\Queries\AttendanceMonthlyReport;
use App\Queries\AttendanceRecordsIndexQuery;
use App\Queries\AttendanceWeeklyQuery;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePunchException;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;

/*
| ADR 0036 — attendance is judged on the organisation's clock, on work dates
| anchored to shifts, by the rules frozen onto each day.
|
| 2026-09-14 is a Monday. Every time below is a wall-clock time in the test
| tenant's zone unless it ends in Z.
*/

/**
 * An employee on a Mon–Fri schedule with no grace, in a tenant keeping `$timezone`.
 *
 * @param  array<string, mixed>  $schedule
 */
function shiftWorker(string $start = '08:00', string $end = '17:00', string $timezone = 'Asia/Manila', array $schedule = []): Employee
{
    testOrganization()->forceFill(['timezone' => $timezone])->save();

    $workSchedule = WorkSchedule::factory()->create([
        'name' => 'Test Shift',
        'start_time' => $start,
        'end_time' => $end,
        'work_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
        'grace_minutes' => 0,
        'required_hours' => 8,
        ...$schedule,
    ]);

    return Employee::factory()->create(['work_schedule_id' => $workSchedule->id]);
}

/**
 * Travel to a wall-clock moment in the tenant's zone and punch there.
 */
function punchOnWallClock(Employee $employee, string $type, string $localDateTime): AttendanceRecord
{
    test()->travelTo(CarbonImmutable::parse($localDateTime, OrganizationClock::timezone()));

    return app(AttendanceClock::class)->punch($employee, $type, ['source' => 'web']);
}

// ── The organisation's clock ─────────────────────────────────────────────────

test('the organisation clock turns a wall-clock time into the instant it names', function () {
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();

    expect(OrganizationClock::at('2026-09-14', '08:00')->toIso8601String())->toBe('2026-09-14T00:00:00+00:00')
        ->and(OrganizationClock::localDate(CarbonImmutable::parse('2026-09-13T23:45:00Z')))->toBe('2026-09-14');

    testOrganization()->forceFill(['timezone' => 'America/New_York'])->save();

    // Daylight saving time: New York is UTC−4 in September.
    expect(OrganizationClock::at('2026-09-14', '08:00')->toIso8601String())->toBe('2026-09-14T12:00:00+00:00');
});

// ── Local time ───────────────────────────────────────────────────────────────

test('lateness and the work date are read on the organisation clock', function (string $timezone) {
    $employee = shiftWorker(timezone: $timezone);

    $record = punchOnWallClock($employee, 'clock_in', '2026-09-14 08:30');

    expect($record->work_date->toDateString())->toBe('2026-09-14')
        ->and($record->late_minutes)->toBe(30)
        ->and($record->scheduled_start_at->toIso8601String())->toBe(OrganizationClock::at('2026-09-14', '08:00')->toIso8601String());

    $record = punchOnWallClock($employee, 'clock_out', '2026-09-14 17:00');

    expect($record->undertime_minutes)->toBe(0)
        ->and($record->worked_minutes)->toBe(510)
        ->and($record->status)->toBe('late');
})->with(['Asia/Manila', 'America/New_York']);

test('an early clock-in is filed under today, not under the UTC date before it', function (string $timezone) {
    $employee = shiftWorker(timezone: $timezone);

    $record = punchOnWallClock($employee, 'clock_in', '2026-09-14 07:45');

    expect($record->work_date->toDateString())->toBe('2026-09-14')
        ->and($record->late_minutes)->toBe(0)
        ->and(AttendanceRecord::count())->toBe(1);
})->with(['Asia/Manila', 'America/New_York']);

test('times entered by hand are the organisation clock readings', function () {
    actingAsSuperAdmin();
    $employee = shiftWorker();

    $this->post(route('attendance.store'), [
        'employee_id' => $employee->id,
        'work_date' => '2026-09-14',
        'time_in' => '08:00',
        'time_out' => '17:00',
    ])->assertSessionHasNoErrors();

    $in = AttendancePunch::where('type', 'clock_in')->sole();
    $record = AttendanceRecord::sole();

    expect($in->punched_at->utc()->toIso8601String())->toBe('2026-09-14T00:00:00+00:00')
        ->and($record->late_minutes)->toBe(0)
        ->and($record->undertime_minutes)->toBe(0)
        ->and($record->status)->toBe('present');
});

// ── Overnight shifts ─────────────────────────────────────────────────────────

test('a night shift clocks out the next morning on the day it started', function () {
    $employee = shiftWorker('22:00', '06:00');

    punchOnWallClock($employee, 'clock_in', '2026-09-14 22:00');
    $record = punchOnWallClock($employee, 'clock_out', '2026-09-15 06:00');

    expect(AttendanceRecord::count())->toBe(1)
        ->and($record->work_date->toDateString())->toBe('2026-09-14')
        ->and($record->worked_minutes)->toBe(480)
        ->and($record->late_minutes)->toBe(0)
        ->and($record->undertime_minutes)->toBe(0)
        ->and($record->status)->toBe('present')
        ->and($record->scheduled_end_at->toIso8601String())->toBe('2026-09-14T22:00:00+00:00');
});

test('a clock-in either side of midnight belongs to the night shift it is for', function (string $at, int $late) {
    $employee = shiftWorker('22:00', '06:00');

    $record = punchOnWallClock($employee, 'clock_in', $at);

    expect($record->work_date->toDateString())->toBe('2026-09-14')
        ->and($record->late_minutes)->toBe($late);
})->with([
    'half an hour early' => ['2026-09-14 21:30', 0],
    'two and a half hours late' => ['2026-09-15 00:30', 150],
]);

test('a night shift entered by hand runs into the next morning', function () {
    actingAsSuperAdmin();
    $employee = shiftWorker('22:00', '06:00');

    $this->post(route('attendance.store'), [
        'employee_id' => $employee->id,
        'work_date' => '2026-09-14',
        'time_in' => '22:00',
        'break_start' => '02:00',
        'break_end' => '03:00',
        'time_out' => '06:00',
    ])->assertSessionHasNoErrors();

    $record = AttendanceRecord::sole();

    expect(AttendancePunch::where('type', 'clock_out')->sole()->punched_at->utc()->toIso8601String())->toBe('2026-09-14T22:00:00+00:00')
        ->and($record->worked_minutes)->toBe(420)
        ->and($record->break_minutes)->toBe(60)
        ->and($record->status)->toBe('present');
});

test('the clock card shows the night shift still under way after midnight', function () {
    $employee = shiftWorker('22:00', '06:00');

    punchOnWallClock($employee, 'clock_in', '2026-09-14 22:00');
    $this->travelTo(CarbonImmutable::parse('2026-09-15 02:00', 'Asia/Manila'));

    $clock = app(AttendanceClock::class);
    $current = $clock->currentRecord($employee);

    expect($current->work_date->toDateString())->toBe('2026-09-14')
        ->and($clock->nextExpected($current))->toBe('clock_out');
});

test('a refused punch writes no record and no punch', function () {
    $employee = shiftWorker('22:00', '06:00');

    // The shape of the old bug: a clock-out with nothing open opened a day first.
    expect(fn () => punchOnWallClock($employee, 'clock_out', '2026-09-15 06:00'))
        ->toThrow(AttendancePunchException::class, 'You need to clock in first.');

    expect(AttendanceRecord::count())->toBe(0)
        ->and(AttendancePunch::count())->toBe(0);

    punchOnWallClock($employee, 'clock_in', '2026-09-14 22:00');

    expect(fn () => punchOnWallClock($employee, 'clock_in', '2026-09-14 22:05'))
        ->toThrow(AttendancePunchException::class, "You're already clocked in.");

    expect(AttendanceRecord::count())->toBe(1)
        ->and(AttendancePunch::count())->toBe(1);
});

// ── Frozen rules ─────────────────────────────────────────────────────────────

test('a recorded day keeps the grace it was judged by when the schedule changes', function () {
    actingAsSuperAdmin();
    $employee = shiftWorker(schedule: ['grace_minutes' => 15]);

    $record = punchOnWallClock($employee, 'clock_in', '2026-09-14 08:20');

    expect($record->late_minutes)->toBe(5)
        ->and($record->rules['grace_minutes'])->toBe(15);

    $employee->workSchedule->update(['grace_minutes' => 0]);

    // HR corrects the day after the schedule changed.
    $this->post(route('attendance.update', $record), ['time_in' => '08:20', 'time_out' => '17:00'])
        ->assertSessionHasNoErrors();

    expect($record->fresh()->late_minutes)->toBe(5);
});

test('re-applying the current schedule re-judges the day and is logged', function () {
    actingAsSuperAdmin();
    $employee = shiftWorker(schedule: ['grace_minutes' => 15]);

    $record = punchOnWallClock($employee, 'clock_in', '2026-09-14 08:20');
    $employee->workSchedule->update(['grace_minutes' => 0]);

    $this->patch(route('attendance.reapply', $record))->assertSessionHasNoErrors();
    assertToast('success');

    $fresh = $record->fresh();

    expect($fresh->late_minutes)->toBe(20)
        ->and($fresh->rules['grace_minutes'])->toBe(0)
        ->and(ActivityLog::query()
            ->where('log_name', 'attendance')
            ->where('description', 'like', 'Re-applied the current schedule%')
            ->exists())->toBeTrue();
});

test('re-applying schedules over a period re-judges every recorded day in it', function () {
    actingAsSuperAdmin();
    $employee = shiftWorker(schedule: ['grace_minutes' => 15]);

    punchOnWallClock($employee, 'clock_in', '2026-09-14 08:20');
    punchOnWallClock($employee, 'clock_out', '2026-09-14 17:00');
    punchOnWallClock($employee, 'clock_in', '2026-09-15 08:10');
    $employee->workSchedule->update(['grace_minutes' => 0]);

    $this->patch(route('attendance.reapply-range'), ['from' => '2026-09-14', 'to' => '2026-09-15'])
        ->assertSessionHasNoErrors();

    expect(AttendanceRecord::orderBy('work_date')->pluck('late_minutes')->all())->toBe([20, 10])
        ->and(ActivityLog::where('description', 'like', 'Re-applied current schedules and policies to 2 attendance records%')->exists())->toBeTrue();
});

test('re-applying schedules needs the manage permission and a bounded period', function () {
    actingAsUserWith(['attendance.view']);

    $this->patch(route('attendance.reapply-range'), ['from' => '2026-09-01', 'to' => '2026-09-02'])->assertForbidden();

    actingAsSuperAdmin();

    $this->patch(route('attendance.reapply-range'), ['from' => '2026-01-01', 'to' => '2026-06-30'])
        ->assertSessionHasErrors('to');
});

// ── Holidays ─────────────────────────────────────────────────────────────────

test('a day without punches reads holiday, leave and working holidays in order', function () {
    $employee = shiftWorker();
    $onLeave = shiftWorker();

    Holiday::factory()->create(['name' => 'Founders Day', 'date' => '2026-09-14', 'type' => 'regular', 'is_recurring' => false]);
    Holiday::factory()->create(['name' => 'Company Anniversary', 'date' => '2026-09-15', 'type' => 'special_working', 'is_recurring' => false]);
    LeaveRequest::factory()->create([
        'employee_id' => $onLeave->id,
        'status' => 'approved',
        'start_date' => '2026-09-14',
        'end_date' => '2026-09-14',
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00', 'Asia/Manila'));
    $roster = app(AttendanceRecordsIndexQuery::class);

    $holiday = $roster->roster('2026-09-14');
    $working = $roster->roster('2026-09-15');

    expect($holiday->firstWhere('employee_id', $employee->id)->status)->toBe('holiday')
        ->and($holiday->firstWhere('employee_id', $onLeave->id)->status)->toBe('on_leave')
        ->and($working->firstWhere('employee_id', $employee->id)->status)->toBe('absent');
});

test('a holiday somebody worked is judged like any day and remembers the holiday', function () {
    $employee = shiftWorker();
    Holiday::factory()->create(['name' => 'Founders Day', 'date' => '2026-09-14', 'type' => 'regular', 'is_recurring' => false]);

    punchOnWallClock($employee, 'clock_in', '2026-09-14 08:00');
    $record = punchOnWallClock($employee, 'clock_out', '2026-09-14 17:00');

    expect($record->status)->toBe('present')
        ->and($record->rules['holiday_type'])->toBe('regular')
        ->and($record->rules['holiday_name'])->toBe('Founders Day');
});

test('the weekly matrix and the monthly report show a holiday', function () {
    $employee = shiftWorker();
    Holiday::factory()->create(['name' => 'Founders Day', 'date' => '2026-09-14', 'type' => 'regular', 'is_recurring' => false]);

    $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00', 'Asia/Manila'));

    $week = app(AttendanceWeeklyQuery::class)->toArray('2026-09-16');
    $cell = collect($week['rows'][0]['cells'])->firstWhere('date', '2026-09-14');

    $report = app(AttendanceMonthlyReport::class)->toArray('2026-09-16');

    expect($cell['status'])->toBe('holiday')
        ->and($cell['holiday'])->toBe('Founders Day')
        ->and($report['rows'][0]['holiday_count'])->toBe(1)
        ->and($report['rows'][0]['employee']['id'])->toBe($employee->id);
});

// ── Backfill and repair ──────────────────────────────────────────────────────

test('attendance:recompute reports a dry run without writing, then backfills', function () {
    $employee = shiftWorker();

    // A day written before ADR 0036: no snapshot, and judged in UTC — an 08:30
    // Manila clock-in (00:30Z) against "08:00" read as 08:00Z.
    $record = AttendanceRecord::factory()->create([
        'employee_id' => $employee->id,
        'work_date' => '2026-09-14',
        'work_schedule_id' => $employee->work_schedule_id,
        'scheduled_start' => '08:00:00',
        'scheduled_end' => '17:00:00',
        'status' => 'undertime',
        'first_in_at' => '2026-09-14 00:30:00',
        'last_out_at' => '2026-09-14 09:00:00',
        'worked_minutes' => 510,
        'break_minutes' => 0,
        'late_minutes' => 0,
        'undertime_minutes' => 480,
        'overtime_minutes' => 30,
    ]);
    $record->punches()->createMany([
        ['employee_id' => $employee->id, 'type' => 'clock_in', 'punched_at' => '2026-09-14 00:30:00', 'source' => 'web'],
        ['employee_id' => $employee->id, 'type' => 'clock_out', 'punched_at' => '2026-09-14 09:00:00', 'source' => 'web'],
    ]);

    $this->artisan('attendance:recompute', ['--dry-run' => true])
        ->expectsOutputToContain('status: undertime → late')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($record->fresh()->rules)->toBeNull()
        ->and($record->fresh()->status)->toBe('undertime');

    $this->artisan('attendance:recompute', ['--from' => '2026-09-01', '--to' => '2026-09-30'])->assertSuccessful();

    $fresh = $record->fresh();

    expect($fresh->status)->toBe('late')
        ->and($fresh->late_minutes)->toBe(30)
        ->and($fresh->undertime_minutes)->toBe(0)
        ->and($fresh->rules['work_schedule_id'])->toBe($employee->work_schedule_id)
        ->and($fresh->scheduled_start_at->toIso8601String())->toBe('2026-09-14T00:00:00+00:00');
});

test('attendance:recompute refuses a malformed date', function () {
    $this->artisan('attendance:recompute', ['--from' => '14/09/2026'])->assertFailed();
});

test('attendance:prune-orphan-days deletes only the empty day a refused clock-out left', function () {
    $employee = shiftWorker('22:00', '06:00');

    AttendanceRecord::factory()->create([
        'employee_id' => $employee->id,
        'work_date' => '2026-09-14',
        'status' => 'incomplete',
        'first_in_at' => '2026-09-14 14:00:00',
        'last_out_at' => null,
    ]);
    $orphan = AttendanceRecord::factory()->absent()->create(['employee_id' => $employee->id, 'work_date' => '2026-09-15']);
    $explained = AttendanceRecord::factory()->absent()->create([
        'employee_id' => $employee->id,
        'work_date' => '2026-09-16',
        'remarks' => 'Called in sick.',
    ]);

    $this->artisan('attendance:prune-orphan-days', ['--dry-run' => true])
        ->expectsOutputToContain('1 empty day would be deleted')
        ->assertSuccessful();

    expect(AttendanceRecord::whereKey($orphan->id)->exists())->toBeTrue();

    $this->artisan('attendance:prune-orphan-days')->assertSuccessful();

    expect(AttendanceRecord::whereKey($orphan->id)->exists())->toBeFalse()
        ->and(AttendanceRecord::whereKey($explained->id)->exists())->toBeTrue()
        ->and(AttendanceRecord::count())->toBe(2);
});
