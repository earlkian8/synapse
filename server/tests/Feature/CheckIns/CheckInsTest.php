<?php

use App\Models\ActivityLog;
use App\Models\CheckIn;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\CheckIns\CheckInException;
use App\Support\CheckIns\CheckInPeriod;
use App\Support\CheckIns\CheckInReminders;
use App\Support\CheckIns\CheckInResults;
use App\Support\CheckIns\CheckInWorkflow;
use App\Support\DataExport\DataExportCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Mood check-ins (ADR 0102): once a month each employee answers six questions;
| answers are confidential, and HR sees only groups of five or more, with hidden
| groups pooled so none can be worked out by subtraction.
*/

/** A full set of answers, each overridable. */
function checkInAnswers(array $overrides = []): array
{
    return [
        'mood' => 4, 'satisfaction' => 4, 'energy' => 5, 'enthusiasm' => 4, 'immersion' => 3, 'leave_thoughts' => 2,
        ...$overrides,
    ];
}

/** `$count` people of `$department` who answered this month with `$mood`. */
function checkInTeam(?Department $department, int $count, int $mood = 3, int $leaveThoughts = 2, ?string $period = null): void
{
    testOrganization();

    foreach (range(1, $count) as $_) {
        $employee = Employee::factory()->create(['department_id' => $department?->id, 'employment_status' => 'active']);
        CheckIn::create([
            'employee_id' => $employee->id, 'department_id' => $department?->id,
            'period' => $period ?? CheckInPeriod::current()->toDateString(),
            ...checkInAnswers(['mood' => $mood, 'leave_thoughts' => $leaveThoughts]),
            'submitted_at' => now(),
        ]);
    }
}

/** A signed-in employee who may answer their own check-in. */
function checkInStaff(): array
{
    $user = actingAsUserWith(['check-ins.respond']);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'employment_status' => 'active']);

    return [$user, $employee];
}

// ── Answering ───────────────────────────────────────────────────────────────

test('an employee answers this month, and answering again changes it rather than adding a second', function () {
    [, $employee] = checkInStaff();

    $this->post(route('check-ins.store'), checkInAnswers(['note' => 'A heavy month, but a good team.']))->assertRedirect();
    assertToast('success', 'Check-in saved');
    $this->post(route('check-ins.store'), checkInAnswers(['mood' => 2]))->assertRedirect();

    $checkIn = CheckIn::sole();
    expect($checkIn->employee_id)->toBe($employee->id)
        ->and($checkIn->mood)->toBe(2)
        ->and($checkIn->note)->toBeNull()
        ->and($checkIn->period->toDateString())->toBe(CheckInPeriod::current()->toDateString())
        ->and($checkIn->engagement())->toBe(4.0);
});

test('every question must be answered, each on its own scale', function () {
    checkInStaff();

    $this->post(route('check-ins.store'), checkInAnswers(['energy' => 7, 'mood' => 0]))
        ->assertSessionHasErrors(['energy', 'mood']);
    $this->post(route('check-ins.store'), array_diff_key(checkInAnswers(), ['leave_thoughts' => 1]))
        ->assertSessionHasErrors(['leave_thoughts']);
    $this->post(route('check-ins.store'), checkInAnswers(['note' => str_repeat('x', 501)]))
        ->assertSessionHasErrors(['note']);

    expect(CheckIn::count())->toBe(0);
});

test('the private note is encrypted at rest and never serialised', function () {
    [, $employee] = checkInStaff();
    $this->post(route('check-ins.store'), checkInAnswers(['note' => 'Only for me']));

    $raw = DB::table('check_ins')->value('note');
    expect($raw)->not->toBe('Only for me')
        ->and(CheckIn::sole()->note)->toBe('Only for me')
        ->and(CheckIn::sole()->toArray())->not->toHaveKey('note');
});

test('a workspace that turned check-ins off, or a person no longer employed, cannot answer', function () {
    [, $employee] = checkInStaff();
    testOrganization()->update(['check_ins_enabled' => false]);

    $this->post(route('check-ins.store'), checkInAnswers())->assertRedirect();
    assertToast('warning', 'turned off');

    testOrganization()->update(['check_ins_enabled' => true]);
    $employee->update(['employment_status' => 'resigned']);
    expect(fn () => app(CheckInWorkflow::class)->submit($employee->refresh(), checkInAnswers()))
        ->toThrow(CheckInException::class, 'currently employed');
    expect(CheckIn::count())->toBe(0);
});

