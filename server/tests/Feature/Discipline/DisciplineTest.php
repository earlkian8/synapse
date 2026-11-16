<?php

use App\Models\ActivityLog;
use App\Models\ConductOffense;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Support\Discipline\ConductCode;
use App\Support\Discipline\ConductRecord;
use App\Support\Discipline\DisciplineWorkflow;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| ADR 0105 — discipline: an incident is taken through the twin-notice rule (Labor
| Code Art. 292(b), D.O. 147-15) — a notice to explain with at least five calendar
| days to answer, the answer, a hearing when asked for, and a written decision —
| charged under a code of conduct whose penalties climb with each repeat.
|
| "Today" is Monday 2026-11-16 in Manila.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Manila'));
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();
});

function dcOffense(array $attributes = []): ConductOffense
{
    return ConductOffense::create([
        'code' => 'ATT-02',
        'title' => 'Absence without leave',
        'gravity' => 'less_serious',
        'ground' => 'gross_neglect',
        'penalties' => [['action' => 'written_warning'], ['action' => 'suspension', 'days' => 3], ['action' => 'dismissal']],
        'reckoning_months' => 12,
        ...$attributes,
    ]);
}

/** An employee with an app account, and that account. */
function dcEmployee(): array
{
    $user = User::factory()->create();
    $user->roles()->attach(makeRole('dc-staff', ['discipline.respond']));
    $employee = Employee::factory()->create(['user_id' => $user->id, 'middle_name' => null, 'suffix' => null]);

    return [$employee, $user];
}

function dcHr(): User
{
    return actingAsUserWith(['discipline.view', 'discipline.manage', 'discipline.report']);
}

function dcReport(Employee $employee, ?ConductOffense $offense = null, ?User $by = null, string $date = '2026-11-12'): DisciplinaryCase
{
    return app(DisciplineWorkflow::class)->report([
        'employee_id' => $employee->id,
        'conduct_offense_id' => $offense?->id,
        'incident_date' => $date,
        'location' => 'Warehouse',
        'narrative' => 'Did not report for the 08:00 shift and gave no notice to the supervisor.',
        'witnesses' => 'Ramon Santos',
    ], $by ?? User::factory()->create());
}

/** A case served and answered, ready to decide. */
function dcReady(Employee $employee, ConductOffense $offense, User $hr, string $date = '2026-11-12'): DisciplinaryCase
{
    $workflow = app(DisciplineWorkflow::class);
    $case = dcReport($employee, $offense, $hr, $date);
    $workflow->issueNotice($case, 'Violation of ATT-02: absent on the date without leave.', CarbonImmutable::parse(OrganizationClock::today())->addDays(5), null, $hr);
    $workflow->explain($case->refresh(), 'I was sick and could not reach anyone.', false, $employee->user);

    return $case->refresh();
}

// ── The report ───────────────────────────────────────────────────────────────

test('a supervisor reports an incident; HR is told and the employee hears nothing yet', function () {
    [$employee, $staff] = dcEmployee();
    $hr = User::factory()->create();
    $hr->roles()->attach(makeRole('dc-hr', ['discipline.view', 'discipline.manage']));
    actingAsUserWith(['discipline.report']);

    $this->post('/discipline/cases', [
        'employee_id' => $employee->id,
        'incident_date' => '2026-11-12',
        'narrative' => 'Shouted at a customer at the counter and refused to serve them.',
    ])->assertRedirect('/discipline/reported');

    $case = DisciplinaryCase::sole();

    expect($case->reference)->toBe('DC-2026-00001')
        ->and($case->status)->toBe('reported')
        ->and($hr->notifications()->count())->toBe(1)
        ->and($staff->notifications()->count())->toBe(0);

    $this->get('/discipline/reported')->assertInertia(fn ($page) => $page
        ->has('reports', 1)
        ->where('reports.0.status', 'reported')
        ->missing('reports.0.narrative'));

    $this->actingAs($staff);
    $this->get('/discipline/me')->assertInertia(fn ($page) => $page->has('cases', 0));
    $this->get("/discipline/me/{$case->hashid}")->assertNotFound();
});

test('an incident needs the facts, in enough detail to charge on', function () {
    dcHr();
    [$employee] = dcEmployee();

    $this->post('/discipline/cases', ['employee_id' => $employee->id, 'incident_date' => '2026-11-20', 'narrative' => 'Late.'])
        ->assertSessionHasErrors(['narrative', 'incident_date']);
});

