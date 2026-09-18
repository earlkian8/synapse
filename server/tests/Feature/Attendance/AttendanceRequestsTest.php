<?php

use App\Models\ActivityLog;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\SystemNotification;
use App\Services\Assistant\Modules\AttendanceModule;
use App\Support\Attendance\AttendanceCalculator;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceRequestApprover;
use App\Support\Attendance\AttendanceRequestException;
use App\Support\Attendance\DayContext;
use App\Support\Attendance\DayRules;
use App\Support\Attendance\ResolvedShift;
use App\Support\Attendance\ScheduleAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

/*
| ADR 0039 — attendance requests: employees ask for what the clock could not
| capture, a reviewer decides, and one approver applies every decision.
|
| "Today" is Friday 2026-09-18, noon in Manila. 2026-09-14 is a Monday. Times
| are wall-clock readings on the organisation's clock.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00', 'Asia/Manila'));
});

// ── Filing ───────────────────────────────────────────────────────────────────

dataset('invalid requests', [
    'no reason' => [['type' => 'overtime', 'start_date' => '2026-09-14', 'minutes' => 60], 'reason'],
    'a correction that proposes nothing' => [['type' => 'correction', 'start_date' => '2026-09-14', 'reason' => 'Forgot'], 'time_in'],
    'a correction for a day not yet begun' => [['type' => 'correction', 'start_date' => '2026-09-21', 'time_out' => '17:00', 'reason' => 'Forgot'], 'start_date'],
    'a malformed time' => [['type' => 'correction', 'start_date' => '2026-09-14', 'time_out' => '5pm', 'reason' => 'Forgot'], 'time_out'],
    'overtime without minutes' => [['type' => 'overtime', 'start_date' => '2026-09-14', 'reason' => 'Deadline'], 'minutes'],
    'overtime beyond a day' => [['type' => 'overtime', 'start_date' => '2026-09-14', 'minutes' => 2000, 'reason' => 'Deadline'], 'minutes'],
    'a range ending before it starts' => [['type' => 'remote_work', 'start_date' => '2026-09-14', 'end_date' => '2026-09-10', 'reason' => 'Home'], 'end_date'],
    'a range longer than a month' => [['type' => 'official_business', 'start_date' => '2026-09-01', 'end_date' => '2026-10-05', 'reason' => 'Site'], 'end_date'],
    'an unknown type' => [['type' => 'vacation', 'start_date' => '2026-09-14', 'reason' => 'Beach'], 'type'],
]);

test('each request type validates its own payload', function (array $payload, string $field) {
    $user = actingAsUserWith(['attendance.request']);
    requestWorker($user);

    $this->post(route('attendance.requests.store'), $payload)->assertSessionHasErrors($field);

    expect(AttendanceRequest::count())->toBe(0);
})->with('invalid requests');

test('an employee files a correction, and every reviewer but them is told', function () {
    Notification::fake();
    $user = actingAsUserWith(['attendance.request', 'attendance.requests.review']);
    $employee = requestWorker($user);
    $reviewer = User::factory()->create();
    $reviewer->roles()->attach(makeRole('reviewer', ['attendance.requests.review']));
    $reviewer->memberships()->syncWithoutDetaching([testOrganization()->id => ['is_default' => true, 'joined_at' => now()]]);
    $user->memberships()->syncWithoutDetaching([testOrganization()->id => ['is_default' => true, 'joined_at' => now()]]);

    $this->post(route('attendance.requests.store'), [
        'type' => 'correction',
        'start_date' => '2026-09-17',
        'time_out' => '18:00',
        'reason' => 'Forgot to clock out.',
    ])->assertSessionHasNoErrors();

    $request = AttendanceRequest::sole();

    expect($request->employee_id)->toBe($employee->id)
        ->and($request->status)->toBe('pending')
        ->and($request->requested_by)->toBe($user->id)
        ->and($request->payload)->toBe(['time_in' => null, 'break_start' => null, 'break_end' => null, 'time_out' => '18:00'])
        ->and(ActivityLog::where('log_name', 'attendance')->where('description', 'like', 'Filed a correction for Sep 17%')->exists())->toBeTrue();

    Notification::assertSentTo($reviewer, SystemNotification::class);
    Notification::assertNotSentTo($user, SystemNotification::class);
});

test('a second pending request for the same day is refused', function () {
    $user = actingAsUserWith(['attendance.request']);
    requestWorker($user);
    $payload = ['type' => 'overtime', 'start_date' => '2026-09-17', 'minutes' => 60, 'reason' => 'Release night'];

    $this->post(route('attendance.requests.store'), $payload);
    $this->post(route('attendance.requests.store'), $payload);

    assertToast('warning', 'already a pending overtime request');
    expect(AttendanceRequest::count())->toBe(1);
});

test('filing for somebody else needs attendance.manage', function () {
    $user = actingAsUserWith(['attendance.request']);
    requestWorker($user);
    $colleague = Employee::factory()->create();

    $this->post(route('attendance.requests.store'), [
        'type' => 'overtime', 'employee_id' => $colleague->id, 'start_date' => '2026-09-17', 'minutes' => 60, 'reason' => 'Covering',
    ])->assertForbidden();

    actingAsUserWith(['attendance.request', 'attendance.manage']);

    $this->post(route('attendance.requests.store'), [
        'type' => 'overtime', 'employee_id' => $colleague->id, 'start_date' => '2026-09-17', 'minutes' => 60, 'reason' => 'Covering',
    ])->assertSessionHasNoErrors();

    expect(AttendanceRequest::sole()->employee_id)->toBe($colleague->id);
});

test('filing needs attendance.request', function () {
    $user = actingAsUserWith(['attendance.clock']);
    requestWorker($user);

    $this->post(route('attendance.requests.store'), [
        'type' => 'overtime', 'start_date' => '2026-09-17', 'minutes' => 60, 'reason' => 'x x x',
    ])->assertForbidden();
});

// ── Corrections ──────────────────────────────────────────────────────────────

test('approving a correction writes correction punches, keeps the replaced ones and recomputes', function () {
    $reviewer = actingAsUserWith(['attendance.view', 'attendance.requests.review']);
    $employee = requestWorker();
    $record = workedDay($employee, '2026-09-17', ['time_in' => '08:40']);

    expect($record->status)->toBe('incomplete');

    $request = pendingRequest($employee, 'correction', '2026-09-17', ['time_in' => '08:00', 'time_out' => '17:30']);

    $this->patch(route('attendance.requests.review', $request->hashid), ['action' => 'approve', 'review_note' => 'Confirmed with the guard.'])
        ->assertSessionHasNoErrors();

    $record->refresh();
    $request->refresh();

    expect($request->status)->toBe('approved')
        ->and($request->reviewer_id)->toBe($reviewer->id)
        ->and($request->attendance_record_id)->toBe($record->id)
        ->and($record->status)->toBe('present')
        ->and($record->worked_minutes)->toBe(570)
        ->and($record->is_manual)->toBeTrue()
        ->and(localTimes($record))->toBe(['clock_in 08:00', 'clock_out 17:30'])
        ->and($record->punches()->pluck('source')->unique()->all())->toBe(['correction'])
        ->and($record->punches()->pluck('attendance_request_id')->unique()->all())->toBe([$request->id]);

    $replaced = AttendancePunch::onlyTrashed()->where('attendance_record_id', $record->id)->sole();

    expect($replaced->type)->toBe('clock_in')
        ->and($replaced->replaced_by_request_id)->toBe($request->id)
        ->and($replaced->punched_at->setTimezone('Asia/Manila')->format('H:i'))->toBe('08:40');

    $this->get(route('attendance.show', $record->hashid))
        ->assertJsonPath('data.replaced_punches.0.type', 'clock_in')
        ->assertJsonPath('data.replaced_punches.0.by_request', true)
        ->assertJsonPath('data.requests.0.type', 'correction');
});

test('a correction changes only the punches it names', function () {
    actingAsUserWith(['attendance.requests.review']);
    $employee = requestWorker();
    $record = workedDay($employee, '2026-09-17', ['time_in' => '07:55', 'break_start' => '12:00', 'break_end' => '13:00']);
    $original = $record->punches()->pluck('id')->all();

    $request = pendingRequest($employee, 'correction', '2026-09-17', ['time_out' => '17:05']);
    app(AttendanceRequestApprover::class)->approve($request, auth()->user());

    $record->refresh();

    expect(localTimes($record))->toBe(['clock_in 07:55', 'break_start 12:00', 'break_end 13:00', 'clock_out 17:05'])
        ->and(array_values(array_intersect($record->punches()->pluck('id')->all(), $original)))->toBe($original)
        ->and($record->status)->toBe('present')
        ->and(AttendancePunch::onlyTrashed()->count())->toBe(0);
});

test('a correction for a day nobody punched opens it', function () {
    actingAsUserWith(['attendance.requests.review']);
    $employee = requestWorker();

    $request = pendingRequest($employee, 'correction', '2026-09-16', ['time_in' => '08:00', 'time_out' => '17:00']);
    app(AttendanceRequestApprover::class)->approve($request, auth()->user());

    $record = AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', '2026-09-16')->sole();

    expect($record->status)->toBe('present')
        ->and($record->worked_minutes)->toBe(540);
});

test('a night-shift correction reads the clock-out as the next morning', function () {
    actingAsUserWith(['attendance.requests.review']);
    $employee = requestWorker();
    $record = workedDay($employee, '2026-09-16', ['time_in' => '22:00']);

    app(AttendanceRequestApprover::class)->approve(
        pendingRequest($employee, 'correction', '2026-09-16', ['time_out' => '06:00']),
        auth()->user(),
    );

    $out = $record->punches()->where('type', 'clock_out')->sole()->punched_at->setTimezone('Asia/Manila');

    expect($out->format('Y-m-d H:i'))->toBe('2026-09-17 06:00')
        ->and($record->refresh()->worked_minutes)->toBe(480);
});

// ── Overtime ─────────────────────────────────────────────────────────────────

test('approved overtime is capped at what was worked, and settles the day', function (int $asked, int $approved) {
    actingAsUserWith(['attendance.requests.review']);
    $employee = requestWorker(policy: ['overtime' => ['requires_approval' => true]]);
    $record = workedDay($employee, '2026-09-17', ['time_in' => '08:00', 'time_out' => '18:00']);

    expect($record->overtime_minutes)->toBe(120)
        ->and($record->approved_overtime_minutes)->toBe(0)
        ->and($record->flags)->toContain('unapproved_overtime')
        ->and($record->approval_status)->toBe('pending');

    app(AttendanceRequestApprover::class)->approve(pendingRequest($employee, 'overtime', '2026-09-17', ['minutes' => $asked]), auth()->user());

    $record->refresh();

    expect($record->approved_overtime_minutes)->toBe($approved)
        ->and($record->flags)->not->toContain('unapproved_overtime')
        ->and($record->approval_status)->toBeNull();
})->with([
    'asked for less than worked' => [60, 60],
    'asked for more than worked' => [180, 120],
]);

test('a pre-approval is matched when the day is worked', function () {
    actingAsUserWith(['attendance.requests.review']);
    $employee = requestWorker(policy: ['overtime' => ['requires_approval' => true]]);

    $request = pendingRequest($employee, 'overtime', '2026-09-21', ['minutes' => 90, 'pre_approval' => true]);
    app(AttendanceRequestApprover::class)->approve($request, auth()->user());

    expect(AttendanceRecord::count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-09-21 20:00', 'Asia/Manila'));
    $record = workedDay($employee, '2026-09-21', ['time_in' => '08:00', 'time_out' => '18:00']);

    expect($record->overtime_minutes)->toBe(120)
        ->and($record->approved_overtime_minutes)->toBe(90)
        ->and($record->approval_status)->toBeNull();
});

test('rejected overtime stays unapproved but no longer waits for a decision', function () {
    actingAsUserWith(['attendance.requests.review']);
    $employee = requestWorker(policy: ['overtime' => ['requires_approval' => true]]);
    $record = workedDay($employee, '2026-09-17', ['time_in' => '08:00', 'time_out' => '18:00']);

    app(AttendanceRequestApprover::class)->reject(pendingRequest($employee, 'overtime', '2026-09-17', ['minutes' => 120]), auth()->user(), 'Not agreed beforehand.');

    $record->refresh();

    expect($record->approved_overtime_minutes)->toBe(0)
        ->and($record->flags)->not->toContain('unapproved_overtime')
        ->and($record->approval_status)->toBeNull();
});

// ── Official business and remote work ────────────────────────────────────────

test('official business makes a day nobody punched a full working day', function () {
    actingAsUserWith(['attendance.requests.review']);
    $employee = requestWorker();

    // Wed–Sun: the weekend is a rest day and stays empty.
    app(AttendanceRequestApprover::class)->approve(
        pendingRequest($employee, 'official_business', '2026-09-16', ['location' => 'Client site'], '2026-09-20'),
        auth()->user(),
    );

    $records = AttendanceRecord::where('employee_id', $employee->id)->orderBy('work_date')->get();

    expect($records->map(fn ($r) => $r->work_date->toDateString())->all())->toBe(['2026-09-16', '2026-09-17', '2026-09-18'])
        ->and($records->pluck('status')->unique()->all())->toBe(['present'])
        ->and($records->pluck('worked_minutes')->unique()->all())->toBe([480])
        ->and($records->pluck('regular_minutes')->unique()->all())->toBe([480])
        ->and($records->first()->flags)->toBe(['official_business']);
});

test('a punched day on official business is neither late nor short', function () {
    actingAsUserWith(['attendance.requests.review']);
    $employee = requestWorker();
    $record = workedDay($employee, '2026-09-17', ['time_in' => '13:00', 'time_out' => '17:00']);

    expect($record->status)->toBe('late');

    app(AttendanceRequestApprover::class)->approve(pendingRequest($employee, 'official_business', '2026-09-17', []), auth()->user());

    $record->refresh();

    expect($record->status)->toBe('present')
        ->and($record->late_minutes)->toBe(0)
        ->and($record->undertime_minutes)->toBe(0)
        ->and($record->worked_minutes)->toBe(480)
        ->and($record->flags)->toContain('official_business');
});

test('remote work is marked on the day, and punches are still required', function () {
    actingAsUserWith(['attendance.requests.review']);
    $employee = requestWorker();
    $record = workedDay($employee, '2026-09-17', ['time_in' => '08:00', 'time_out' => '17:00']);

    app(AttendanceRequestApprover::class)->approve(pendingRequest($employee, 'remote_work', '2026-09-16', [], '2026-09-17'), auth()->user());

    expect($record->refresh()->flags)->toContain('remote_work')
        ->and(AttendanceRecord::whereDate('work_date', '2026-09-16')->exists())->toBeFalse();
});

test('remote work exempts the day from the geofence')->todo('Phase 4 — the geofence does not exist yet.');

test('the evaluator never lets official business excuse a rest day or leave', function () {
    $rest = DayRules::fromShift(ResolvedShift::fallback('2026-09-19'));
    $ob = new DayContext(officialBusiness: true);

    expect(AttendanceCalculator::evaluate(collect(), $rest, $ob)->status)->toBe('day_off')
        ->and(AttendanceCalculator::evaluate(collect(), DayRules::fromShift(ResolvedShift::fallback('2026-09-17')), new DayContext(onApprovedLeave: true, officialBusiness: true))->status)->toBe('on_leave');
});

// ── Who decides ──────────────────────────────────────────────────────────────

test('nobody reviews their own request', function () {
    $user = actingAsUserWith(['attendance.request', 'attendance.requests.review']);
    $employee = requestWorker($user);
    $request = pendingRequest($employee, 'overtime', '2026-09-17', ['minutes' => 60]);

    $this->patch(route('attendance.requests.review', $request->hashid), ['action' => 'approve']);

    assertToast('warning', "can't review your own");
    expect($request->refresh()->status)->toBe('pending');

    $this->get(route('attendance.requests.show', $request->hashid))->assertJsonPath('data.can.review', false);
});

test('a decided request cannot be decided again', function () {
    actingAsUserWith(['attendance.requests.review']);
    $request = pendingRequest(requestWorker(), 'overtime', '2026-09-17', ['minutes' => 60]);
    $approver = app(AttendanceRequestApprover::class);

    $approver->reject($request, auth()->user());

    expect(fn () => $approver->approve($request->refresh(), auth()->user()))
        ->toThrow(AttendanceRequestException::class, 'already been rejected');
});

test('every request endpoint is permission-gated', function () {
    $employee = requestWorker();
    $request = pendingRequest($employee, 'overtime', '2026-09-17', ['minutes' => 60]);

    actingAsUserWith(['attendance.request']);

    $this->patch(route('attendance.requests.review', $request->hashid), ['action' => 'approve'])->assertForbidden();
    $this->patch(route('attendance.requests.bulk-review'), ['action' => 'approve', 'hashids' => [$request->hashid]])->assertForbidden();
    $this->get(route('attendance.requests.show', $request->hashid))->assertForbidden();
    $this->patch(route('attendance.requests.cancel', $request->hashid));

    assertToast('warning', 'only cancel your own');
    expect($request->refresh()->status)->toBe('pending');
});

test('the decision reaches the employee with the note', function () {
    Notification::fake();
    $employeeUser = User::factory()->create();
    $employee = requestWorker($employeeUser);
    actingAsUserWith(['attendance.requests.review']);

    app(AttendanceRequestApprover::class)->reject(pendingRequest($employee, 'overtime', '2026-09-17', ['minutes' => 60]), auth()->user(), 'Not this week.');

    Notification::assertSentTo($employeeUser, SystemNotification::class, fn (SystemNotification $n) => str_contains(json_encode($n->toArray($employeeUser)), 'Not this week.'));
});

test('an employee cancels their own pending request, but not a decided one', function () {
    $user = actingAsUserWith(['attendance.request']);
    $employee = requestWorker($user);
    $pending = pendingRequest($employee, 'overtime', '2026-09-17', ['minutes' => 60]);
    $decided = pendingRequest($employee, 'overtime', '2026-09-16', ['minutes' => 60]);
    $decided->update(['status' => 'approved']);

    $this->patch(route('attendance.requests.cancel', $pending->hashid));
    $this->patch(route('attendance.requests.cancel', $decided->hashid));

    expect($pending->refresh()->status)->toBe('cancelled')
        ->and($decided->refresh()->status)->toBe('approved');
});

test('bulk review decides each on its own, leaving the reviewer’s own', function () {
    $user = actingAsUserWith(['attendance.request', 'attendance.requests.review']);
    $own = pendingRequest(requestWorker($user), 'overtime', '2026-09-17', ['minutes' => 60]);
    $others = collect(range(1, 3))->map(fn () => pendingRequest(Employee::factory()->create(), 'remote_work', '2026-09-17', []));

    $this->patch(route('attendance.requests.bulk-review'), [
        'action' => 'approve',
        'review_note' => 'Fine.',
        'hashids' => [$own->hashid, ...$others->pluck('hashid')],
    ]);

    assertToast('success', 'Approved 3 requests. 1 could not be');
    expect($own->refresh()->status)->toBe('pending')
        ->and(AttendanceRequest::where('status', 'approved')->where('review_note', 'Fine.')->count())->toBe(3);
});

test('the requests tab lists pending requests for reviewers only', function () {
    actingAsUserWith(['attendance.view', 'attendance.requests.review']);
    pendingRequest(requestWorker(), 'overtime', '2026-09-17', ['minutes' => 60]);

    $this->get(route('attendance.index', ['tab' => 'requests']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('requests', 1)
            ->where('requests.0.type', 'overtime')
            ->where('stats.pending_requests', 1)
            ->where('can.reviewRequests', true));

    actingAsUserWith(['attendance.view']);

    $this->get(route('attendance.index', ['tab' => 'requests']))
        ->assertInertia(fn (Assert $page) => $page->where('requests', null));
});

test('my attendance lists my own requests', function () {
    $user = actingAsUserWith(['attendance.clock', 'attendance.request']);
    $employee = requestWorker($user);
    pendingRequest($employee, 'overtime', '2026-09-17', ['minutes' => 60]);
    pendingRequest(Employee::factory()->create(), 'overtime', '2026-09-17', ['minutes' => 60]);

    $this->get(route('attendance.me'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/me')
            ->has('requests', 1)
            ->where('requests.0.can.cancel', true)
            ->where('can.request', true));
});

// ── Sign-off ─────────────────────────────────────────────────────────────────

test('signing a day off approves its overtime, and survives a recompute', function () {
    actingAsUserWith(['attendance.manage']);
    $employee = requestWorker(policy: ['overtime' => ['requires_approval' => true]]);
    $record = workedDay($employee, '2026-09-17', ['time_in' => '08:00', 'time_out' => '18:00']);

    expect($record->approval_status)->toBe('pending');

    $this->patch(route('attendance.approve', $record->hashid))->assertSessionHasNoErrors();

    $record->refresh();

    expect($record->approval_status)->toBe('approved')
        ->and($record->approved_overtime_minutes)->toBe(120)
        ->and($record->signed_off_overtime_minutes)->toBe(120);

    $this->artisan('attendance:recompute')->assertSuccessful();

    expect($record->refresh()->approved_overtime_minutes)->toBe(120)
        ->and($record->approval_status)->toBe('approved');
});

test('overtime a later correction adds is not signed off with the rest', function () {
    actingAsUserWith(['attendance.manage', 'attendance.requests.review']);
    $employee = requestWorker(policy: ['overtime' => ['requires_approval' => true]]);
    $record = workedDay($employee, '2026-09-17', ['time_in' => '08:00', 'time_out' => '18:00']);
    $clock = app(AttendanceClock::class);

    $clock->signOff($record, auth()->user());
    $clock->applyManualPunches($record->refresh(), ['time_in' => '08:00', 'time_out' => '19:00'], auth()->id());

    expect($record->refresh()->overtime_minutes)->toBe(180)
        ->and($record->approved_overtime_minutes)->toBe(120);
});

test('approve all signs off only the pending days, never the signer’s own', function () {
    $user = actingAsUserWith(['attendance.manage']);
    $mine = requestWorker($user, ['overtime' => ['requires_approval' => true]]);
    $theirs = Employee::factory()->create(['work_schedule_id' => null]);
    app(ScheduleAssigner::class)->assign($theirs, WorkSchedule::sole(), '2026-09-01');

    $own = workedDay($mine, '2026-09-17', ['time_in' => '08:00', 'time_out' => '18:00']);
    $pending = workedDay($theirs, '2026-09-17', ['time_in' => '08:00', 'time_out' => '18:00']);
    $clean = workedDay($theirs, '2026-09-16', ['time_in' => '08:00', 'time_out' => '16:00']);

    $this->patch(route('attendance.approve-all'));

    expect($pending->refresh()->approval_status)->toBe('approved')
        ->and($own->refresh()->approval_status)->toBe('pending')
        ->and($clean->refresh()->approval_status)->toBeNull()
        ->and($clean->approved_at)->toBeNull();
});

// ── The mobile API ───────────────────────────────────────────────────────────

test('the mobile app files, lists and cancels my own requests', function () {
    $user = actingAsUserWith(['attendance.request']);
    $employee = requestWorker($user);
    Sanctum::actingAs($user);

    $this->postJson('/api/attendance/requests', [
        'type' => 'correction', 'start_date' => '2026-09-17', 'time_out' => '17:30', 'reason' => 'Phone died.',
        // Ignored: the app files for its own employee only.
        'employee_id' => Employee::factory()->create()->id,
    ])->assertForbidden();

    $id = $this->postJson('/api/attendance/requests', [
        'type' => 'correction', 'start_date' => '2026-09-17', 'time_out' => '17:30', 'reason' => 'Phone died.',
    ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

    $this->postJson('/api/attendance/requests', ['type' => 'overtime', 'start_date' => '2026-09-17', 'reason' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['minutes', 'reason']);

    $this->getJson('/api/attendance/requests')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/attendance/requests/{$id}")->assertOk()->assertJsonPath('data.payload.time_out', '17:30');
    $this->patchJson("/api/attendance/requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect(AttendanceRequest::sole()->employee_id)->toBe($employee->id);
});

test('the mobile app never reaches somebody else’s request', function () {
    $user = actingAsUserWith(['attendance.request']);
    requestWorker($user);
    $theirs = pendingRequest(Employee::factory()->create(), 'overtime', '2026-09-17', ['minutes' => 60]);
    Sanctum::actingAs($user);

    $this->getJson("/api/attendance/requests/{$theirs->id}")->assertNotFound();
    $this->patchJson("/api/attendance/requests/{$theirs->id}/cancel")->assertNotFound();
});

// ── The assistant ────────────────────────────────────────────────────────────

test('the assistant files my correction through the same validation and filer', function () {
    $user = actingAsUserWith(['attendance.request']);
    $employee = requestWorker($user);
    $module = app(AttendanceModule::class);

    expect(collect($module->tools($user))->pluck('name')->all())
        ->toContain('file_attendance_request', 'find_attendance_requests')
        ->not->toContain('review_attendance_request', 'record_punch');

    $missing = $module->run($user, 'file_attendance_request', ['type' => 'correction', 'date' => '2026-09-17', 'time_out' => '6:00 PM']);
    expect($missing->status)->toBe('error');

    $result = $module->run($user, 'file_attendance_request', [
        'type' => 'correction', 'date' => '2026-09-17', 'time_out' => '6:00 PM', 'reason' => 'Forgot to clock out',
    ]);

    expect($result->status)->toBe('done')
        ->and(AttendanceRequest::sole()->payload['time_out'])->toBe('18:00')
        ->and(AttendanceRequest::sole()->employee_id)->toBe($employee->id);
});

test('the assistant decides through the approver, and refuses a reviewer’s own', function () {
    $user = actingAsUserWith(['attendance.request', 'attendance.requests.review']);
    $mine = requestWorker($user);
    $other = Employee::factory()->create(['first_name' => 'Ramona', 'last_name' => 'Flores']);
    pendingRequest($mine, 'overtime', '2026-09-17', ['minutes' => 60]);
    $theirs = pendingRequest($other, 'remote_work', '2026-09-17', []);
    $module = app(AttendanceModule::class);

    $own = $module->run($user, 'review_attendance_request', ['employee' => $mine->full_name, 'action' => 'approve']);
    $done = $module->run($user, 'review_attendance_request', ['employee' => 'Ramona Flores', 'action' => 'approve', 'review_note' => 'OK']);

    expect($own->status)->toBe('error')
        ->and($done->status)->toBe('done')
        ->and($theirs->refresh()->status)->toBe('approved');
});
