<?php

use App\Models\Applicant;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Models\Position;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

// ── Postings ──────────────────────────────────────────────────────────────────

test('the recruitment index renders', function () {
    actingAsSuperAdmin();
    JobPosting::factory()->count(2)->create();

    $this->get(route('recruitment.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('recruitment/index')
            ->has('postings.data', 2)
            ->has('stats')
            ->has('options')
            ->has('can')
            ->has('filters'));
});

test('it filters postings by status', function () {
    actingAsSuperAdmin();
    JobPosting::factory()->create(['status' => 'open']);
    JobPosting::factory()->create(['status' => 'closed']);

    $this->get(route('recruitment.index', ['status' => 'closed']))
        ->assertInertia(fn (Assert $page) => $page->has('postings.data', 1));
});

test('it creates a job posting', function () {
    actingAsSuperAdmin();

    $this->post(route('recruitment.store'), [
        'title' => 'Backend Engineer',
        'employment_type' => 'regular',
        'openings' => 2,
        'status' => 'open',
        'closing_date' => now()->addMonth()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect(JobPosting::where('title', 'Backend Engineer')->exists())->toBeTrue();
});

test('an open posting requires a closing date', function () {
    actingAsSuperAdmin();

    $this->post(route('recruitment.store'), [
        'title' => 'No Deadline',
        'employment_type' => 'regular',
        'openings' => 1,
        'status' => 'open',
    ])->assertSessionHasErrors('closing_date');

    // A draft may omit it.
    $this->post(route('recruitment.store'), [
        'title' => 'Draft Role',
        'employment_type' => 'regular',
        'openings' => 1,
        'status' => 'draft',
    ])->assertSessionHasNoErrors();
});

test('it validates required fields when creating a posting', function () {
    actingAsSuperAdmin();

    $this->post(route('recruitment.store'), [])
        ->assertSessionHasErrors(['title', 'employment_type', 'openings', 'status']);
});

test('it updates and deletes a posting', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create();

    $this->post(route('recruitment.update', $posting), [
        'title' => 'Renamed Role',
        'employment_type' => 'contractual',
        'openings' => 1,
        'status' => 'open',
        'closing_date' => now()->addMonth()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect($posting->fresh()->title)->toBe('Renamed Role');

    $this->delete(route('recruitment.destroy', $posting))->assertSessionHasNoErrors();
    expect(JobPosting::find($posting->id))->toBeNull();
});

test('it changes a posting status', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create(['status' => 'open']);

    $this->patch(route('recruitment.status', $posting), ['status' => 'closed'])
        ->assertSessionHasNoErrors();

    expect($posting->fresh()->status)->toBe('closed');
});

// ── Pipeline ──────────────────────────────────────────────────────────────────

test('the pipeline board renders', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create();
    JobApplication::factory()->count(3)->create(['job_posting_id' => $posting->id]);

    $this->get(route('recruitment.show', $posting))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('recruitment/pipeline')
            ->has('posting')
            ->has('applications', 3)
            ->has('options')
            ->has('can'));
});

test('the pipeline board carries decision-support insights', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create();
    JobApplication::factory()->count(3)->create([
        'job_posting_id' => $posting->id,
        'stage' => 'applied',
    ]);

    $this->get(route('recruitment.show', $posting))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('insights.overall', fn (Assert $overall) => $overall
                ->where('total', 3)
                ->where('active', 3)
                ->etc())
            ->has('insights.stages.applied')
            ->has('insights.stages.hired'));
});

test('it exports a posting pipeline as csv', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create();
    JobApplication::factory()->count(2)->create(['job_posting_id' => $posting->id]);

    $response = $this->get(route('recruitment.pipeline.export', $posting));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});

test('it adds a new applicant to the pipeline', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create();

    $this->post(route('recruitment.applications.store', $posting), [
        'first_name' => 'Hopeful',
        'last_name' => 'Candidate',
        'source' => 'referral',
    ])->assertSessionHasNoErrors();

    $applicant = Applicant::where('last_name', 'Candidate')->first();
    expect($applicant)->not->toBeNull()
        ->and($posting->applications()->where('applicant_id', $applicant->id)->where('stage', 'applied')->exists())->toBeTrue();
});

test('it adds an existing applicant to the pipeline', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create();
    $applicant = Applicant::factory()->create();

    $this->post(route('recruitment.applications.store', $posting), [
        'applicant_id' => $applicant->id,
    ])->assertSessionHasNoErrors();

    expect($posting->applications()->where('applicant_id', $applicant->id)->exists())->toBeTrue();
});

