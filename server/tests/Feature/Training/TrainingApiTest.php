<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\Organization;
use App\Models\TrainingAttendance;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

/*
| ADR 0094 — training on the phone: My training, Browse, one program with the
| person's own place on it, joining and withdrawing through the web's own
| rules, a session for the phone's calendar, and one's own certificate. All of
| it is the person's own, or a program open to them.
*/

/** A participant signed in to the app, with an active employee record. */
function tapiParticipant(): array
{
    $user = actingAsUserWith(['training.participate']);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'employment_status' => 'active', 'middle_name' => null, 'suffix' => null]);
    Sanctum::actingAs($user);

    return [$user, $employee];
}

/** The organisation's today, as a date the test can move from (see the org-clock trap). */
function tapiToday(): CarbonImmutable
{
    return CarbonImmutable::parse(OrganizationClock::today());
}

/** A program starting ten days out, open to join unless told otherwise. */
function tapiProgram(array $attributes = []): TrainingProgram
{
    return TrainingProgram::create([
        'name' => 'Forklift Safety',
        'provider' => 'SafeWorks',
        'start_date' => tapiToday()->addDays(10)->toDateString(),
        'end_date' => tapiToday()->addDays(14)->toDateString(),
        'enrollment_mode' => 'open',
        ...$attributes,
    ]);
}

function tapiSession(TrainingProgram $program, string $date, array $attributes = []): TrainingSession
{
    return $program->sessions()->create([
        'session_date' => $date,
        'starts_at' => '09:00',
        'ends_at' => '12:00',
        'location' => 'Training Room 2',
        ...$attributes,
    ]);
}

function tapiCertificate(Employee $employee, TrainingProgram $program, string $issued, ?string $expires): EmployeeCertification
{
    $enrollment = $program->enrollments()->create(['employee_id' => $employee->id, 'status' => 'completed', 'completed_at' => $issued, 'score' => 92]);

    return EmployeeCertification::create([
        'employee_id' => $employee->id,
        'training_enrollment_id' => $enrollment->id,
        'name' => $program->name,
        'issuer' => $program->provider,
        'certificate_no' => 'TC-2026-'.str_pad((string) $enrollment->id, 5, '0', STR_PAD_LEFT),
        'issued_date' => $issued,
        'expiry_date' => $expires,
    ]);
}

beforeEach(function () {
    Notification::fake();
    [$this->user, $this->employee] = tapiParticipant();
});

test('the app is told whether the person takes part in training', function () {
    $this->getJson('/api/me')->assertOk()->assertJsonPath('user.can_train', true);

    Sanctum::actingAs(actingAsUserWith(['leave.request']));
    $this->getJson('/api/me')->assertOk()->assertJsonPath('user.can_train', false);
    $this->getJson('/api/training')->assertForbidden();
    $this->getJson('/api/training/catalog')->assertForbidden();
});

test('My training lists my programs, my sessions coming up and my certificates, never anyone else’s', function () {
    $today = tapiToday();
    $mine = tapiProgram();
    $mine->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'enrolled']);
    tapiSession($mine, $today->addDays(11)->toDateString(), ['title' => 'Day one']);
    tapiSession($mine, $today->addDays(12)->toDateString());

    $asked = tapiProgram(['name' => 'Excel Basics', 'enrollment_mode' => 'approval']);
    $asked->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'requested']);
    tapiSession($asked, $today->addDays(11)->toDateString());

    $done = tapiProgram(['name' => 'First Aid', 'start_date' => $today->subMonths(3)->toDateString(), 'end_date' => $today->subMonths(3)->addDay()->toDateString(), 'issues_certificate' => true]);
    tapiCertificate($this->employee, $done, $today->subMonths(3)->toDateString(), $today->addDays(20)->toDateString());

    $theirs = tapiProgram(['name' => 'Not mine']);
    $theirs->enrollments()->create(['employee_id' => Employee::factory()->create()->id, 'status' => 'enrolled']);
    tapiSession($theirs, $today->addDays(11)->toDateString());

    $data = $this->getJson('/api/training')->assertOk()->json('data');

    expect(collect($data['enrollments'])->pluck('program.name')->all())->toBe(['First Aid', 'Excel Basics', 'Forklift Safety'])
        ->and(collect($data['enrollments'])->firstWhere('program.name', 'Excel Basics')['status'])->toBe('requested')
        // Only the sessions of a program I hold a seat on.
        ->and($data['sessions'])->toHaveCount(2)
        ->and($data['sessions'][0])->toMatchArray(['title' => 'Day one', 'starts_at' => '09:00', 'ends_at' => '12:00', 'location' => 'Training Room 2'])
        ->and($data['sessions'][0]['program'])->toBe(['hashid' => $mine->hashid, 'name' => 'Forklift Safety', 'provider' => 'SafeWorks'])
        ->and($data['certificates'])->toHaveCount(1)
        ->and($data['certificates'][0])->toMatchArray(['name' => 'First Aid', 'standing' => 'expiring', 'score' => 92.0])
        ->and($data['expiring_days'])->toBe(60);
});