test('references run on within the year', function () {
    [$employee] = dcEmployee();

    dcReport($employee);
    $second = dcReport($employee);

    expect($second->reference)->toBe('DC-2026-00002');
});

// ── The notice to explain ────────────────────────────────────────────────────

test('a notice gives at least five calendar days to answer, and tells the employee', function () {
    $hr = dcHr();
    [$employee, $staff] = dcEmployee();
    $case = dcReport($employee, dcOffense());

    $this->post("/discipline/cases/{$case->hashid}/notice", [
        'notice_grounds' => 'Violation of ATT-02: absent on November 12 without leave or notice.',
        'reply_due_on' => '2026-11-19',
    ]);
    assertToast('warning', 'at least 5 calendar days');
    expect($case->refresh()->status)->toBe('reported');

    $this->post("/discipline/cases/{$case->hashid}/notice", [
        'notice_grounds' => 'Violation of ATT-02: absent on November 12 without leave or notice.',
        'reply_due_on' => '2026-11-21',
    ]);
    assertToast('success');

    expect($case->refresh()->status)->toBe('notice_issued')
        ->and($case->notice_issued_by)->toBe($hr->id)
        ->and($case->reply_due_on->toDateString())->toBe('2026-11-21')
        ->and($staff->notifications()->count())->toBe(1);
});

test('a general description is not a charge', function () {
    dcHr();
    [$employee] = dcEmployee();
    $case = dcReport($employee);

    $this->post("/discipline/cases/{$case->hashid}/notice", ['notice_grounds' => 'Misconduct.', 'reply_due_on' => '2026-11-21'])
        ->assertSessionHasErrors('notice_grounds');
});

test('the employee reads the charge, acknowledges it and answers; they never see the witnesses or the evidence', function () {
    Storage::fake('uploads');
    $hr = dcHr();
    [$employee, $staff] = dcEmployee();
    $case = dcReport($employee, dcOffense(), $hr);
    $this->post("/discipline/cases/{$case->hashid}/attachments", ['file' => UploadedFile::fake()->image('cctv.jpg')]);
    app(DisciplineWorkflow::class)->issueNotice($case, 'Violation of ATT-02: absent on November 12 without leave.', CarbonImmutable::parse('2026-11-21'), null, $hr);

    $this->actingAs($staff);

    $this->get("/discipline/me/{$case->hashid}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('discipline/my-case')
        ->where('case.notice_grounds', 'Violation of ATT-02: absent on November 12 without leave.')
        ->missing('case.witnesses')
        ->missing('case.attachments'));

    $this->patch("/discipline/me/{$case->hashid}/acknowledge")->assertRedirect();
    $this->post("/discipline/me/{$case->hashid}/explanation", ['explanation' => 'I had a fever and my phone was dead.', 'hearing' => true])->assertRedirect();

    $case->refresh();

    expect($case->notice_received_at)->not->toBeNull()
        ->and($case->status)->toBe('explained')
        ->and($case->hearing_requested)->toBeTrue()
        ->and($case->explanation_recorded_by)->toBeNull()
        ->and($hr->notifications()->count())->toBe(1);

    // The evidence is HR's.
    $file = $case->attachments()->sole();
    $this->get('/files/'.$file->path)->assertNotFound();
    $this->actingAs($hr)->get('/files/'.$file->path)->assertOk();
});

test('nobody reads a notice served on somebody else', function () {
    $hr = dcHr();
    [$employee] = dcEmployee();
    [, $other] = dcEmployee();
    $case = dcReport($employee, null, $hr);
    app(DisciplineWorkflow::class)->issueNotice($case, 'Violation of ATT-02: absent on November 12 without leave.', CarbonImmutable::parse('2026-11-21'), null, $hr);

    $this->actingAs($other);

    $this->get("/discipline/me/{$case->hashid}")->assertNotFound();
    $this->post("/discipline/me/{$case->hashid}/explanation", ['explanation' => 'Not mine to answer.'])->assertNotFound();
    $this->get("/discipline/me/{$case->hashid}/notice.pdf")->assertNotFound();
    $this->get("/discipline/cases/{$case->hashid}")->assertForbidden();
});

// ── Deciding ─────────────────────────────────────────────────────────────────

