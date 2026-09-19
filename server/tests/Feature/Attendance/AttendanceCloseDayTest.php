<?php

use App\Jobs\RecomputeAttendanceRange;
use App\Models\AttendancePeriod;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Notifications\SystemNotification;
use App\Services\Assistant\Modules\AttendanceModule;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\DayCloser;
use App\Support\Attendance\ScheduleAssigner;
use App\Support\Attendance\SchedulePatternWriter;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| ADR 0041 — attendance days close themselves: the end-of-day job records the
| days nobody punched, deals with forgotten clock-outs by policy and sends one
| digest; reminders go to whoever has not clocked in; and recorded days follow
| leave, holidays and the roster when those change.
|
| The week is Mon 2026-09-14 … Sun 2026-09-20, on Manila's clock unless a test
| says otherwise. Friday is the 18th.
*/

function at(string $local, string $zone = 'Asia/Manila'): void
{
    test()->travelTo(CarbonImmutable::parse($local, $zone));
}

function closeDays(): void
{
    test()->artisan('attendance:close-day')->assertSuccessful();
}

/** An active member of the current organisation holding these permissions. */
function memberWith(array $permissions): User
{
    $user = User::factory()->create();
    $user->roles()->attach(makeRole('role-'.str()->random(6), $permissions));
    $user->memberships()->syncWithoutDetaching([testOrganization()->id => ['is_default' => true, 'joined_at' => now()]]);

    return $user;
}

/** A policy setting, laid over the fallback, as the company default. */
function closePolicy(array $settings): array
{
    return array_replace_recursive(AttendancePolicySettings::fallback()->toArray(), $settings);
}

// ── Materialising the days nobody punched ────────────────────────────────────

test('closing a day records everybody who was due and did not punch — absent, on leave, a holiday', function () {
    at('2026-09-19 09:00');
    $absent = requestWorker();
    $onLeave = requestWorker();
    $worked = requestWorker();
    $notYetHired = requestWorker();
    $notYetHired->forceFill(['date_hired' => '2026-09-19'])->save();
    $gone = requestWorker();
    $gone->forceFill(['employment_status' => 'resigned'])->save();

    LeaveRequest::factory()->create([
        'employee_id' => $onLeave->id, 'start_date' => '2026-09-18', 'end_date' => '2026-09-18', 'status' => 'approved',
    ]);
    workedDay($worked, '2026-09-18', ['time_in' => '08:00', 'time_out' => '17:00']);

    closeDays();

    $days = AttendanceRecord::query()->whereDate('work_date', '2026-09-18')->get()->keyBy('employee_id');

    expect($days)->toHaveCount(3)
        ->and($days[$absent->id]->status)->toBe('absent')
        ->and($days[$absent->id]->closed_at)->not->toBeNull()
        ->and($days[$absent->id]->rules['schedule_name'])->toBe('Day Shift')
        ->and($days[$onLeave->id]->status)->toBe('on_leave')
        ->and($days[$worked->id]->status)->toBe('present')
        ->and($days->has($notYetHired->id))->toBeFalse()
        ->and($days->has($gone->id))->toBeFalse()
        ->and(testOrganization()->refresh()->attendance_closed_through->toDateString())->toBe('2026-09-18')
        ->and(testOrganization()->attendance_closed_from->toDateString())->toBe('2026-09-18');
});

test('a holiday nobody works is recorded as a holiday, and a rest day is not recorded at all', function () {
    at('2026-09-21 09:00');
    $employee = requestWorker();
    testOrganization()->forceFill(['attendance_closed_through' => '2026-09-17'])->save();
    Holiday::create(['name' => 'Founders Day', 'date' => '2026-09-18', 'type' => 'regular', 'is_recurring' => false]);

    closeDays();

    $days = AttendanceRecord::query()->where('employee_id', $employee->id)->orderBy('work_date')->get();

    expect($days->map(fn ($day) => $day->work_date->toDateString().' '.$day->status)->all())->toBe(['2026-09-18 holiday'])
        ->and(testOrganization()->refresh()->attendance_closed_through->toDateString())->toBe('2026-09-20');
});

