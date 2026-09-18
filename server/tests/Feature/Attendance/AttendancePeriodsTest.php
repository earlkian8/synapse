<?php

use App\Models\ActivityLog;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Assistant\Modules\AttendanceModule;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceLockedException;
use App\Support\Attendance\AttendanceRequestApprover;
use App\Support\Attendance\PeriodCalendar;
use App\Support\Attendance\PeriodLocker;
use App\Support\Attendance\PeriodSummaryExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| ADR 0039 — attendance periods: generated on the company's calendar, locked
| with a checklist, frozen by one guard in the engine, and kept as the file
| payroll received.
|
| "Today" is Friday 2026-09-18, noon in Manila. requestWorker(), workedDay()
| and pendingRequest() are in tests/Pest.php.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00', 'Asia/Manila'));
    Storage::fake('local');
});

function period(string $start, string $end, string $status = 'open'): AttendancePeriod
{
    testOrganization();

    return AttendancePeriod::create(['start_date' => $start, 'end_date' => $end, 'status' => $status]);
}

/** The periods as "start..end". */
function periodRanges(): array
{
    return AttendancePeriod::query()->orderBy('start_date')->get()
        ->map(fn (AttendancePeriod $p): string => $p->start_date->toDateString().'..'.$p->end_date->toDateString())
        ->all();
}

// ── The calendar ─────────────────────────────────────────────────────────────

test('a company’s first periods are the last one, the current one and the next', function (string $frequency, array $expected) {
    testOrganization()->forceFill(['timezone' => 'Asia/Manila', 'attendance_period_frequency' => $frequency])->save();

    expect(app(PeriodCalendar::class)->ensureCurrent())->toBe(3)
        ->and(periodRanges())->toBe($expected)
        ->and(app(PeriodCalendar::class)->ensureCurrent())->toBe(0);
})->with([
    'semi-monthly' => ['semi_monthly', ['2026-09-01..2026-09-15', '2026-09-16..2026-09-30', '2026-10-01..2026-10-15']],
    'monthly' => ['monthly', ['2026-08-01..2026-08-31', '2026-09-01..2026-09-30', '2026-10-01..2026-10-31']],
    'weekly' => ['weekly', ['2026-09-07..2026-09-13', '2026-09-14..2026-09-20', '2026-09-21..2026-09-27']],
    'bi-weekly' => ['bi_weekly', ['2026-08-31..2026-09-13', '2026-09-14..2026-09-27', '2026-09-28..2026-10-11']],
]);

test('a change of calendar carries on from the last period, with no gap or overlap', function () {
    testOrganization()->forceFill(['timezone' => 'Asia/Manila', 'attendance_period_frequency' => 'weekly'])->save();
    period('2026-09-07', '2026-09-13');

    app(PeriodCalendar::class)->ensureCurrent();

    expect(periodRanges())->toBe(['2026-09-07..2026-09-13', '2026-09-14..2026-09-20', '2026-09-21..2026-09-27']);

    testOrganization()->forceFill(['attendance_period_frequency' => 'semi_monthly'])->save();
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00', 'Asia/Manila'));

    app(PeriodCalendar::class)->ensureCurrent();

    expect(periodRanges())->toBe([
        '2026-09-07..2026-09-13', '2026-09-14..2026-09-20', '2026-09-21..2026-09-27',
        // A short period onto the new calendar: the 28th to the month's end.
        '2026-09-28..2026-09-30',
    ]);
});

test('period settings and generation are permission-gated and logged', function () {
    actingAsUserWith(['attendance.view']);

    $this->post(route('attendance.periods.generate'))->assertForbidden();
    $this->patch(route('attendance.periods.settings'), ['frequency' => 'monthly', 'reminder_days' => 3])->assertForbidden();

    actingAsUserWith(['attendance.period.manage']);

    $this->patch(route('attendance.periods.settings'), ['frequency' => 'fortnightly', 'reminder_days' => 3])->assertSessionHasErrors('frequency');
    $this->patch(route('attendance.periods.settings'), ['frequency' => 'monthly', 'reminder_days' => 3])->assertSessionHasNoErrors();
    $this->post(route('attendance.periods.generate'))->assertSessionHasNoErrors();

    expect(testOrganization()->refresh()->attendance_period_frequency)->toBe('monthly')
        ->and(testOrganization()->attendance_lock_reminder_days)->toBe(3)
        ->and(periodRanges())->toBe(['2026-08-01..2026-08-31', '2026-09-01..2026-09-30', '2026-10-01..2026-10-31'])
        ->and(ActivityLog::where('description', 'like', 'Generated 3 attendance periods')->exists())->toBeTrue();
});

// ── The checklist and the lock ───────────────────────────────────────────────