test('no decision before a notice, nor before they could answer', function () {
    $hr = dcHr();
    [$employee] = dcEmployee();
    $case = dcReport($employee, dcOffense(), $hr);
    $decision = ['finding' => 'upheld', 'penalty' => 'written_warning', 'decision' => 'All the circumstances were considered and the absence is not excused.'];

    $this->post("/discipline/cases/{$case->hashid}/decision", $decision);
    assertToast('warning', 'notice to explain first');

    app(DisciplineWorkflow::class)->issueNotice($case, 'Violation of ATT-02: absent on November 12 without leave.', CarbonImmutable::parse('2026-11-21'), null, $hr);

    $this->post("/discipline/cases/{$case->hashid}/decision", $decision);
    assertToast('warning', 'Wait for their written explanation');

    // The time to answer runs out: the right is waived.
    $this->travelTo(CarbonImmutable::parse('2026-11-22 09:00', 'Asia/Manila'));
    $this->post("/discipline/cases/{$case->hashid}/decision", $decision);
    assertToast('success');

    expect($case->refresh()->status)->toBe('decided')
        ->and($case->finding)->toBe('upheld')
        ->and($case->occurrence)->toBe(1);
});

test('a hearing asked for in writing is held before the decision', function () {
    $hr = dcHr();
    [$employee] = dcEmployee();
    $case = dcReady($employee, dcOffense(), $hr);
    app(DisciplineWorkflow::class)->explain($case, 'I was sick. I want to explain in person.', true, $employee->user);
    $decision = ['finding' => 'cleared', 'decision' => 'All the circumstances were considered; the medical certificate excuses the absence.'];

    $this->post("/discipline/cases/{$case->hashid}/decision", $decision);
    assertToast('warning', 'asked for a hearing');

    $this->post("/discipline/cases/{$case->hashid}/hearing", ['hearing_at' => '2026-11-18T10:00', 'hearing_place' => 'HR office'])->assertRedirect();
    expect($case->refresh()->hearing_at->toIso8601String())->toBe('2026-11-18T02:00:00+00:00');

    $this->post("/discipline/cases/{$case->hashid}/decision", $decision);
    assertToast('warning', 'Record the hearing');

    $this->post("/discipline/cases/{$case->hashid}/hearing/record", ['attended' => false, 'hearing_notes' => 'The employee did not come and sent no word.']);
    $this->post("/discipline/cases/{$case->hashid}/decision", $decision);
    assertToast('success');

    expect($case->refresh()->finding)->toBe('cleared')
        ->and($case->penalty)->toBeNull()
        ->and($case->hearing_attended)->toBeFalse();
});

test('dismissal rests on a just cause, never a company rule alone', function () {
    $hr = dcHr();
    [$employee] = dcEmployee();
    $rule = dcOffense(['code' => 'DUT-03', 'title' => 'Dress code', 'ground' => 'company_rule', 'penalties' => [['action' => 'verbal_warning']]]);
    $case = dcReady($employee, $rule, $hr);

    $this->post("/discipline/cases/{$case->hashid}/decision", ['finding' => 'upheld', 'penalty' => 'dismissal', 'decision' => 'All the circumstances were considered and the conduct is repeated.']);
    assertToast('warning', 'just cause');

    expect($case->refresh()->status)->toBe('explained');
});

test('the penalty climbs the ladder with each repeat, and an old case stops counting', function () {
    $hr = dcHr();
    [$employee] = dcEmployee();
    $offense = dcOffense();
    $workflow = app(DisciplineWorkflow::class);
    $reasons = 'All the circumstances were considered and the absence was not excused.';

    $first = dcReady($employee, $offense, $hr, '2026-11-02');
    $workflow->decide($first, ['finding' => 'upheld', 'penalty' => 'written_warning', 'decision' => $reasons], $hr);

    $second = dcReady($employee, $offense, $hr, '2026-11-09');
    expect($workflow->suggestion($second))->toMatchArray(['occurrence' => 2, 'penalty' => 'suspension', 'days' => 3, 'prior' => [$first->reference]]);
    $workflow->decide($second, ['finding' => 'upheld', 'penalty' => 'suspension', 'suspension_days' => 3, 'penalty_from' => '2026-11-17', 'decision' => $reasons], $hr);

    $second->refresh();
    expect($second->penalty_to->toDateString())->toBe('2026-11-19')
        ->and($second->occurrence)->toBe(2);

    $third = dcReady($employee, $offense, $hr, '2026-11-13');
    expect($workflow->suggestion($third)['penalty'])->toBe('dismissal');

    // A year on, the earlier ones no longer make it a repeat.
    $this->travelTo(CarbonImmutable::parse('2027-12-01 09:00', 'Asia/Manila'));
    $later = dcReady($employee, $offense, $hr, '2027-11-30');
    expect($workflow->suggestion($later)['occurrence'])->toBe(1);
});

