<?php

use App\Models\Employee;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Training for the person taking it (ADR 0074): one way in, the programs open to
| them, joining or asking to join, HR deciding a request, and withdrawing — with
| a request never taking a seat and never graded as if it were on the roster.
*/

/** A user holding `training.participate` (and more), with their employee record. */
function tsParticipant(array $permissions = [], array $employee = []): array
{
    $role = makeRole('ts-'.Str::random(8), ['training.participate', ...$permissions]);
    $user = User::factory()->create(['is_active' => true]);
    $user->roles()->attach($role);

    $person = Employee::factory()->create([
        'user_id' => $user->id,
        'employment_status' => 'active',
        'middle_name' => null,
        'suffix' => null,
        ...$employee,
    ]);

    return [$user, $person];
}

function tsProgram(array $attributes = []): TrainingProgram
{
    return TrainingProgram::create([
        'name' => 'Fire Safety',
        'start_date' => today()->addDays(10),
        'end_date' => today()->addDays(14),
        'enrollment_mode' => 'open',
        ...$attributes,
    ]);
}

/** An HR user holding training.manage, with an account notices reach. */
function tsManager(): User
{
    $role = makeRole('ts-hr-'.Str::random(8), ['training.view', 'training.manage']);
    $user = User::factory()->create(['is_active' => true]);
    $user->roles()->attach($role);

    return $user;
}

beforeEach(function () {
    Notification::fake();
    [$this->user, $this->employee] = tsParticipant(employee: ['first_name' => 'Lara', 'last_name' => 'Quinto']);
    $this->hr = tsManager();
    $this->actingAs($this->user);
});

test('the module has one way in: someone who only takes part lands on their own training', function () {
    $this->get(route('training.index'))->assertRedirect(route('training.me'));
    $this->get(route('training.schedule'))->assertForbidden();
    $this->get(route('training.budget'))->assertForbidden();
    $this->get(route('training.certificates.index'))->assertForbidden();

    $this->actingAs($this->hr)->get(route('training.index'))->assertOk();

    actingAsUserWith(['leave.request']);
    $this->get(route('training.index'))->assertForbidden();
    $this->get(route('training.me'))->assertForbidden();
});

test('Browse lists only programs open to sign-ups that are not over, with where the person stands', function () {
    $open = tsProgram(['name' => 'Fire Safety']);
    tsProgram(['name' => 'Power BI', 'enrollment_mode' => 'approval']);
    tsProgram(['name' => 'HR Only', 'enrollment_mode' => 'closed']);
    tsProgram(['name' => 'Last Year', 'start_date' => today()->subYear(), 'end_date' => today()->subYear()->addDays(2)]);
    tsProgram(['name' => 'Archived'])->delete();
    $open->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'enrolled']);

    $this->get(route('training.catalog'))->assertInertia(fn (Assert $page) => $page
        ->component('training/catalog')
        ->has('programs', 2)
        ->where('programs.0.name', 'Fire Safety')
        ->where('programs.0.mine.status', 'enrolled')
        ->where('programs.1.name', 'Power BI')
        ->where('programs.1.mine', null)
        ->where('has_employee', true));
});

test('joining an open program takes a seat at once, while seats last', function () {
    $program = tsProgram(['capacity' => 1]);

    $this->post(route('training.join', $program))->assertRedirect();
    assertToast('success', 'You’re in.');

    expect($program->enrollments()->where('employee_id', $this->employee->id)->value('status'))->toBe('enrolled');

    [$late] = tsParticipant();
    $this->actingAs($late)->post(route('training.join', $program))->assertRedirect();
    assertToast('warning', 'This program is full.');
    expect($program->enrollments()->count())->toBe(1);
});