test('the checklist counts what is still open in a period', function () {
    actingAsUserWith(['attendance.view', 'attendance.period.manage']);
    $employee = requestWorker(policy: ['overtime' => ['requires_approval' => true]]);
    workedDay($employee, '2026-09-02', ['time_in' => '08:00']);
    workedDay($employee, '2026-09-03', ['time_in' => '08:00', 'time_out' => '18:00']);
    pendingRequest($employee, 'remote_work', '2026-09-15', [], '2026-09-16');
    $period = period('2026-09-01', '2026-09-15');

    expect(app(PeriodLocker::class)->checklist($period))->toBe([
        'pending_requests' => 1,
        'incomplete_days' => 1,
        'pending_sign_offs' => 1,
        'clear' => false,
    ]);

    $this->get(route('attendance.index', ['tab' => 'periods']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('periods.items.0.checklist.incomplete_days', 1)
            ->where('periods.settings.frequency', 'semi_monthly'));
});

test('an open checklist needs a reason to lock', function () {
    actingAsUserWith(['attendance.period.manage']);
    $employee = requestWorker();
    workedDay($employee, '2026-09-02', ['time_in' => '08:00']);
    $period = period('2026-09-01', '2026-09-15');

    $this->post(route('attendance.periods.lock', $period->hashid));

    assertToast('warning', 'still has open items');
    expect($period->refresh()->status)->toBe('open');

    $this->post(route('attendance.periods.lock', $period->hashid), ['reason' => 'Payroll cut-off is today.']);

    expect($period->refresh()->status)->toBe('locked')
        ->and($period->lock_note)->toBe('Payroll cut-off is today.')
        ->and(ActivityLog::where('description', 'like', 'Locked the attendance period Sep 1 – 15 with open items')->exists())->toBeTrue();
});

test('locking writes the period summary and keeps it as the period’s file', function () {
    $user = actingAsUserWith(['attendance.period.manage', 'attendance.period.unlock']);
    $employee = requestWorker();
    workedDay($employee, '2026-09-02', ['time_in' => '08:00', 'time_out' => '17:00']);
    $period = period('2026-09-01', '2026-09-15');

    $this->post(route('attendance.periods.lock', $period->hashid))->assertSessionHasNoErrors();

    $period->refresh();
    $file = Storage::disk('local')->get($period->export_path);
    [$header, $row] = array_map('str_getcsv', array_slice(explode("\n", trim($file)), 0, 2));

    expect($period->status)->toBe('locked')
        ->and($period->locked_by)->toBe($user->id)
        ->and($header)->toBe(PeriodSummaryExport::COLUMNS)
        ->and($row[0])->toBe($employee->full_name)
        ->and($row[3])->toBe('2026-09-01')
        ->and($row[10])->toBe('540');

    // What payroll received stays what it was, even after the day changes.
    $this->post(route('attendance.periods.unlock', $period->hashid), ['reason' => 'Correcting Sep 2.']);
    app(AttendanceClock::class)->applyManualPunches(AttendanceRecord::sole(), ['time_in' => '08:00', 'time_out' => '12:00'], $user->id);

    $response = $this->get(route('attendance.periods.export', $period->hashid))->assertOk();

    expect($response->streamedContent())->toBe($file);
});

// ── One guard, every path ────────────────────────────────────────────────────

test('a locked period refuses every change to its days', function () {
    $user = actingAsSuperAdmin();
    $employee = requestWorker(policy: ['overtime' => ['requires_approval' => true]]);
    $record = workedDay($employee, '2026-09-02', ['time_in' => '08:00', 'time_out' => '18:00']);
    $request = pendingRequest($employee, 'overtime', '2026-09-02', ['minutes' => 60]);
    $before = $record->only(['status', 'worked_minutes', 'approved_overtime_minutes', 'approval_status']);
    period('2026-09-01', '2026-09-15', 'locked');
    $clock = app(AttendanceClock::class);

    // The engine, directly.
    expect(fn () => $clock->applyManualPunches($record, ['time_in' => '09:00'], $user->id))->toThrow(AttendanceLockedException::class)
        ->and(fn () => $clock->punch($employee, 'clock_in', ['punched_at' => '2026-09-03 00:00:00']))->toThrow(AttendanceLockedException::class)
        ->and(fn () => $clock->reapplySchedule($record))->toThrow(AttendanceLockedException::class)
        ->and(fn () => $clock->signOff($record, $user))->toThrow(AttendanceLockedException::class)
        ->and(fn () => $clock->openRecord($employee, '2026-09-04'))->toThrow(AttendanceLockedException::class)
        ->and(fn () => app(AttendanceRequestApprover::class)->approve($request, $user))->toThrow(AttendanceLockedException::class);

    // Through the board.
    $this->post(route('attendance.update', $record->hashid), ['time_in' => '09:00', 'time_out' => '17:00']);
    assertToast('warning', 'locked attendance period (Sep 1 – 15)');
    $this->patch(route('attendance.approve', $record->hashid));
    $this->patch(route('attendance.reapply', $record->hashid));
    $this->delete(route('attendance.destroy', $record->hashid));
    $this->patch(route('attendance.requests.review', $request->hashid), ['action' => 'approve']);
    $this->post(route('attendance.requests.store'), ['type' => 'overtime', 'employee_id' => $employee->id, 'start_date' => '2026-09-03', 'minutes' => 30, 'reason' => 'Late fix']);
    assertToast('warning', 'locked attendance period');

    // Bulk paths leave the frozen day and say so.
    $this->patch(route('attendance.reapply-range'), ['from' => '2026-09-01', 'to' => '2026-09-30']);
    assertToast('info', 'Every recorded day in that period is locked');
    $this->artisan('attendance:recompute')->expectsOutputToContain('1 day in locked periods left as they are')->assertSuccessful();

    // …and the assistant, through the same engine.
    $result = app(AttendanceModule::class)->run($user, 'review_attendance_request', ['employee' => $employee->full_name, 'action' => 'approve']);
    expect($result->status)->toBe('error')->and($result->detail)->toContain('locked');

    expect(AttendanceRecord::sole()->only(['status', 'worked_minutes', 'approved_overtime_minutes', 'approval_status']))->toBe($before)
        ->and(AttendanceRecord::count())->toBe(1)
        ->and($request->refresh()->status)->toBe('pending')
        ->and(AttendanceRequest::count())->toBe(1);
});

test('a punch outside the locked period is untouched by it', function () {
    actingAsSuperAdmin();
    $employee = requestWorker();
    period('2026-09-01', '2026-09-15', 'locked');

    $record = app(AttendanceClock::class)->punch($employee, 'clock_in', ['punched_at' => CarbonImmutable::parse('2026-09-18 08:00', 'Asia/Manila')]);

    expect($record->work_date->toDateString())->toBe('2026-09-18')
        ->and($record->punches()->count())->toBe(1);
});

test('unlocking needs its own permission and a reason, is logged, and re-enables changes', function () {
    $user = actingAsUserWith(['attendance.period.manage']);
    $employee = requestWorker();
    $record = workedDay($employee, '2026-09-02', ['time_in' => '08:00', 'time_out' => '17:00']);
    $period = period('2026-09-01', '2026-09-15');
    app(PeriodLocker::class)->lock($period, $user);

    $this->post(route('attendance.periods.unlock', $period->hashid), ['reason' => 'Fix'])->assertForbidden();

    actingAsUserWith(['attendance.period.manage', 'attendance.period.unlock']);

    $this->post(route('attendance.periods.unlock', $period->hashid))->assertSessionHasErrors('reason');
    $this->post(route('attendance.periods.unlock', $period->hashid), ['reason' => 'Late correction from the site.'])->assertSessionHasNoErrors();

    expect($period->refresh()->status)->toBe('open')
        ->and($period->unlock_reason)->toBe('Late correction from the site.')
        ->and(ActivityLog::where('description', 'like', 'Unlocked the attendance period Sep 1 – 15')->exists())->toBeTrue();

    app(AttendanceClock::class)->applyManualPunches($record, ['time_in' => '09:00', 'time_out' => '17:00'], $user->id);

    expect($record->refresh()->status)->toBe('late');
});

test('a day in a locked period says so to the board', function () {
    actingAsUserWith(['attendance.view']);
    $record = workedDay(requestWorker(), '2026-09-02', ['time_in' => '08:00', 'time_out' => '17:00']);
    period('2026-09-01', '2026-09-15', 'locked');

    $this->get(route('attendance.show', $record->hashid))
        ->assertJsonPath('data.is_locked', true)
        ->assertJsonPath('data.locked_period', 'Sep 1 – 15');
});

// ── The reminder ─────────────────────────────────────────────────────────────

test('holders of attendance.period.manage are reminded once when a period comes due', function () {
    Notification::fake();
    $manager = actingAsUserWith(['attendance.period.manage']);
    $manager->memberships()->syncWithoutDetaching([testOrganization()->id => ['is_default' => true, 'joined_at' => now()]]);
    $bystander = User::factory()->create();
    $bystander->memberships()->syncWithoutDetaching([testOrganization()->id => ['is_default' => true, 'joined_at' => now()]]);
    testOrganization()->forceFill(['timezone' => 'Asia/Manila', 'attendance_lock_reminder_days' => 2])->save();

    // Ends 2026-09-30: due from 2026-09-28.
    period('2026-09-16', '2026-09-30');

    $this->artisan('attendance:periods')->assertSuccessful();
    Notification::assertNothingSentTo($manager);

    $this->travelTo(CarbonImmutable::parse('2026-09-28 08:00', 'Asia/Manila'));
    $this->artisan('attendance:periods')->assertSuccessful();
    $this->artisan('attendance:periods')->assertSuccessful();

    Notification::assertSentToTimes($manager, SystemNotification::class, 1);
    Notification::assertNotSentTo($bystander, SystemNotification::class);
    expect(AttendancePeriod::whereNotNull('reminded_at')->count())->toBe(1);
});

test('the periods tab is only for those who manage periods', function () {
    actingAsUserWith(['attendance.view']);

    $this->get(route('attendance.index', ['tab' => 'periods']))
        ->assertInertia(fn (Assert $page) => $page->where('periods', null)->where('can.managePeriods', false));
});
