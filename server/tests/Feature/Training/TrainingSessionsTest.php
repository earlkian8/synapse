<?php

use App\Models\CalendarFeed;
use App\Models\Employee;
use App\Models\TrainingAttendance;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use App\Support\Training\TrainingSessions;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| A program's sessions and the attendance taken at them (ADR 0074): inside the
| program's dates, repeated daily, attendance from the day on for the people
| holding a seat, each person's rate, and the month calendar.
*/

function tssProgram(array $attributes = []): TrainingProgram
{
    return TrainingProgram::create([
        'name' => 'Leadership Lab',
        'start_date' => today()->subDays(3),
        'end_date' => today()->addDays(10),
        ...$attributes,
    ]);
}

function tssSeat(TrainingProgram $program, string $status = 'enrolled'): TrainingEnrollment
{
    return $program->enrollments()->create(['employee_id' => Employee::factory()->create()->id, 'status' => $status]);
}

/** Today on the organisation's clock — the one sessions are judged on. */
function tssToday(): CarbonImmutable
{
    return CarbonImmutable::parse(OrganizationClock::today());
}

function tssSession(TrainingProgram $program, int $daysFromToday = 0, array $attributes = []): TrainingSession
{
    return $program->sessions()->create([
        'session_date' => tssToday()->addDays($daysFromToday)->toDateString(),
        'starts_at' => '09:00',
        'ends_at' => '12:00',
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->hr = actingAsSuperAdmin();
});

test('HR schedules a session inside the program’s dates', function () {
    $program = tssProgram();

    $this->post(route('training.sessions.store', $program), [
        'title' => 'Day 1',
        'session_date' => tssToday()->addDay()->toDateString(),
        'starts_at' => '09:00',
        'ends_at' => '17:00',
        'location' => 'Room B',
    ])->assertRedirect();
    assertToast('success', 'Session scheduled.');

    $session = $program->sessions()->firstOrFail();

    expect($session->title)->toBe('Day 1')
        ->and($session->startTime())->toBe('09:00')
        ->and($session->endTime())->toBe('17:00');
});

test('a repeated session makes one per weekday up to a date', function () {
    $program = tssProgram(['start_date' => '2030-03-01', 'end_date' => '2030-03-31']);

    // Friday 1 March 2030 to Thursday 7 March: Fri, Mon, Tue, Wed, Thu.
    $this->post(route('training.sessions.store', $program), [
        'session_date' => '2030-03-01',
        'starts_at' => '13:00',
        'ends_at' => '17:00',
        'repeat_until' => '2030-03-07',
        'weekdays_only' => true,
    ]);
    assertToast('success', '5 sessions scheduled.');

    expect($program->sessions()->chronological()->pluck('session_date')->map->toDateString()->all())
        ->toBe(['2030-03-01', '2030-03-04', '2030-03-05', '2030-03-06', '2030-03-07']);
});

test('a session outside the dates, ending before it starts, or repeated too far is refused beside its field', function () {
    $program = tssProgram(['start_date' => '2030-03-01', 'end_date' => '2030-03-05']);
    $base = ['session_date' => '2030-03-02', 'starts_at' => '09:00', 'ends_at' => '12:00'];

    $this->post(route('training.sessions.store', $program), [...$base, 'session_date' => '2030-04-01'])
        ->assertSessionHasErrors(['session_date' => 'Sessions fall within the program’s dates (Mar 1, 2030 – Mar 5, 2030).']);

    $this->post(route('training.sessions.store', $program), [...$base, 'ends_at' => '08:00'])
        ->assertSessionHasErrors(['ends_at' => 'A session ends after it starts.']);

    $this->post(route('training.sessions.store', $program), [...$base, 'repeat_until' => '2030-03-09'])
        ->assertSessionHasErrors('repeat_until');

    $wide = tssProgram(['name' => 'Year long', 'start_date' => '2030-01-01', 'end_date' => '2030-12-31']);
    $this->post(route('training.sessions.store', $wide), [...$base, 'repeat_until' => '2030-06-30'])
        ->assertSessionHasErrors(['repeat_until' => 'That makes more than 60 sessions — repeat until an earlier day.']);

    expect(TrainingSession::query()->count())->toBe(0);
});

test('a program’s dates cannot move off a session it has', function () {
    $program = tssProgram(['start_date' => '2030-03-01', 'end_date' => '2030-03-10']);
    tssSession($program, attributes: ['session_date' => '2030-03-09']);

    $this->post(route('training.update', $program), ['name' => $program->name, 'start_date' => '2030-03-01', 'end_date' => '2030-03-05'])
        ->assertSessionHasErrors(['start_date' => 'A session falls outside those dates — move or cancel it first.']);

    expect($program->refresh()->end_date->toDateString())->toBe('2030-03-10');

    $this->post(route('training.update', $program), ['name' => $program->name, 'start_date' => '2030-02-20', 'end_date' => '2030-03-12'])->assertRedirect();
    assertToast('success', 'Training program updated.');
});

test('attendance is taken from the session’s day on, for the people holding a seat', function () {
    $program = tssProgram();
    $today = tssSession($program);
    $later = tssSession($program, 2);
    $ana = tssSeat($program);
    $ben = tssSeat($program);
    $dropped = tssSeat($program, 'dropped');
    $elsewhere = tssSeat(tssProgram(['name' => 'Other']));

    $this->put(route('training.sessions.attendance', $later), ['marks' => [['enrollment_id' => $ana->id, 'status' => 'present']]]);
    assertToast('warning', 'Attendance can be taken from the day of the session.');

    $this->put(route('training.sessions.attendance', $today), ['marks' => [['enrollment_id' => $elsewhere->id, 'status' => 'present']]]);
    assertToast('warning', 'only be taken for people enrolled in this program');

    $this->put(route('training.sessions.attendance', $today), ['marks' => [['enrollment_id' => $dropped->id, 'status' => 'present']]]);
    assertToast('warning', 'only be taken for people enrolled in this program');

    $this->put(route('training.sessions.attendance', $today), ['marks' => [
        ['enrollment_id' => $ana->id, 'status' => 'present'],
        ['enrollment_id' => $ben->id, 'status' => 'absent'],
    ]]);
    assertToast('success', 'Attendance saved.');

    expect($today->attendances()->pluck('status', 'training_enrollment_id')->all())->toBe([$ana->id => 'present', $ben->id => 'absent']);

    // A mark is changed, or cleared with null.
    $this->put(route('training.sessions.attendance', $today), ['marks' => [
        ['enrollment_id' => $ana->id, 'status' => 'late'],
        ['enrollment_id' => $ben->id, 'status' => null],
    ]]);

    expect($today->attendances()->pluck('status', 'training_enrollment_id')->all())->toBe([$ana->id => 'late']);
});

test('present and late count as attended, excused is left out of the rate', function () {
    $program = tssProgram(['start_date' => today()->subDays(10)]);
    $seat = tssSeat($program);
    $marks = ['present', 'late', 'absent', 'excused'];

    foreach ($marks as $i => $status) {
        tssSession($program, -$i - 1)->attendances()->create(['training_enrollment_id' => $seat->id, 'status' => $status]);
    }

    expect(TrainingSessions::rates($program)[$seat->id])->toBe(['attended' => 2, 'counted' => 3, 'rate' => 67]);

    $this->get(route('training.show', $program))->assertInertia(fn (Assert $page) => $page
        ->where('attendance.by_enrollment.'.$seat->id.'.rate', 67)
        ->where('attendance.held', 4)
        ->where('attendance.rate', 67)
        ->has('sessions', 4)
        ->where('sessions.0.tally.excused', 1)
        // The sheet reads each mark against its enrollment.
        ->where('sessions.0.marks.0.enrollment_id', $seat->id)
        ->where('sessions.0.marks.0.status', 'excused'));
});

test('a session with attendance cannot move to a day that has not come; cancelling one takes its marks', function () {
    $program = tssProgram();
    $session = tssSession($program);
    $seat = tssSeat($program);
    $session->attendances()->create(['training_enrollment_id' => $seat->id, 'status' => 'present']);

    $this->patch(route('training.sessions.update', $session), [
        'session_date' => tssToday()->addDays(3)->toDateString(),
        'starts_at' => '09:00',
        'ends_at' => '12:00',
    ])->assertSessionHasErrors('session_date');

    $this->delete(route('training.sessions.destroy', $session))->assertRedirect();
    assertToast('success', 'Session cancelled.');

    expect(TrainingAttendance::query()->count())->toBe(0);
});

test('the schedule shows a month’s sessions across programs, and flags ones with no attendance', function () {
    $program = tssProgram(['start_date' => '2030-03-01', 'end_date' => '2030-03-31']);
    tssSession($program, attributes: ['session_date' => '2030-03-04']);
    tssSession($program, attributes: ['session_date' => '2030-04-04']);
    tssProgram(['name' => 'Nothing yet', 'start_date' => '2030-03-10', 'end_date' => '2030-03-12']);

    $this->get(route('training.schedule', ['month' => '2030-03']))->assertInertia(fn (Assert $page) => $page
        ->component('training/schedule')
        ->where('month', '2030-03')
        ->has('sessions', 1)
        ->where('sessions.0.program.name', 'Leadership Lab')
        ->where('sessions.0.is_held', false)
        ->has('unscheduled', 1)
        ->where('unscheduled.0.name', 'Nothing yet'));

    $this->get(route('training.schedule', ['month' => 'nonsense']))->assertInertia(fn (Assert $page) => $page
        ->where('month', now()->format('Y-m')));
});

test('a person downloads their own session, and nobody else’s', function () {
    $program = tssProgram();
    $session = tssSession($program, 2, ['title' => 'Day 2', 'location' => 'Room B']);

    $user = actingAsUserWith(['training.participate']);
    $employee = Employee::factory()->create(['user_id' => $user->id]);

    $this->get(route('training.me.session-ics', $session))->assertNotFound();

    $program->enrollments()->create(['employee_id' => $employee->id, 'status' => 'enrolled']);

    $response = $this->get(route('training.me.session-ics', $session))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/calendar')
        ->and($response->getContent())
        ->toContain('UID:training-session-'.$session->id.'@')
        ->toContain('SUMMARY:Leadership Lab — Day 2')
        ->toContain('LOCATION:Room B');
});

test('the calendar feed carries the sessions of the programs a person holds a seat on', function () {
    $program = tssProgram();
    tssSession($program, 2);
    tssSession(tssProgram(['name' => 'Not mine']), 3);

    $user = actingAsUserWith(['events.respond', 'training.participate']);
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $program->enrollments()->create(['employee_id' => $employee->id, 'status' => 'enrolled']);

    $token = CalendarFeed::issueFor($user)->plainToken();
    app(Tenancy::class)->forget();

    $body = $this->get(route('api.calendar.feed', $token))->assertOk()->getContent();

    expect($body)->toContain('SUMMARY:Leadership Lab')
        ->not->toContain('Not mine');
});

test('viewers see sessions; only managers schedule them', function () {
    $program = tssProgram();
    actingAsUserWith(['training.view']);

    $this->get(route('training.schedule'))->assertOk();
    $this->post(route('training.sessions.store', $program), ['session_date' => today()->toDateString(), 'starts_at' => '09:00', 'ends_at' => '10:00'])
        ->assertForbidden();
});