test('a day is not closed while somebody could still clock in to it', function () {
    // Friday 16:00: the day shift has not ended.
    at('2026-09-18 16:00');
    requestWorker();
    testOrganization()->forceFill(['attendance_closed_through' => '2026-09-17'])->save();

    closeDays();

    expect(AttendanceRecord::count())->toBe(0)
        ->and(testOrganization()->refresh()->attendance_closed_through->toDateString())->toBe('2026-09-17');
});

test('each organisation closes on its own clock', function () {
    $manila = testOrganization();
    requestWorker();

    $newYork = Organization::factory()->create(['timezone' => 'America/New_York']);
    app(Tenancy::class)->runFor($newYork, function () use ($newYork) {
        requestWorker();
        $newYork->forceFill(['timezone' => 'America/New_York'])->save();
    });

    // 00:30 UTC on Saturday: Saturday morning in Manila, Friday evening in New York.
    at('2026-09-19 00:30', 'UTC');
    closeDays();

    expect($manila->refresh()->attendance_closed_through->toDateString())->toBe('2026-09-18')
        ->and($newYork->refresh()->attendance_closed_through->toDateString())->toBe('2026-09-17')
        ->and(AttendanceRecord::withoutGlobalScopes()->where('organization_id', $manila->id)->pluck('work_date')->map->toDateString()->all())->toBe(['2026-09-18'])
        ->and(AttendanceRecord::withoutGlobalScopes()->where('organization_id', $newYork->id)->pluck('work_date')->map->toDateString()->all())->toBe(['2026-09-17']);
});

// ── Forgotten clock-outs ─────────────────────────────────────────────────────

test('a night shift’s forgotten clock-out is closed the morning after, at the shift’s end, and waits for sign-off', function () {
    at('2026-09-17 21:50');
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();
    $night = WorkSchedule::create(['name' => 'Night Shift', 'type' => 'fixed', 'grace_minutes' => 0, 'cycle_length_days' => 7]);
    app(SchedulePatternWriter::class)->write($night, array_map(fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '22:00', 'end' => '06:00']],
        'required_minutes' => 480,
    ], range(0, 6)));
    AttendancePolicy::create([
        'name' => 'Close at shift end', 'is_default' => true, 'settings_version' => 1,
        'settings' => closePolicy(['missing_clock_out' => ['action' => 'auto_close_at_shift_end']]),
    ]);
    $employee = Employee::factory()->create(['work_schedule_id' => null]);
    app(ScheduleAssigner::class)->assign($employee, $night, '2026-09-01');

    app(AttendanceClock::class)->punch($employee->refresh(), 'clock_in', ['source' => 'mobile']);

    // Friday 07:00: the shift could still claim a clock-out until 13:50.
    at('2026-09-18 07:00');
    closeDays();

    $record = AttendanceRecord::sole();
    expect($record->status)->toBe('incomplete')
        ->and($record->closed_at)->toBeNull()
        ->and(testOrganization()->refresh()->attendance_closed_through)->toBeNull();

    at('2026-09-18 14:00');
    closeDays();

    $record->refresh();
    $out = $record->punches()->where('type', 'clock_out')->sole();

    expect($record->work_date->toDateString())->toBe('2026-09-17')
        ->and($out->source)->toBe('system')
        ->and($out->punched_at->setTimezone('Asia/Manila')->format('Y-m-d H:i'))->toBe('2026-09-18 06:00')
        ->and($record->status)->toBe('present')
        ->and($record->flags)->toContain('auto_closed')
        ->and($record->approval_status)->toBe('pending')
        ->and(testOrganization()->refresh()->attendance_closed_through->toDateString())->toBe('2026-09-17');
});

test('a policy that flags forgotten clock-outs leaves the day open for HR, marked as missing one', function () {
    at('2026-09-18 08:00');
    $employee = requestWorker(null, closePolicy(['missing_clock_out' => ['action' => 'flag']]));
    app(AttendanceClock::class)->punch($employee, 'clock_in', ['source' => 'mobile']);

    at('2026-09-19 09:00');
    closeDays();

    $record = AttendanceRecord::sole();

    expect($record->status)->toBe('incomplete')
        ->and($record->flags)->toContain('missing_clock_out')
        ->and($record->closed_at)->not->toBeNull()
        ->and($record->approval_status)->toBeNull()
        ->and($record->punches()->count())->toBe(1);
});