test('answering is not activity-logged: who answered is as confidential as what', function () {
    checkInStaff();
    $this->post(route('check-ins.store'), checkInAnswers());

    expect(ActivityLog::query()->where('log_name', 'check-ins')->count())->toBe(0);
});

test('my check-in shows only my own months', function () {
    [, $employee] = checkInStaff();
    checkInTeam(null, 3, mood: 1);
    $this->post(route('check-ins.store'), checkInAnswers(['mood' => 5]));

    $this->get(route('check-ins.me'))->assertInertia(fn (Assert $page) => $page
        ->component('check-ins/me')
        ->where('overview.enabled', true)
        ->has('overview.history', 1)
        ->where('overview.history.0.answers.mood', 5)
        ->where('overview.current.answers.mood', 5)
        ->has('overview.questions', 6)
        ->has('overview.months', 12));
});

// ── Who may see what ────────────────────────────────────────────────────────

test('the landing page sends HR to the results and everyone else to their own check-in', function () {
    checkInStaff();
    $this->get(route('check-ins.index'))->assertRedirect(route('check-ins.me'));

    actingAsUserWith(['check-ins.view', 'check-ins.respond']);
    $this->get(route('check-ins.index'))->assertRedirect(route('check-ins.results'));

    actingAsUserWith(['employees.view']);
    $this->get(route('check-ins.index'))->assertForbidden();
});

test('staff cannot read the results, and only check-ins.manage turns check-ins off', function () {
    checkInStaff();
    $this->get(route('check-ins.results'))->assertForbidden();
    $this->post(route('check-ins.settings'), ['check_ins_enabled' => false])->assertForbidden();

    actingAsUserWith(['check-ins.view']);
    $this->post(route('check-ins.settings'), ['check_ins_enabled' => false])->assertForbidden();

    actingAsUserWith(['check-ins.view', 'check-ins.manage']);
    $this->post(route('check-ins.settings'), ['check_ins_enabled' => false])->assertRedirect();
    expect(testOrganization()->refresh()->check_ins_enabled)->toBeFalse()
        ->and(ActivityLog::query()->where('log_name', 'check-ins')->sole()->description)->toBe('Turned monthly check-ins off');
});

test('the results page carries counts and means, never a person or an answer', function () {
    actingAsUserWith(['check-ins.view']);
    $ops = Department::factory()->create(['name' => 'Operations']);
    checkInTeam($ops, 6, mood: 2, leaveThoughts: 5);

    $response = $this->get(route('check-ins.results'))->assertInertia(fn (Assert $page) => $page
        ->component('check-ins/results')
        ->where('results.company.answers', 6)
        ->where('results.company.mood', 2)
        ->where('results.company.leave_share', 1)
        ->where('results.departments.0.name', 'Operations')
        ->where('results.departments.0.moods', [0, 6, 0, 0, 0])
        ->has('trend', 12));

    // The screen's own data (the shared props carry the viewer's session).
    $props = $response->viewData('page')['props'];
    $page = json_encode([$props['results'], $props['trend']]);
    foreach (Employee::all() as $employee) {
        expect($page)->not->toContain($employee->last_name);
    }
    expect($page)->not->toContain('employee_id')->not->toContain('note');
});

// ── The five-answer bar ─────────────────────────────────────────────────────

test('a group under five answers is not shown, even for the whole company', function () {
    checkInTeam(null, 4);
    $results = app(CheckInResults::class)->period(CheckInPeriod::current());

    expect($results['company'])->toBe(['people' => 4, 'answers' => 4, 'shown' => false])
        ->and($results['departments'])->toBe([]);
});

test('small teams are pooled, and the pool never lets a hidden team be worked out', function () {
    testOrganization();
    $sales = Department::factory()->create(['name' => 'Sales']);
    $ops = Department::factory()->create(['name' => 'Operations']);
    $hr = Department::factory()->create(['name' => 'HR']);
    $it = Department::factory()->create(['name' => 'IT']);

    // Sales 9 and Operations 6 clear the bar; HR has 2: alone in the pool it would be
    // company − Sales − Operations, so the next-smallest (Operations) joins it.
    checkInTeam($sales, 9, mood: 4);
    checkInTeam($ops, 6, mood: 2);
    checkInTeam($hr, 2, mood: 1);
    $results = app(CheckInResults::class)->period(CheckInPeriod::current());

    expect(collect($results['departments'])->pluck('name')->all())->toBe(['Sales'])
        ->and($results['others']['answers'])->toBe(8)
        ->and($results['others']['shown'])->toBeTrue()
        ->and($results['others']['teams'])->toBe(2)
        ->and($results['company']['answers'])->toBe(17);

    // Two small teams that together clear the bar are shown together, no one else hidden.
    checkInTeam($it, 3, mood: 3);
    $results = app(CheckInResults::class)->period(CheckInPeriod::current());

    expect(collect($results['departments'])->pluck('name')->sort()->values()->all())->toBe(['Operations', 'Sales'])
        ->and($results['others']['answers'])->toBe(5)
        ->and($results['others']['teams'])->toBe(2);
});

