<?php

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\TrainingProgram;
use App\Models\WorkLocation;
use App\Support\Attendance\RosterWriter;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| ADR 0083 — the app's My schedule: one person's week, from the same resolver and
| holiday calendar as the roster board, with their own leave on it.
*/

/** A day-shift worker signed in to the app as themselves. */
function scheduleWorker(): array
{
    $user = actingAsUserWith(['attendance.clock']);
    $employee = dayShiftWorker($user);
    Sanctum::actingAs($user);

    return [$user, $employee];
}

function scheduleLeave(Employee $employee, string $from, string $to, string $status, bool $halfDay = false): LeaveRequest
{
    return LeaveRequest::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create(['name' => 'Vacation Leave', 'color' => '#14B8A6'])->id,
        'start_date' => $from,
        'end_date' => $to,
        'days' => $halfDay ? 0.5 : 1,
        'is_half_day' => $halfDay,
        'half_day_period' => $halfDay ? 'morning' : null,
        'status' => $status,
    ]);
}

test('a week shows each day’s shift, the holiday, a change and the person’s leave', function () {
    [, $employee] = scheduleWorker();

    Holiday::create(['name' => 'Founders Day', 'date' => '2026-10-14', 'type' => 'regular', 'is_recurring' => false]);
    app(RosterWriter::class)->set($employee, '2026-10-15', [
        'segments' => [['start' => '13:00', 'end' => '22:00']],
        'reason' => 'Covering for Ben',
    ]);
    scheduleLeave($employee, '2026-10-13', '2026-10-13', 'pending', halfDay: true);
    scheduleLeave($employee, '2026-10-16', '2026-10-16', 'approved');

    $week = $this->getJson('/api/schedule?week=2026-10-15')->assertOk()->json('data');
    $days = collect($week['days'])->keyBy('date');

    expect($week['start'])->toBe('2026-10-12')
        ->and($week['end'])->toBe('2026-10-18')
        ->and($week['timezone'])->toBe('Asia/Manila')
        ->and($week['days'])->toHaveCount(7)
        // Monday: the usual day shift.
        ->and($days['2026-10-12']['label'])->toBe('08:00–17:00')
        ->and($days['2026-10-12']['schedule_name'])->toBe('Day Shift')
        ->and($days['2026-10-12']['is_due'])->toBeTrue()
        ->and($days['2026-10-12']['changed'])->toBeFalse()
        // Tuesday: leave that is only asked for excuses nothing yet.
        ->and($days['2026-10-13']['leave'])->toMatchArray(['type' => 'Vacation Leave', 'status' => 'pending', 'half_day' => true, 'half_day_period' => 'morning'])
        ->and($days['2026-10-13']['is_due'])->toBeTrue()
        // Wednesday: a day-off holiday.
        ->and($days['2026-10-14']['holiday'])->toBe(['name' => 'Founders Day', 'type' => 'regular', 'day_off' => true])
        ->and($days['2026-10-14']['is_due'])->toBeFalse()
        // Thursday: changed by HR for that date, without HR's note.
        ->and($days['2026-10-15']['label'])->toBe('13:00–22:00')
        ->and($days['2026-10-15']['changed'])->toBeTrue()
        ->and($days['2026-10-15'])->not->toHaveKeys(['reason', 'source'])
        ->and($days['2026-10-15']['required_minutes'])->toBe(540)
        // Friday: a whole day of approved leave.
        ->and($days['2026-10-16']['leave']['status'])->toBe('approved')
        ->and($days['2026-10-16']['is_due'])->toBeFalse()
        // The weekend.
        ->and($days['2026-10-17']['is_working_day'])->toBeFalse()
        ->and($days['2026-10-17']['label'])->toBe('Rest day')
        ->and($week['totals'])->toBe(['shifts' => 3, 'minutes' => 480 + 480 + 540, 'training' => 0]);
});

test('approved half a day is due for half the hours, and leave on a rest day is not shown', function () {
    [, $employee] = scheduleWorker();

    scheduleLeave($employee, '2026-10-12', '2026-10-12', 'approved', halfDay: true);
    scheduleLeave($employee, '2026-10-16', '2026-10-18', 'approved');

    $week = $this->getJson('/api/schedule?week=2026-10-12')->assertOk()->json('data');
    $days = collect($week['days'])->keyBy('date');

    expect($days['2026-10-12']['is_due'])->toBeTrue()
        ->and($days['2026-10-12']['due_minutes'])->toBe(240)
        ->and($days['2026-10-16']['leave'])->not->toBeNull()
        ->and($days['2026-10-17']['leave'])->toBeNull()
        ->and($days['2026-10-18']['leave'])->toBeNull()
        ->and($week['totals'])->toBe(['shifts' => 4, 'minutes' => 240 + 480 * 3, 'training' => 0]);
});

test('rejected and cancelled leave, and somebody else’s, stay off the week', function () {
    [, $employee] = scheduleWorker();
    $colleague = Employee::factory()->create();

    scheduleLeave($employee, '2026-10-12', '2026-10-12', 'rejected');
    scheduleLeave($employee, '2026-10-13', '2026-10-13', 'cancelled');
    scheduleLeave($colleague, '2026-10-14', '2026-10-14', 'approved');

    $days = collect($this->getJson('/api/schedule?week=2026-10-12')->json('data.days'));

    expect($days->pluck('leave')->filter()->all())->toBe([]);
});