test('Browse lists the programs open to me, as a participant sees them, with where I stand', function () {
    tapiProgram(['name' => 'Open', 'capacity' => 2, 'budget' => 50000]);
    $approval = tapiProgram(['name' => 'Approval', 'enrollment_mode' => 'approval', 'start_date' => tapiToday()->addDays(20)->toDateString(), 'end_date' => tapiToday()->addDays(22)->toDateString()]);
    $approval->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'requested']);
    tapiProgram(['name' => 'HR only', 'enrollment_mode' => 'closed']);
    tapiProgram(['name' => 'Over', 'start_date' => tapiToday()->subDays(30)->toDateString(), 'end_date' => tapiToday()->subDays(28)->toDateString()]);

    $programs = $this->getJson('/api/training/catalog')->assertOk()->json('data');

    // Soonest first: the order someone choosing reads them in.
    expect(collect($programs)->pluck('name')->all())->toBe(['Open', 'Approval'])
        ->and($programs[1]['mine']['status'])->toBe('requested')
        ->and($programs[0])->toMatchArray(['capacity' => 2, 'seats_remaining' => 2, 'is_full' => false, 'enrollment_mode' => 'open', 'mine' => null])
        // Money and HR's notes stay with HR.
        ->and($programs[0])->not->toHaveKeys(['budget', 'ai_insights', 'pending_count']);
});

test('the web’s Browse no longer sends a program’s budget to the people joining it', function () {
    tapiProgram(['name' => 'Open', 'budget' => 50000]);

    $this->get(route('training.catalog'))->assertInertia(fn (Assert $page) => $page
        ->component('training/catalog')
        ->has('programs', 1)
        ->where('programs.0.name', 'Open')
        ->missing('programs.0.budget')
        ->missing('programs.0.ai_insights'));
});

test('a program shows every session with my own mark, my place on it, and whether I can join', function () {
    $today = tapiToday();
    $program = tapiProgram(['start_date' => $today->subDays(5)->toDateString(), 'end_date' => $today->addDays(5)->toDateString(), 'capacity' => 10, 'issues_certificate' => true, 'certificate_validity_months' => 24]);
    $held = tapiSession($program, $today->subDays(2)->toDateString());
    tapiSession($program, $today->addDays(2)->toDateString(), ['starts_at' => '13:00', 'ends_at' => '16:30']);
    $enrollment = $program->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'enrolled']);
    TrainingAttendance::create(['training_session_id' => $held->id, 'training_enrollment_id' => $enrollment->id, 'status' => 'late']);

    $data = $this->getJson("/api/training/programs/{$program->hashid}")->assertOk()->json('data');

    expect($data)->toMatchArray(['name' => 'Forklift Safety', 'status' => 'ongoing', 'seats_remaining' => 9, 'certificate_validity_months' => 24, 'join' => null])
        ->and($data['sessions'])->toHaveCount(2)
        ->and($data['sessions'][0])->toMatchArray(['is_held' => true, 'mark' => 'late'])
        ->and($data['sessions'][1])->toMatchArray(['is_held' => false, 'mark' => null, 'starts_at' => '13:00', 'ends_at' => '16:30'])
        ->and($data['mine'])->toMatchArray(['status' => 'enrolled', 'can_withdraw' => false])
        ->and($data['mine']['attendance'])->toBe(['attended' => 1, 'counted' => 1, 'rate' => 100]);

    expect($this->getJson('/api/training/programs/'.tapiProgram(['name' => 'Ask', 'enrollment_mode' => 'approval'])->hashid)->json('data.join'))->toBe('ask')
        ->and($this->getJson('/api/training/programs/'.tapiProgram(['name' => 'Full', 'capacity' => 0])->hashid)->json('data.join'))->toBe('full')
        ->and($this->getJson('/api/training/programs/'.tapiProgram(['name' => 'Seats'])->hashid)->json('data.join'))->toBe('join');
});