test('a suspension runs one to thirty days, and nobody decides their own case', function () {
    $hr = dcHr();
    [$employee] = dcEmployee();
    $case = dcReady($employee, dcOffense(), $hr);

    $this->post("/discipline/cases/{$case->hashid}/decision", ['finding' => 'upheld', 'penalty' => 'suspension', 'suspension_days' => 45, 'decision' => 'All the circumstances were considered; the absence is not excused.'])
        ->assertSessionHasErrors('suspension_days');

    $self = Employee::factory()->create(['user_id' => $hr->id]);
    $own = dcReady($self, dcOffense(['code' => 'X-1']), User::factory()->create());

    $this->post("/discipline/cases/{$own->hashid}/decision", ['finding' => 'cleared', 'decision' => 'All the circumstances were considered and nothing was proven against me.']);
    assertToast('warning', 'decide your own case');
});

test('a preventive suspension runs at most thirty days, and only once a notice is served', function () {
    $hr = dcHr();
    [$employee] = dcEmployee();
    $case = dcReport($employee, null, $hr);

    $this->post("/discipline/cases/{$case->hashid}/preventive", ['preventive_from' => '2026-11-17', 'days' => 10]);
    assertToast('warning', 'served notice');

    app(DisciplineWorkflow::class)->issueNotice($case, 'Violation of CON-03: struck a colleague at the loading bay.', CarbonImmutable::parse('2026-11-21'), null, $hr);

    $this->post("/discipline/cases/{$case->hashid}/preventive", ['preventive_from' => '2026-11-17', 'days' => 31])->assertSessionHasErrors('days');
    $this->post("/discipline/cases/{$case->hashid}/preventive", ['preventive_from' => '2026-11-17', 'days' => 30]);

    expect($case->refresh()->preventive_to->toDateString())->toBe('2026-12-16');
});