test('a local holiday shows only for the people based at its site', function () {
    [, $employee] = scheduleWorker();
    $cebu = WorkLocation::create(['name' => 'Cebu Office', 'latitude' => 10.3157, 'longitude' => 123.8854, 'radius_meters' => 150]);
    $davao = WorkLocation::create(['name' => 'Davao Office', 'latitude' => 7.19, 'longitude' => 125.45, 'radius_meters' => 150]);
    $cebu->employees()->attach($employee->id, ['is_primary' => true]);

    Holiday::create(['name' => 'Cebu Day', 'date' => '2026-10-13', 'type' => 'special_non_working', 'is_recurring' => false])->locations()->sync([$cebu->id]);
    Holiday::create(['name' => 'Davao Day', 'date' => '2026-10-14', 'type' => 'special_non_working', 'is_recurring' => false])->locations()->sync([$davao->id]);

    $days = collect($this->getJson('/api/schedule?week=2026-10-12')->json('data.days'))->keyBy('date');

    expect($days['2026-10-13']['holiday']['name'])->toBe('Cebu Day')
        ->and($days['2026-10-14']['holiday'])->toBeNull();
});

test('without a week it is this week on the person’s clock, today marked', function () {
    scheduleWorker();
    $today = OrganizationClock::today();

    $week = $this->getJson('/api/schedule')->assertOk()->json('data');
    $todays = collect($week['days'])->firstWhere('date', $today);

    expect($week['start'])->toBe(CarbonImmutable::parse($today)->startOfWeek()->toDateString())
        ->and($week['today'])->toBe($today)
        ->and($todays['is_today'])->toBeTrue()
        ->and($todays['is_past'])->toBeFalse()
        ->and(collect($week['days'])->where('is_today', true))->toHaveCount(1);
});

test('the week must be a calendar date', function (string $week) {
    scheduleWorker();

    $this->getJson('/api/schedule?week='.urlencode($week))->assertUnprocessable()->assertJsonValidationErrors('week');
})->with(['not-a-date', '2026-13-40', '1999-12-31', '12/10/2026']);

test('an account with no employee record is refused, and a stranger is not let in', function () {
    $user = actingAsUserWith(['attendance.clock']);
    Sanctum::actingAs($user);

    $this->getJson('/api/schedule')->assertForbidden();

    app(Tenancy::class)->forget();
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/schedule')->assertUnauthorized();
});

test('the training sessions I hold a seat at sit on their days, at my site’s hour (ADR 0094)', function () {
    [, $employee] = scheduleWorker();

    $program = TrainingProgram::create(['name' => 'Forklift Safety', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'enrollment_mode' => 'open']);
    $program->enrollments()->create(['employee_id' => $employee->id, 'status' => 'enrolled']);
    $program->sessions()->create(['session_date' => '2026-10-13', 'starts_at' => '13:00', 'ends_at' => '16:00', 'location' => 'Room 2', 'title' => 'Day one']);
    $program->sessions()->create(['session_date' => '2026-10-13', 'starts_at' => '09:00', 'ends_at' => '11:00']);
    $program->sessions()->create(['session_date' => '2026-10-18', 'starts_at' => '23:00', 'ends_at' => '23:45']);
    $program->sessions()->create(['session_date' => '2026-10-20', 'starts_at' => '09:00', 'ends_at' => '11:00']);

    // Asked for, not yet a seat: its sessions are not on the week.
    $asked = TrainingProgram::create(['name' => 'Excel', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'enrollment_mode' => 'approval']);
    $asked->enrollments()->create(['employee_id' => $employee->id, 'status' => 'requested']);
    $asked->sessions()->create(['session_date' => '2026-10-14', 'starts_at' => '09:00', 'ends_at' => '11:00']);

    $week = $this->getJson('/api/schedule?week=2026-10-12')->assertOk()->json('data');
    $days = collect($week['days'])->keyBy('date');

    expect(collect($days['2026-10-13']['training'])->pluck('starts_at')->all())->toBe(['09:00', '13:00'])
        ->and($days['2026-10-13']['training'][1])->toBe([
            'id' => $days['2026-10-13']['training'][1]['id'],
            'title' => 'Day one',
            'starts_at' => '13:00',
            'ends_at' => '16:00',
            'location' => 'Room 2',
            'program' => ['hashid' => $program->hashid, 'name' => 'Forklift Safety'],
        ])
        ->and($days['2026-10-14']['training'])->toBe([])
        ->and($days['2026-10-18']['training'])->toHaveCount(1)
        // Training adds to a day; it excuses no work.
        ->and($days['2026-10-13']['is_due'])->toBeTrue()
        ->and($week['totals']['training'])->toBe(3);

    // A site an hour ahead sees the late session at midnight, on the next day — the next week.
    $tokyo = WorkLocation::create(['name' => 'Tokyo Office', 'latitude' => 35.68, 'longitude' => 139.76, 'radius_meters' => 150, 'timezone' => 'Asia/Tokyo']);
    $tokyo->employees()->attach($employee->id, ['is_primary' => true]);

    $days = collect($this->getJson('/api/schedule?week=2026-10-12')->json('data.days'))->keyBy('date');
    $next = collect($this->getJson('/api/schedule?week=2026-10-19')->json('data.days'))->keyBy('date');

    expect($days['2026-10-13']['training'][0]['starts_at'])->toBe('10:00')
        ->and($days['2026-10-18']['training'])->toBe([])
        ->and($next['2026-10-19']['training'][0])->toMatchArray(['starts_at' => '00:00', 'ends_at' => '00:45']);
});