test('a program HR fills alone is not found, unless I am on it', function () {
    $closed = tapiProgram(['enrollment_mode' => 'closed']);

    $this->getJson("/api/training/programs/{$closed->hashid}")->assertNotFound();
    $this->postJson("/api/training/programs/{$closed->hashid}/join")->assertNotFound();

    $closed->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'enrolled']);

    $this->getJson("/api/training/programs/{$closed->hashid}")->assertOk()->assertJsonPath('data.mine.status', 'enrolled')->assertJsonPath('data.join', null);
});

test('joining from the app takes a seat on an open program, or asks on one that needs approval', function () {
    $open = tapiProgram(['capacity' => 1]);

    $this->postJson("/api/training/programs/{$open->hashid}/join")
        ->assertCreated()
        ->assertJsonPath('message', 'You’re in. Forklift Safety is on My training.')
        ->assertJsonPath('data.mine.status', 'enrolled')
        ->assertJsonPath('data.seats_remaining', 0);

    expect(ActivityLog::query()->where('log_name', 'training')->latest('id')->value('description'))->toContain('joined Forklift Safety via the app');

    $hr = User::factory()->create(['is_active' => true]);
    $hr->roles()->attach(makeRole('tapi-hr-'.Str::random(6), ['training.view', 'training.manage']));
    $approval = tapiProgram(['name' => 'Excel Basics', 'enrollment_mode' => 'approval']);

    $this->postJson("/api/training/programs/{$approval->hashid}/join")
        ->assertCreated()
        ->assertJsonPath('message', 'Request sent. You’ll be told when HR decides.')
        ->assertJsonPath('data.mine.status', 'requested')
        ->assertJsonPath('data.mine.can_withdraw', true);

    Notification::assertSentTo($hr, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Request to join Excel Basics');
});

test('a join the rules refuse is told in their own words', function () {
    $full = tapiProgram(['capacity' => 0]);

    $this->postJson("/api/training/programs/{$full->hashid}/join")->assertStatus(422)->assertJsonPath('message', 'This program is full.');

    $open = tapiProgram(['name' => 'Twice']);
    $this->postJson("/api/training/programs/{$open->hashid}/join")->assertCreated();
    $this->postJson("/api/training/programs/{$open->hashid}/join")->assertStatus(422)->assertJsonPath('message', 'You are already on this program.');

    expect(TrainingEnrollment::query()->where('employee_id', $this->employee->id)->count())->toBe(1);
});

test('I can withdraw my request or leave before it starts, but not someone else’s, nor once it has started', function () {
    $approval = tapiProgram(['enrollment_mode' => 'approval']);
    $request = $approval->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'requested']);

    $this->deleteJson("/api/training/enrollments/{$request->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Request withdrawn.')
        ->assertJsonPath('data.mine', null)
        ->assertJsonPath('data.join', 'ask');

    $closed = tapiProgram(['name' => 'HR placed me', 'enrollment_mode' => 'closed']);
    $seat = $closed->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'enrolled']);

    // Off a program I could only see by being on it: nothing more to show.
    $this->deleteJson("/api/training/enrollments/{$seat->id}")->assertOk()->assertJsonPath('message', 'You’ve left the program.')->assertJsonPath('data', null);

    $started = tapiProgram(['name' => 'Started', 'start_date' => tapiToday()->subDays(2)->toDateString(), 'end_date' => tapiToday()->addDays(2)->toDateString()]);
    $on = $started->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'enrolled']);
    $this->deleteJson("/api/training/enrollments/{$on->id}")->assertStatus(422)->assertJsonPath('message', 'The program has started — ask HR to take you off the roster.');

    $theirs = tapiProgram(['name' => 'Theirs'])->enrollments()->create(['employee_id' => Employee::factory()->create()->id, 'status' => 'enrolled']);
    $this->deleteJson("/api/training/enrollments/{$theirs->id}")->assertNotFound();
    expect(TrainingEnrollment::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

test('a session goes to the phone’s calendar through a short-lived link, while I hold a seat', function () {
    $organization = testOrganization();
    $program = tapiProgram();
    $session = tapiSession($program, tapiToday()->addDays(11)->toDateString(), ['title' => 'Day one']);
    $seat = $program->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'enrolled']);

    $url = $this->postJson("/api/training/sessions/{$session->id}/calendar-link")->assertOk()->json('data.url');

    // The phone's browser carries no token and no workspace.
    app(Tenancy::class)->forget();
    $calendar = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    expect($calendar->getContent())->toContain('BEGIN:VCALENDAR')->toContain('Forklift Safety');

    $this->get($url.'x')->assertForbidden();

    app(Tenancy::class)->set($organization);
    $seat->delete();
    app(Tenancy::class)->forget();
    $this->get($url)->assertNotFound();

    app(Tenancy::class)->set($organization);
    $other = tapiSession(tapiProgram(['name' => 'Not mine']), tapiToday()->addDays(11)->toDateString());
    $this->postJson("/api/training/sessions/{$other->id}/calendar-link")->assertNotFound();
});