test('the trend shows each month the company cleared the bar', function () {
    checkInTeam(null, 5, mood: 5, period: CheckInPeriod::current()->subMonthNoOverflow()->toDateString());
    checkInTeam(null, 2, mood: 1);

    $trend = app(CheckInResults::class)->trend(CheckInPeriod::current());

    expect($trend)->toHaveCount(12)
        ->and($trend[10]['shown'])->toBeTrue()
        ->and($trend[10]['mood'])->toBe(5.0)
        ->and($trend[11])->toMatchArray(['answers' => 2, 'shown' => false]);
});

test('team climate is a department\'s latest shown result, and a team below the bar has none', function () {
    testOrganization();
    $sales = Department::factory()->create();
    $tiny = Department::factory()->create();
    $last = CheckInPeriod::current()->subMonthNoOverflow()->toDateString();
    checkInTeam($sales, 5, mood: 2, leaveThoughts: 4, period: $last);
    checkInTeam($sales, 6, mood: 4);
    checkInTeam($tiny, 3, mood: 1, period: $last);

    $climate = app(CheckInResults::class)->teamClimate();

    expect($climate[$sales->id])->toMatchArray(['period' => CheckInPeriod::current()->toDateString(), 'mood' => 4.0, 'answers' => 6])
        ->and($climate)->not->toHaveKey($tiny->id);
});

// ── Reminders ───────────────────────────────────────────────────────────────

test('on the 1st everyone who may answer hears the month is open; on the 20th, only who has not answered', function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00', 'Asia/Manila'));
    testOrganization();
    $answering = User::factory()->create(['is_active' => true]);
    $answering->roles()->attach(makeRole('checker-'.uniqid(), ['check-ins.respond']));
    $answer = Employee::factory()->create(['user_id' => $answering->id, 'employment_status' => 'active']);
    $waiting = User::factory()->create(['is_active' => true]);
    $waiting->roles()->attach(makeRole('checker-'.uniqid(), ['check-ins.respond']));
    Employee::factory()->create(['user_id' => $waiting->id, 'employment_status' => 'active']);
    $cannot = User::factory()->create(['is_active' => true]);
    Employee::factory()->create(['user_id' => $cannot->id, 'employment_status' => 'active']);

    expect(app(CheckInReminders::class)->sendDue())->toBe(2);
    Notification::assertSentTo($waiting, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Your December check-in is open' && $n->url === '/check-ins/me');
    Notification::assertNotSentTo($cannot, SystemNotification::class);

    app(CheckInWorkflow::class)->submit($answer, checkInAnswers());
    $this->travelTo(CarbonImmutable::parse('2026-12-20 09:00', 'Asia/Manila'));
    Notification::fake();
    expect(app(CheckInReminders::class)->sendDue())->toBe(1);
    Notification::assertSentTo($waiting, SystemNotification::class, fn (SystemNotification $n): bool => str_contains($n->title, 'A minute for your December check-in'));
    Notification::assertNotSentTo($answering, SystemNotification::class);

    $this->travelTo(CarbonImmutable::parse('2026-12-21 09:00', 'Asia/Manila'));
    expect(app(CheckInReminders::class)->sendDue())->toBe(0);
});

test('a check-in counts for the organisation\'s month, not the server\'s', function () {
    // 23:30 on 30 November in Manila: the organisation's month is still November.
    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:30', 'Asia/Manila'));
    [, $employee] = checkInStaff();
    app(CheckInWorkflow::class)->submit($employee, checkInAnswers());

    expect(CheckIn::sole()->period->toDateString())->toBe('2026-11-01');

    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30', 'Asia/Manila'));
    expect(app(CheckInWorkflow::class)->due($employee))->toBeTrue();
});

test('the data export never carries check-ins', function () {
    expect(DataExportCatalogue::EXCLUDED)->toHaveKey('check_ins');
});