test('a closed program, one that is over, and joining twice are refused in words', function () {
    $closed = tsProgram(['name' => 'Closed', 'enrollment_mode' => 'closed']);
    $over = tsProgram(['name' => 'Over', 'start_date' => today()->subDays(10), 'end_date' => today()->subDay()]);
    $open = tsProgram(['name' => 'Open']);

    $this->post(route('training.join', $closed));
    assertToast('warning', 'HR enrolls people in this program');

    $this->post(route('training.join', $over));
    assertToast('warning', 'This program is over');

    $this->post(route('training.join', $open));
    $this->post(route('training.join', $open));
    assertToast('warning', 'You are already on this program.');

    expect(TrainingEnrollment::query()->count())->toBe(1);
});

test('asking to join takes no seat, and whoever runs training is told', function () {
    $program = tsProgram(['enrollment_mode' => 'approval', 'capacity' => 1]);

    $this->post(route('training.join', $program))->assertRedirect();
    assertToast('success', 'Request sent.');

    $request = $program->enrollments()->firstOrFail();

    expect($request->status)->toBe('requested')
        ->and($program->enrollments()->active()->count())->toBe(0)
        ->and($program->analytics()['total'])->toBe(0);

    Notification::assertSentTo($this->hr, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Request to join Fire Safety'
        && $n->url === '/training/'.$program->hashid);
    Notification::assertNotSentTo($this->user, SystemNotification::class);

    $this->post(route('training.join', $program));
    assertToast('warning', 'You have already asked to join');
});

test('HR approves a request — a seat permitting — and the person is told', function () {
    $program = tsProgram(['enrollment_mode' => 'approval', 'capacity' => 1]);
    $request = $program->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'requested']);
    [, $other] = tsParticipant();
    $second = $program->enrollments()->create(['employee_id' => $other->id, 'status' => 'requested']);

    $this->actingAs($this->hr)->post(route('training.enrollments.approve', $request))->assertRedirect();
    assertToast('success', 'Lara Quinto is in.');

    expect($request->refresh()->status)->toBe('enrolled')
        ->and($request->decided_by)->toBe($this->hr->id);
    Notification::assertSentTo($this->user, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'You’re in: Fire Safety');

    $this->post(route('training.enrollments.approve', $second));
    assertToast('warning', 'The program is full');
    expect($second->refresh()->status)->toBe('requested');

    $this->post(route('training.enrollments.approve', $request));
    assertToast('warning', 'already been decided');
});

test('a declined request carries HR’s note to the person, and they cannot ask again', function () {
    $program = tsProgram(['enrollment_mode' => 'approval']);
    $request = $program->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'requested']);

    $this->actingAs($this->hr)->post(route('training.enrollments.decline', $request), ['note' => 'The March run suits your team.'])->assertRedirect();
    assertToast('success', 'Request declined.');

    expect($request->refresh()->status)->toBe('declined')
        ->and($request->decision_note)->toBe('The March run suits your team.');
    Notification::assertSentTo($this->user, SystemNotification::class, fn (SystemNotification $n): bool => str_contains($n->body, 'The March run suits your team.'));

    $this->actingAs($this->user)->post(route('training.join', $program));
    assertToast('warning', 'HR declined your request');

    $this->get(route('training.catalog'))->assertInertia(fn (Assert $page) => $page
        ->where('programs.0.mine.status', 'declined')
        ->where('programs.0.mine.decision_note', 'The March run suits your team.'));
});

test('HR’s enroll takes in someone who asked, on their request', function () {
    $program = tsProgram(['enrollment_mode' => 'approval']);
    $request = $program->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'requested']);

    $this->actingAs($this->hr)->post(route('training.enrollments.store', $program), ['employee_ids' => [$this->employee->id]]);
    assertToast('success', 'Employee enrolled.');

    expect($request->refresh()->status)->toBe('enrolled')
        ->and($program->enrollments()->count())->toBe(1);
    Notification::assertSentTo($this->user, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'You’re enrolled in Fire Safety'
        && $n->url === '/training/me');
});