test('it prevents a duplicate application for the same posting', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create();
    $applicant = Applicant::factory()->create();
    JobApplication::factory()->create([
        'job_posting_id' => $posting->id,
        'applicant_id' => $applicant->id,
    ]);

    $this->post(route('recruitment.applications.store', $posting), [
        'applicant_id' => $applicant->id,
    ])->assertSessionHasNoErrors();

    expect($posting->applications()->where('applicant_id', $applicant->id)->count())->toBe(1);
});

test('it moves an application to another stage', function () {
    actingAsSuperAdmin();
    $application = JobApplication::factory()->create(['stage' => 'applied']);

    $this->patch(route('recruitment.applications.stage', $application), ['stage' => 'screening'])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->stage)->toBe('screening');
});

test('it cannot move an application straight to hired via the stage endpoint', function () {
    actingAsSuperAdmin();
    $application = JobApplication::factory()->create(['stage' => 'offer']);

    $this->patch(route('recruitment.applications.stage', $application), ['stage' => 'hired'])
        ->assertSessionHasErrors('stage');
});

test('it rejects an application with a reason', function () {
    actingAsSuperAdmin();
    $application = JobApplication::factory()->create(['stage' => 'screening']);

    $this->patch(route('recruitment.applications.reject', $application), ['reason' => 'Withdrew'])
        ->assertSessionHasNoErrors();

    $fresh = $application->fresh();
    expect($fresh->stage)->toBe('rejected')
        ->and($fresh->rejected_reason)->toBe('Withdrew');
});

// ── Interviews ────────────────────────────────────────────────────────────────

test('scheduling an interview advances the application to the interview stage', function () {
    actingAsSuperAdmin();
    $application = JobApplication::factory()->create(['stage' => 'applied']);

    $this->post(route('recruitment.interviews.store', $application), [
        'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        'mode' => 'online',
    ])->assertSessionHasNoErrors();

    expect($application->interviews()->count())->toBe(1)
        ->and($application->fresh()->stage)->toBe('interview');
});

test('it records an interview result', function () {
    actingAsSuperAdmin();
    $interview = Interview::factory()->create(['result' => 'pending']);

    $this->post(route('recruitment.interviews.update', $interview), [
        'result' => 'passed',
        'feedback' => 'Strong candidate',
    ])->assertSessionHasNoErrors();

    expect($interview->fresh()->result)->toBe('passed');
});

// ── The hire bridge ───────────────────────────────────────────────────────────

test('hiring an applicant creates a linked employee and fills the posting', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id]);
    $posting = JobPosting::factory()->create([
        'department_id' => $department->id,
        'position_id' => $position->id,
        'openings' => 1,
        'status' => 'open',
    ]);
    $applicant = Applicant::factory()->create(['first_name' => 'Future', 'last_name' => 'Hire']);
    $application = JobApplication::factory()->create([
        'job_posting_id' => $posting->id,
        'applicant_id' => $applicant->id,
        'stage' => 'offer',
    ]);

    $this->post(route('recruitment.applications.hire', $application))
        ->assertSessionHasNoErrors();

    $employee = Employee::where('first_name', 'Future')->where('last_name', 'Hire')->first();
    $fresh = $application->fresh();

    expect($employee)->not->toBeNull()
        ->and($employee->department_id)->toBe($department->id)
        ->and($employee->position_id)->toBe($position->id)
        ->and($fresh->stage)->toBe('hired')
        ->and($fresh->hired_employee_id)->toBe($employee->id)
        ->and($posting->fresh()->status)->toBe('filled');
});

test('an applicant cannot be hired twice', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();
    $application = JobApplication::factory()->create([
        'stage' => 'hired',
        'hired_employee_id' => $employee->id,
    ]);

    $before = Employee::count();

    $this->post(route('recruitment.applications.hire', $application))
        ->assertSessionHasNoErrors();

    expect(Employee::count())->toBe($before);
});

// ── Export & authorization ────────────────────────────────────────────────────

test('it exports postings as csv', function () {
    actingAsSuperAdmin();
    JobPosting::factory()->count(2)->create();

    $response = $this->get(route('recruitment.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});

test('recruitment routes are permission gated', function () {
    actingAsUserWith([]); // no recruitment permissions

    $this->get(route('recruitment.index'))->assertForbidden();
});

test('viewing does not grant creating', function () {
    actingAsUserWith(['recruitment.view']);

    $this->get(route('recruitment.index'))->assertOk();
    $this->post(route('recruitment.store'), [
        'title' => 'Nope',
        'employment_type' => 'regular',
        'openings' => 1,
        'status' => 'open',
    ])->assertForbidden();
});

test('postings are isolated per organisation', function () {
    actingAsSuperAdmin();
    JobPosting::factory()->count(2)->create();

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => JobPosting::factory()->count(4)->create());

    $this->get(route('recruitment.index'))
        ->assertInertia(fn (Assert $page) => $page->has('postings.data', 2));
});