test('the decision is served on the employee, who acknowledges it; both notices print', function () {
    $hr = dcHr();
    [$employee, $staff] = dcEmployee();
    $case = dcReady($employee, dcOffense(), $hr);
    app(DisciplineWorkflow::class)->decide($case, ['finding' => 'upheld', 'penalty' => 'written_warning', 'decision' => 'All the circumstances were considered; no medical certificate was shown.'], $hr);

    expect(substr($this->get("/discipline/cases/{$case->hashid}/notice.pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF')
        ->and(substr($this->get("/discipline/cases/{$case->hashid}/decision.pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');

    $this->actingAs($staff);
    $this->patch("/discipline/me/{$case->hashid}/acknowledge", ['notice' => 'decision']);

    expect($case->refresh()->decision_received_at)->not->toBeNull()
        ->and($staff->notifications()->count())->toBe(2)
        ->and(substr($this->get("/discipline/me/{$case->hashid}/decision.pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
});

test('a withdrawn case tells an employee already served, and cannot be decided', function () {
    $hr = dcHr();
    [$employee, $staff] = dcEmployee();
    $case = dcReady($employee, dcOffense(), $hr);

    $this->patch("/discipline/cases/{$case->hashid}/withdraw", ['withdrawn_reason' => 'The leave was approved late; the absence is excused.']);

    expect($case->refresh()->status)->toBe('withdrawn')
        ->and($staff->notifications()->count())->toBe(2);

    $this->post("/discipline/cases/{$case->hashid}/decision", ['finding' => 'cleared', 'decision' => 'All the circumstances were considered; the leave is approved.']);
    assertToast('warning');
});

// ── The conduct record ───────────────────────────────────────────────────────

test('where somebody stands follows what still counts against them', function () {
    $hr = dcHr();
    [$employee] = dcEmployee();
    $offense = dcOffense();
    $workflow = app(DisciplineWorkflow::class);
    $reasons = 'All the circumstances were considered and the absence was not excused.';

    expect(ConductRecord::for($employee)['standing']['key'])->toBe('good');

    $first = dcReady($employee, $offense, $hr);
    expect(ConductRecord::for($employee)['standing']['key'])->toBe('under_review');

    $workflow->decide($first, ['finding' => 'upheld', 'penalty' => 'written_warning', 'decision' => $reasons], $hr);
    $record = ConductRecord::for($employee);
    expect($record['standing'])->toMatchArray(['key' => 'warned', 'until' => '2027-11-16'])
        ->and($record['active'])->toHaveCount(1);

    $second = dcReady($employee, $offense, $hr);
    $workflow->decide($second, ['finding' => 'upheld', 'penalty' => 'suspension', 'suspension_days' => 3, 'penalty_from' => '2026-11-16', 'decision' => $reasons], $hr);
    expect(ConductRecord::for($employee)['standing'])->toMatchArray(['key' => 'suspended', 'until' => '2026-11-18']);

    $this->get("/discipline/people/{$employee->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('discipline/record')
        ->has('cases', 2)
        ->where('record.upheld', 2));
});

// ── The code of conduct ──────────────────────────────────────────────────────

test('the standard code adds once, and an offense on a company rule can’t end in dismissal', function () {
    actingAsUserWith(['discipline.manage']);

    $this->post('/discipline/code/standard')->assertRedirect();
    $this->post('/discipline/code/standard')->assertRedirect();

    expect(ConductOffense::count())->toBe(count(ConductCode::STANDARD));

    $this->post('/discipline/code', [
        'code' => 'X-9',
        'title' => 'Chewing gum',
        'gravity' => 'minor',
        'ground' => 'company_rule',
        'penalties' => [['action' => 'verbal_warning'], ['action' => 'dismissal']],
        'reckoning_months' => 6,
    ])->assertSessionHasErrors('penalties.1.action');

    $this->post('/discipline/code', [
        'code' => 'ATT-01',
        'title' => 'Duplicate',
        'gravity' => 'minor',
        'ground' => 'company_rule',
        'penalties' => [['action' => 'suspension']],
        'reckoning_months' => 6,
    ])->assertSessionHasErrors(['code', 'penalties.0.days']);
});

test('each step is in the activity log', function () {
    $hr = dcHr();
    [$employee] = dcEmployee();
    $case = dcReady($employee, dcOffense(), $hr);
    app(DisciplineWorkflow::class)->decide($case, ['finding' => 'upheld', 'penalty' => 'written_warning', 'decision' => 'All the circumstances were considered; the absence is not excused.'], $hr);

    expect(ActivityLog::query()->where('log_name', 'discipline')->pluck('description')->all())
        ->toHaveCount(4)
        ->each->toContain($case->reference);
});

// ── Who sees what ────────────────────────────────────────────────────────────

test('each lands on their own part of the module', function () {
    actingAsUserWith(['discipline.view']);
    $this->get('/discipline')->assertRedirect('/discipline/cases');

    actingAsUserWith(['discipline.report']);
    $this->get('/discipline')->assertRedirect('/discipline/reported');
    $this->get('/discipline/cases')->assertForbidden();

    actingAsUserWith(['discipline.respond']);
    $this->get('/discipline')->assertRedirect('/discipline/me');

    actingAsUserWith(['attendance.clock']);
    $this->get('/discipline')->assertForbidden();
});

test('HR’s list counts what waits on whom', function () {
    $hr = dcHr();
    [$employee] = dcEmployee();
    dcReport($employee, null, $hr);
    $served = dcReport($employee, null, $hr);
    app(DisciplineWorkflow::class)->issueNotice($served, 'Violation of ATT-02: absent on November 12 without leave.', CarbonImmutable::parse('2026-11-21'), null, $hr);

    $this->get('/discipline/cases')->assertOk()->assertInertia(fn ($page) => $page
        ->component('discipline/cases')
        ->has('cases.data', 2)
        ->where('stats.reported', 1)
        ->where('stats.awaiting', 1)
        ->where('counts.open', 2));
});

test('another company’s case is nobody’s here', function () {
    [$employee] = dcEmployee();
    $case = dcReport($employee);

    app(Tenancy::class)->set(Organization::factory()->create());
    actingAsUserWith(['discipline.view', 'discipline.manage']);
    app(Tenancy::class)->forget();

    $this->get("/discipline/cases/{$case->hashid}")->assertNotFound();
});
