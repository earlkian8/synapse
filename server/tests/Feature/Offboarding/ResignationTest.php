<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\Organization;
use App\Models\ResignationRequest;
use App\Models\User;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
| Resignations the employee files themselves (ADR 0076): filing, withdrawing,
| HR's decision, the exit an accepted one becomes, who is told, and who may see
| what.
*/

/**
 * A member of staff who may resign, linked to an active employee.
 *
 * @return array{0: User, 1: Employee}
 */
function resignee(array $employee = []): array
{
    $role = makeRole('resign-'.Str::random(8), ['offboarding.resign']);
    $user = User::factory()->create(['is_active' => true]);
    $user->roles()->attach($role);
    $person = Employee::factory()->create(['user_id' => $user->id, 'employment_status' => 'active', 'middle_name' => null, 'suffix' => null, ...$employee]);

    return [$user, $person];
}

function resignDay(int $days): string
{
    return CarbonImmutable::parse(OrganizationClock::today())->addDays($days)->toDateString();
}

test('somebody who may only resign lands on their own section, and the board is closed to them', function () {
    [$user] = resignee();
    $this->actingAs($user);

    $this->get(route('offboarding.index'))->assertRedirect(route('offboarding.me'));
    $this->get(route('offboarding.me'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('offboarding/me')
        ->where('can_resign', true)
        ->where('notice_days', 30)
        ->where('exit', null));
    $this->get(route('offboarding.resignations.index'))->assertForbidden();
    $this->get(route('offboarding.interviews.index'))->assertForbidden();
});

test('an employee files a resignation, HR is told, and a second one waits its turn', function () {
    testOrganization();
    $hr = actingAsUserWith(['offboarding.view', 'offboarding.manage']);
    [$user, $employee] = resignee(['first_name' => 'Lara', 'last_name' => 'Quinto']);
    $this->actingAs($user);

    $this->post(route('offboarding.me.resign'), ['proposed_last_day' => resignDay(14), 'reason' => 'Moving to Cebu.'])->assertRedirect();
    assertToast('success', 'Resignation sent to HR.');

    $resignation = ResignationRequest::query()->sole();

    expect($resignation->employee_id)->toBe($employee->id)
        ->and($resignation->status)->toBe('pending')
        ->and($resignation->noticeDays())->toBe(14)
        ->and($hr->notifications()->count())->toBe(1)
        ->and($hr->notifications()->first()->data['body'])->toContain('less than the 30 days')
        ->and(ActivityLog::query()->where('description', 'like', 'Lara Quinto filed a resignation%')->exists())->toBeTrue();

    $this->post(route('offboarding.me.resign'), ['proposed_last_day' => resignDay(40)])->assertRedirect();
    assertToast('warning', 'already have a resignation waiting');
});

test('a last day in the past is refused', function () {
    [$user] = resignee();
    $this->actingAs($user);

    $this->post(route('offboarding.me.resign'), ['proposed_last_day' => resignDay(-1)])
        ->assertSessionHasErrors('proposed_last_day');
});

test('an employee withdraws a waiting resignation, but not somebody else\'s', function () {
    [$user, $employee] = resignee();
    $resignation = ResignationRequest::factory()->create(['employee_id' => $employee->id, 'user_id' => $user->id]);
    [, $other] = resignee();
    $theirs = ResignationRequest::factory()->create(['employee_id' => $other->id]);

    $this->actingAs($user);

    $this->patch(route('offboarding.me.withdraw', $theirs))->assertNotFound();
    $this->patch(route('offboarding.me.withdraw', $resignation))->assertRedirect();
    assertToast('success', 'Resignation withdrawn.');

    expect($resignation->refresh()->status)->toBe('withdrawn');

    $this->patch(route('offboarding.me.withdraw', $resignation))->assertRedirect();
    assertToast('warning', 'already withdrawn');
});

test('HR accepts a resignation: the exit starts with the agreed day, the survey goes out, and they are told', function () {
    [$user, $employee] = resignee(['first_name' => 'Nico', 'last_name' => 'Reyes']);
    $resignation = ResignationRequest::factory()->create(['employee_id' => $employee->id, 'user_id' => $user->id, 'reason' => 'Further studies.']);
    $hr = actingAsUserWith(['offboarding.view', 'offboarding.manage']);

    $this->patch(route('offboarding.resignations.decide', $resignation), [
        'decision' => 'accept',
        'last_working_day' => resignDay(21),
        'note' => 'Thank you for everything.',
    ])->assertRedirect();
    assertToast('success', 'Their clearance has started.');

    $case = OffboardingCase::query()->sole();

    expect($case->employee_id)->toBe($employee->id)
        ->and($case->type)->toBe('resignation')
        ->and($case->last_working_day->toDateString())->toBe(resignDay(21))
        ->and($case->reason)->toBe('Further studies.')
        ->and($case->clearanceItems()->count())->toBeGreaterThan(0)
        ->and($case->exitInterview)->not->toBeNull()
        ->and($resignation->refresh())->status->toBe('accepted')
        ->and($resignation->decided_by)->toBe($hr->id)
        ->and($resignation->offboarding_case_id)->toBe($case->id)
        ->and($user->notifications()->pluck('data')->pluck('title')->all())
        ->toContain('Your resignation was accepted', 'Tell us about your time here');

    $this->actingAs($user)->get(route('offboarding.me'))->assertInertia(fn ($page) => $page
        ->where('can_resign', false)
        ->where('exit.last_working_day', resignDay(21))
        ->where('exit.certificate.ready', false)
        ->where('interview.can_answer', true));
});

test('HR declines a resignation with a note the employee sees', function () {
    [$user, $employee] = resignee();
    $resignation = ResignationRequest::factory()->create(['employee_id' => $employee->id, 'user_id' => $user->id]);
    actingAsUserWith(['offboarding.view', 'offboarding.manage']);

    $this->patch(route('offboarding.resignations.decide', $resignation), ['decision' => 'decline'])
        ->assertSessionHasErrors('note');

    $this->patch(route('offboarding.resignations.decide', $resignation), ['decision' => 'decline', 'note' => 'Please talk to your manager first.'])->assertRedirect();

    expect($resignation->refresh()->status)->toBe('declined')
        ->and(OffboardingCase::count())->toBe(0)
        ->and($user->notifications()->first()->data['body'])->toBe('Please talk to your manager first.');

    $this->actingAs($user)->get(route('offboarding.me'))->assertInertia(fn ($page) => $page
        ->where('can_resign', true)
        ->where('resignations.0.decision_note', 'Please talk to your manager first.'));
});

test('accepting a resignation of somebody whose earlier exit was cancelled reopens it', function () {
    [$user, $employee] = resignee();
    $old = OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'cancelled', 'type' => 'termination']);
    $resignation = ResignationRequest::factory()->create(['employee_id' => $employee->id, 'user_id' => $user->id]);
    actingAsSuperAdmin();

    $this->patch(route('offboarding.resignations.decide', $resignation), ['decision' => 'accept', 'last_working_day' => resignDay(30)])->assertRedirect();
    assertToast('success');

    expect(OffboardingCase::count())->toBe(1)
        ->and($old->refresh()->status)->toBe('clearance')
        ->and($old->type)->toBe('resignation')
        ->and($resignation->refresh()->offboarding_case_id)->toBe($old->id);
});