test('a request is decided, never graded or bulk-changed', function () {
    $program = tsProgram(['enrollment_mode' => 'approval']);
    $request = $program->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'requested']);
    $this->actingAs($this->hr);

    $this->patch(route('training.enrollments.update', $request), ['status' => 'completed']);
    assertToast('warning', 'approve or decline it first');

    $this->patch(route('training.enrollments.bulk'), ['action' => 'complete', 'enrollment_ids' => [$request->id]]);

    expect($request->refresh()->status)->toBe('requested');

    $this->patch(route('training.enrollments.update', $request), ['status' => 'requested'])->assertSessionHasErrors('status');
});

test('a person withdraws a request any time, and leaves a seat only before the program starts', function () {
    $approval = tsProgram(['name' => 'Asked', 'enrollment_mode' => 'approval']);
    $upcoming = tsProgram(['name' => 'Upcoming']);
    $running = tsProgram(['name' => 'Running', 'start_date' => today()->subDay(), 'end_date' => today()->addDays(5)]);

    $request = $approval->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'requested']);
    $seat = $upcoming->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'enrolled']);
    $started = $running->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'enrolled']);

    $this->delete(route('training.me.withdraw', $request));
    assertToast('success', 'Request withdrawn.');

    $this->delete(route('training.me.withdraw', $seat));
    assertToast('success', 'You’ve left the program.');

    $this->delete(route('training.me.withdraw', $started));
    assertToast('warning', 'The program has started');

    expect(TrainingEnrollment::query()->pluck('id')->all())->toBe([$started->id]);
});

test('someone else’s enrollment is a 404 here', function () {
    [, $other] = tsParticipant();
    $theirs = tsProgram()->enrollments()->create(['employee_id' => $other->id, 'status' => 'requested']);

    $this->delete(route('training.me.withdraw', $theirs))->assertNotFound();

    expect($theirs->exists())->toBeTrue();
});

test('My training shows the person’s own programs, sessions coming up and nothing of anyone else', function () {
    $mine = tsProgram(['name' => 'Mine', 'start_date' => today()->subDay(), 'end_date' => today()->addDays(9)]);
    $mine->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'enrolled']);
    $mine->sessions()->create(['session_date' => today()->addDays(2), 'starts_at' => '09:00', 'ends_at' => '12:00', 'location' => 'Room B']);

    $theirs = tsProgram(['name' => 'Theirs']);
    $theirs->enrollments()->create(['employee_id' => Employee::factory()->create()->id, 'status' => 'enrolled']);
    $theirs->sessions()->create(['session_date' => today()->addDays(11), 'starts_at' => '09:00', 'ends_at' => '12:00']);

    $this->get(route('training.me'))->assertInertia(fn (Assert $page) => $page
        ->component('training/me')
        ->has('enrollments', 1)
        ->where('enrollments.0.program.name', 'Mine')
        ->where('enrollments.0.can_withdraw', false)
        ->has('sessions', 1)
        ->where('sessions.0.location', 'Room B')
        ->where('has_employee', true));
});

test('without an employee record, My training says so', function () {
    actingAsUserWith(['training.participate']);

    $this->get(route('training.me'))->assertInertia(fn (Assert $page) => $page
        ->where('has_employee', false)
        ->has('enrollments', 0));

    $this->post(route('training.join', tsProgram()))->assertForbidden();
});

test('the overview lists the requests waiting, and a program’s page keeps them off the roster', function () {
    $program = tsProgram(['enrollment_mode' => 'approval']);
    $program->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'requested']);
    $program->enrollments()->create(['employee_id' => Employee::factory()->create()->id, 'status' => 'enrolled']);

    $this->actingAs($this->hr)->get(route('training.index'))->assertInertia(fn (Assert $page) => $page
        ->has('requests', 1)
        ->where('requests.0.employee.full_name', 'Lara Quinto')
        ->where('stats.requests', 1)
        ->where('nav.requests', 1)
        ->where('programs.0.pending_count', 1)
        ->where('programs.0.enrollments_count', 1));

    $this->get(route('training.show', $program))->assertInertia(fn (Assert $page) => $page
        ->has('requests', 1)
        ->has('program.enrollments', 1)
        ->where('analytics.total', 1));
});