test('my certificate reads as the printed one does; anyone else’s, or a typed-in licence, is not found', function () {
    $today = tapiToday();
    $program = tapiProgram(['name' => 'First Aid', 'issues_certificate' => true, 'start_date' => $today->subYears(3)->toDateString(), 'end_date' => $today->subYears(3)->addDay()->toDateString()]);
    $old = tapiCertificate($this->employee, $program, $today->subYears(3)->toDateString(), $today->subYear()->toDateString());
    $renewal = tapiProgram(['name' => 'First Aid', 'issues_certificate' => true, 'start_date' => $today->subMonth()->toDateString(), 'end_date' => $today->subMonth()->addDay()->toDateString()]);
    $new = tapiCertificate($this->employee, $renewal, $today->subMonth()->toDateString(), $today->addYears(2)->toDateString());

    $this->getJson("/api/training/certificates/{$new->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'First Aid')
        ->assertJsonPath('data.issuer', 'SafeWorks')
        ->assertJsonPath('data.standing', 'valid')
        ->assertJsonPath('data.employee.full_name', $this->employee->full_name)
        ->assertJsonPath('data.completed_on', $today->subMonth()->toDateString())
        ->assertJsonPath('data.organization', testOrganization()->name);

    // Lapsed, but earned again since: it says renewed, as the register does.
    $this->getJson("/api/training/certificates/{$old->id}")->assertOk()->assertJsonPath('data.standing', 'renewed');

    $licence = EmployeeCertification::create(['employee_id' => $this->employee->id, 'name' => 'PRC License']);
    $theirs = tapiCertificate(Employee::factory()->create(), tapiProgram(['name' => 'Theirs']), $today->toDateString(), null);

    $this->getJson("/api/training/certificates/{$licence->id}")->assertNotFound();
    $this->getJson("/api/training/certificates/{$theirs->id}")->assertNotFound();
});

test('another company’s program is not found from the app', function () {
    $elsewhere = Organization::factory()->create();
    $program = app(Tenancy::class)->runFor($elsewhere, fn () => tapiProgram(['name' => 'Elsewhere']));

    $this->getJson("/api/training/programs/{$program->hashid}")->assertNotFound();
    $this->postJson("/api/training/programs/{$program->hashid}/join")->assertNotFound();
});

test('search finds my own training and certificates by name, and lists them for “training”', function () {
    $program = tapiProgram(['name' => 'Forklift Safety']);
    $program->enrollments()->create(['employee_id' => $this->employee->id, 'status' => 'requested']);
    tapiCertificate($this->employee, tapiProgram(['name' => 'First Aid', 'issues_certificate' => true]), tapiToday()->toDateString(), null);
    tapiProgram(['name' => 'Forklift Advanced'])->enrollments()->create(['employee_id' => Employee::factory()->create()->id, 'status' => 'enrolled']);

    $group = collect($this->getJson('/api/search?q=forklift')->assertOk()->json('groups'))->firstWhere('key', 'training');

    expect($group['items'])->toHaveCount(1)
        ->and($group['items'][0])->toMatchArray(['title' => 'Forklift Safety', 'hint' => 'Asked to join', 'target' => ['screen' => 'training', 'id' => $program->hashid]]);

    $all = collect($this->getJson('/api/search?q=training')->json('groups'))->firstWhere('key', 'training');
    expect(collect($all['items'])->pluck('title')->all())->toContain('Forklift Safety', 'First Aid');

    $certificates = collect($this->getJson('/api/search?q=certificates')->json('groups'))->firstWhere('key', 'training');
    expect($certificates['items'])->toHaveCount(1)
        ->and($certificates['items'][0])->toMatchArray(['title' => 'First Aid', 'hint' => 'Valid'])
        ->and($certificates['items'][0]['target']['screen'])->toBe('certificate');
});