test('closing a while after the shift writes the clock-out that long after it', function () {
    at('2026-09-18 08:00');
    $employee = requestWorker(null, closePolicy(['missing_clock_out' => ['action' => 'auto_close_after_minutes', 'after_minutes' => 120]]));
    app(AttendanceClock::class)->punch($employee, 'clock_in', ['source' => 'mobile']);

    at('2026-09-19 09:00');
    closeDays();

    // 08:00 to 19:00 is eleven hours against eight required.
    expect(AttendancePunch::where('source', 'system')->sole()->punched_at->setTimezone('Asia/Manila')->format('H:i'))->toBe('19:00')
        ->and(AttendanceRecord::sole()->worked_minutes)->toBe(660)
        ->and(AttendanceRecord::sole()->flags)->toContain('auto_closed');
});

// ── Idempotent, and one digest each ──────────────────────────────────────────

test('closing twice changes nothing the second time, and each recipient hears once', function () {
    Notification::fake();
    at('2026-09-19 09:00');

    $viewer = memberWith(['attendance.view']);
    $otherViewer = memberWith(['attendance.view']);
    $managerUser = memberWith(['attendance.clock']);
    $manager = Employee::factory()->create(['user_id' => $managerUser->id]);

    $absent = requestWorker();
    $absent->forceFill(['manager_id' => $manager->id])->save();
    $alsoAbsent = requestWorker();

    $snapshot = fn (): array => AttendanceRecord::query()->orderBy('id')->get()
        ->map(fn (AttendanceRecord $record): string => $record->id.' '.$record->status.' '.$record->updated_at->toIso8601String())
        ->all();

    closeDays();
    $firstRun = $snapshot();

    at('2026-09-19 10:00');
    closeDays();

    // The two reports, and the manager — who did not punch either.
    expect($snapshot())->toBe($firstRun)->toHaveCount(3);

    Notification::assertSentToTimes($viewer, SystemNotification::class, 1);
    Notification::assertSentToTimes($otherViewer, SystemNotification::class, 1);
    // A manager who cannot see the board hears about their own report only.
    Notification::assertSentTo($managerUser, SystemNotification::class, function (SystemNotification $notification) use ($absent, $alsoAbsent) {
        return str_contains($notification->body, $absent->full_name) && ! str_contains($notification->body, $alsoAbsent->full_name);
    });
    Notification::assertSentToTimes($managerUser, SystemNotification::class, 1);
    Notification::assertSentTo($viewer, SystemNotification::class, fn (SystemNotification $notification) => str_contains($notification->body, '3 absent without leave')
        && $notification->title === 'Attendance exceptions for Fri, Sep 18');
});

test('a date with nothing wrong sends no digest', function () {
    Notification::fake();
    at('2026-09-19 09:00');
    memberWith(['attendance.view']);
    workedDay(requestWorker(), '2026-09-18', ['time_in' => '08:00', 'time_out' => '17:00']);

    closeDays();

    Notification::assertNothingSent();
});

test('a day in a locked period is not written, and the job moves past it', function () {
    at('2026-09-19 09:00');
    requestWorker();
    AttendancePeriod::create(['start_date' => '2026-09-16', 'end_date' => '2026-09-18', 'status' => 'locked', 'locked_at' => now()]);

    closeDays();

    expect(AttendanceRecord::count())->toBe(0)
        ->and(testOrganization()->refresh()->attendance_closed_through->toDateString())->toBe('2026-09-18');
});

// ── Reminders ────────────────────────────────────────────────────────────────