test('starting an exit by hand settles a resignation that was waiting', function () {
    [$user, $employee] = resignee();
    $resignation = ResignationRequest::factory()->create(['employee_id' => $employee->id, 'user_id' => $user->id]);
    actingAsSuperAdmin();

    $this->post(route('offboarding.store'), ['employee_id' => $employee->id, 'type' => 'resignation'])->assertRedirect();

    expect($resignation->refresh()->status)->toBe('accepted')
        ->and($resignation->offboarding_case_id)->toBe($employee->offboardingCase()->value('id'));
});

test('nobody files while their exit is under way, or after they left', function () {
    [$user, $employee] = resignee();
    OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'clearance']);
    $this->actingAs($user);

    $this->post(route('offboarding.me.resign'), ['proposed_last_day' => resignDay(30)])->assertRedirect();
    assertToast('warning', 'already under way');

    [$gone] = resignee(['employment_status' => 'resigned']);
    $this->actingAs($gone);

    $this->post(route('offboarding.me.resign'), ['proposed_last_day' => resignDay(30)])->assertRedirect();
    assertToast('warning', 'already ended');
});

test('the resignations list shows the workspace\'s own, with counts, to those who may see exits', function () {
    [, $employee] = resignee();
    ResignationRequest::factory()->create(['employee_id' => $employee->id, 'proposed_last_day' => resignDay(10)]);

    $here = app(Tenancy::class)->organization();
    app(Tenancy::class)->forget();
    $elsewhere = Organization::factory()->create();
    app(Tenancy::class)->set($elsewhere);
    ResignationRequest::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    app(Tenancy::class)->set($here);

    actingAsUserWith(['offboarding.view']);
    app(Tenancy::class)->forget();

    $this->get(route('offboarding.resignations.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('offboarding/resignations')
        ->has('resignations', 1)
        ->where('stats.pending', 1)
        ->where('stats.short_notice', 1)
        ->where('can.manage', false));
});

test('deciding needs offboarding.manage', function () {
    [, $employee] = resignee();
    $resignation = ResignationRequest::factory()->create(['employee_id' => $employee->id]);
    actingAsUserWith(['offboarding.view']);

    $this->patch(route('offboarding.resignations.decide', $resignation), ['decision' => 'decline', 'note' => 'No.'])->assertForbidden();
});

test('accepting without a note keeps no note — the exit starting does not write its own', function () {
    [$user, $employee] = resignee();
    $resignation = ResignationRequest::factory()->create(['employee_id' => $employee->id, 'user_id' => $user->id]);
    $hr = actingAsUserWith(['offboarding.view', 'offboarding.manage']);

    $this->patch(route('offboarding.resignations.decide', $resignation), ['decision' => 'accept', 'last_working_day' => resignDay(30)])->assertRedirect();

    expect($resignation->refresh())
        ->status->toBe('accepted')
        ->decision_note->toBeNull()
        ->decided_by->toBe($hr->id)
        ->offboarding_case_id->toBe(OffboardingCase::query()->sole()->id);
});