test('whoever has not clocked in by the policy’s minutes is reminded, once', function () {
    Notification::fake();
    at('2026-09-18 08:20');

    $reminded = memberWith(['attendance.clock']);
    $late = requestWorker($reminded, closePolicy(['reminders' => ['clock_in_after_minutes' => 15]]));

    $clockedIn = requestWorker(memberWith(['attendance.clock']));
    app(AttendanceClock::class)->punch($clockedIn, 'clock_in', ['source' => 'mobile']);

    $onLeaveUser = memberWith(['attendance.clock']);
    LeaveRequest::factory()->create(['employee_id' => requestWorker($onLeaveUser)->id, 'start_date' => '2026-09-18', 'end_date' => '2026-09-18', 'status' => 'approved']);

    $remoteUser = memberWith(['attendance.clock']);
    AttendanceRequest::create([
        'employee_id' => requestWorker($remoteUser)->id, 'type' => 'remote_work', 'start_date' => '2026-09-18', 'end_date' => '2026-09-18',
        'payload' => [], 'reason' => 'Home.', 'status' => 'approved',
    ]);

    $this->artisan('attendance:remind')->assertSuccessful();
    $this->artisan('attendance:remind')->assertSuccessful();

    Notification::assertSentToTimes($reminded, SystemNotification::class, 1);
    Notification::assertSentTo($reminded, SystemNotification::class, fn (SystemNotification $notification) => str_contains($notification->body, '8:00 AM'));
    Notification::assertNotSentTo($clockedIn->user, SystemNotification::class);
    Notification::assertNotSentTo($onLeaveUser, SystemNotification::class);
    Notification::assertNotSentTo($remoteUser, SystemNotification::class);
});

test('nobody is reminded before the minutes have passed, on a rest day, on a holiday, or without a policy asking', function () {
    Notification::fake();
    $user = memberWith(['attendance.clock']);
    requestWorker($user, closePolicy(['reminders' => ['clock_in_after_minutes' => 30]]));

    at('2026-09-18 08:20');
    $this->artisan('attendance:remind');

    at('2026-09-19 09:00'); // Saturday
    $this->artisan('attendance:remind');

    Holiday::create(['name' => 'Founders Day', 'date' => '2026-09-21', 'type' => 'regular', 'is_recurring' => false]);
    at('2026-09-21 09:00');
    $this->artisan('attendance:remind');

    Notification::assertNothingSent();
});

// ── Recorded days follow what they depend on ─────────────────────────────────

test('approving leave turns the day the job recorded as absent into leave', function () {
    at('2026-09-19 09:00');
    $employee = requestWorker();
    closeDays();
    expect(AttendanceRecord::sole()->status)->toBe('absent');

    Bus::fake([RecomputeAttendanceRange::class]);
    $leave = LeaveRequest::factory()->create(['employee_id' => $employee->id, 'start_date' => '2026-09-18', 'end_date' => '2026-09-18', 'status' => 'pending']);
    Bus::assertNotDispatched(RecomputeAttendanceRange::class);

    $leave->update(['status' => 'approved']);
    Bus::assertDispatchedAfterResponse(RecomputeAttendanceRange::class, fn ($job) => $job->from === '2026-09-18' && $job->employeeIds === [$employee->id]);

    (new RecomputeAttendanceRange(testOrganization()->id, [$employee->id], '2026-09-18', '2026-09-18', 'a leave change'))->handle(app(Tenancy::class), app(AttendanceClock::class), app(DayCloser::class));

    expect(AttendanceRecord::sole()->status)->toBe('on_leave');
});

test('the recompute runs once the response has gone, with no worker', function () {
    at('2026-09-19 09:00');
    $employee = requestWorker();
    closeDays();

    LeaveRequest::factory()->create(['employee_id' => $employee->id, 'start_date' => '2026-09-18', 'end_date' => '2026-09-18', 'status' => 'approved']);
    app()->terminate();

    expect(AttendanceRecord::sole()->status)->toBe('on_leave');
});

test('inside a locked period nothing moves', function () {
    at('2026-09-19 09:00');
    $employee = requestWorker();
    closeDays();
    AttendancePeriod::create(['start_date' => '2026-09-16', 'end_date' => '2026-09-18', 'status' => 'locked', 'locked_at' => now()]);

    LeaveRequest::factory()->create(['employee_id' => $employee->id, 'start_date' => '2026-09-18', 'end_date' => '2026-09-18', 'status' => 'approved']);
    app()->terminate();

    expect(AttendanceRecord::sole()->status)->toBe('absent');
});

test('a holiday added late reaches a day somebody worked, without re-judging its schedule', function () {
    at('2026-09-19 09:00');
    $employee = requestWorker();
    $record = workedDay($employee, '2026-09-18', ['time_in' => '08:00', 'time_out' => '17:00']);
    $rulesBefore = $record->rules;

    Holiday::create(['name' => 'Founders Day', 'date' => '2026-09-18', 'type' => 'regular', 'is_recurring' => false]);
    app()->terminate();

    $record->refresh();

    expect($record->rules['holiday_name'])->toBe('Founders Day')
        ->and($record->holiday_minutes)->toBe($record->worked_minutes)
        ->and($record->rules['schedule_name'])->toBe($rulesBefore['schedule_name'])
        ->and($record->rules['grace_minutes'])->toBe($rulesBefore['grace_minutes']);
});

test('a new assignment that makes a recorded absence a rest day makes it a day off', function () {
    at('2026-09-19 09:00');
    $employee = requestWorker();
    closeDays();

    $fourDays = WorkSchedule::create(['name' => 'Mon–Thu', 'type' => 'fixed', 'grace_minutes' => 0, 'cycle_length_days' => 7]);
    app(SchedulePatternWriter::class)->write($fourDays, array_map(fn (int $i): array => [
        'is_rest_day' => $i >= 4,
        'segments' => [['start' => '07:00', 'end' => '17:00']],
        'required_minutes' => 540,
    ], range(0, 6)));

    app(ScheduleAssigner::class)->assign($employee, $fourDays, '2026-09-18');
    app()->terminate();

    expect(AttendanceRecord::sole()->status)->toBe('day_off');
});

// ── Seeing it ────────────────────────────────────────────────────────────────

test('the board’s live filter shows who has not clocked in yet', function () {
    $this->withoutVite();
    at('2026-09-18 08:30');
    actingAsUserWith(['attendance.view']);

    $missing = requestWorker();
    $in = requestWorker();
    app(AttendanceClock::class)->punch($in, 'clock_in', ['source' => 'mobile']);
    $leave = requestWorker();
    LeaveRequest::factory()->create(['employee_id' => $leave->id, 'start_date' => '2026-09-18', 'end_date' => '2026-09-18', 'status' => 'approved']);

    $this->get(route('attendance.index', ['status' => 'not_clocked_in']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('records', 1)
            ->where('records.0.employee.id', $missing->id)
            ->where('filters.status', 'not_clocked_in'));
});

test('the assistant finds who has not clocked in, and who punched off site', function () {
    at('2026-09-18 08:30');
    $user = actingAsUserWith(['attendance.view']);
    $missing = requestWorker();
    $away = requestWorker(null, closePolicy(['capture' => ['geofence' => 'flag']]));
    WorkLocation::create(['name' => 'HQ', 'latitude' => 14.5547, 'longitude' => 121.0244, 'radius_meters' => 100]);

    at('2026-09-17 08:00');
    app(AttendanceClock::class)->punch($away, 'clock_in', ['source' => 'mobile', 'latitude' => 14.70, 'longitude' => 121.0244]);
    at('2026-09-17 17:00');
    app(AttendanceClock::class)->punch($away, 'clock_out', ['source' => 'mobile', 'latitude' => 14.70, 'longitude' => 121.0244]);
    at('2026-09-18 08:30');

    $module = app(AttendanceModule::class);

    $notIn = $module->run($user, 'find_attendance_exceptions', ['kind' => 'not_clocked_in']);
    $offSite = $module->run($user, 'find_attendance_exceptions', ['kind' => 'outside_geofence', 'from' => '2026-09-14', 'to' => '2026-09-18']);

    expect($notIn->failed())->toBeFalse()
        ->and(collect($notIn->cards)->pluck('title')->all())->toContain($missing->full_name)
        ->and(collect($offSite->cards)->pluck('title')->all())->toBe([$away->full_name])
        ->and($offSite->cards[0]['subtitle'])->toStartWith('Thu, Sep 17');

    $denied = $module->run(actingAsUserWith(['attendance.request']), 'find_attendance_exceptions', []);
    expect($denied->failed())->toBeTrue();
});
