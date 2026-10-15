<?php

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Files\UploadAccess;
use App\Support\Help\HelpCenter;
use App\Support\OrganizationProvisioner;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Interviewing beyond HR (ADR 0091): `recruitment.interview` lets somebody see
| and record the interviews they are assigned — and nothing else of hiring —
| and only people who may interview can be put on one.
*/

/** A signed-in member who may interview, and nothing more of recruitment. */
function ivInterviewer(): User
{
    return actingAsUserWith(['recruitment.interview']);
}

/** An interview of a fresh candidate, assigned to this person. */
function ivInterviewFor(?User $interviewer, array $attributes = []): Interview
{
    return Interview::factory()->create([
        'interviewer_id' => $interviewer?->id,
        'scheduled_at' => now()->addDay(),
        ...$attributes,
    ]);
}

test('an interviewer sees the interviews assigned to them, and only those', function () {
    $mine = ivInterviewFor($me = ivInterviewer());
    $mine->application->update(['expected_salary' => 40000]);
    ivInterviewFor(User::factory()->create());
    $done = ivInterviewFor($me, ['result' => 'passed', 'scheduled_at' => now()->subDay()]);

    $this->get(route('recruitment.interviews.mine'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('recruitment/interviews')
            ->has('interviews.pending', 1)
            ->where('interviews.pending.0.id', $mine->id)
            ->where('interviews.pending.0.candidate.name', $mine->application->applicant->full_name)
            // Pay stays with HR.
            ->missing('interviews.pending.0.expected_salary')
            ->missing('interviews.pending.0.candidate.expected_salary')
            ->has('interviews.done', 1)
            ->where('interviews.done.0.id', $done->id));
});

test('an interviewer records the verdict on their own interview, never on another', function () {
    $mine = ivInterviewFor($me = ivInterviewer());
    $theirs = ivInterviewFor(User::factory()->create());

    $this->post(route('recruitment.interviews.update', $mine), ['result' => 'passed', 'feedback' => 'Clear thinker.'])
        ->assertSessionHasNoErrors();

    $this->post(route('recruitment.interviews.update', $theirs), ['result' => 'failed'])->assertForbidden();

    expect($mine->fresh()->result)->toBe('passed')
        ->and($mine->fresh()->feedback)->toBe('Clear thinker.')
        ->and($theirs->fresh()->result)->toBe('pending');
});

test('somebody without the permission records nothing, even if named on it', function () {
    $me = actingAsUserWith(['attendance.clock']);
    $interview = ivInterviewFor($me);

    $this->post(route('recruitment.interviews.update', $interview), ['result' => 'passed'])->assertForbidden();
    $this->get(route('recruitment.interviews.mine'))->assertForbidden();
});

test('an interviewer lands on their interviews and cannot open the board', function () {
    ivInterviewer();
    $application = JobApplication::factory()->create();

    $this->get(route('recruitment.index'))->assertRedirect(route('recruitment.interviews.mine'));
    $this->get(route('recruitment.show', $application->jobPosting))->assertForbidden();
});

test('recruitment stays closed to somebody who neither hires nor interviews', function () {
    actingAsUserWith(['attendance.clock']);

    $this->get(route('recruitment.index'))->assertForbidden();
});

test('only people who may interview are offered, from this workspace only', function () {
    actingAsSuperAdmin();
    $interviewing = makeRole('interviewer', ['recruitment.interview']);
    $interviewer = User::factory()->create(['first_name' => 'Ina']);
    $interviewer->roles()->attach($interviewing);
    $bystander = User::factory()->create(['first_name' => 'Bea']);
    $bystander->roles()->attach(makeRole('clocker', ['attendance.clock']));
    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->roles()->attach($interviewing);

    // Somebody of another company who may interview there.
    $other = Organization::factory()->create();
    OrganizationProvisioner::provisionRoles($other);
    $outsider = app(Tenancy::class)->runFor($other, fn () => User::factory()->create(['first_name' => 'Otto']));
    OrganizationProvisioner::admit($other, $outsider, role: Role::HR_MANAGER);

    $application = JobApplication::factory()->create();

    $this->get(route('recruitment.show', $application->jobPosting))
        ->assertInertia(fn (Assert $page) => $page
            ->where('options.interviewers', fn ($people) => collect($people)->pluck('id')->contains($interviewer->id)
                && ! collect($people)->pluck('id')->contains($bystander->id)
                && ! collect($people)->pluck('id')->contains($inactive->id)
                && ! collect($people)->pluck('id')->contains($outsider->id)));
});

test('an interview cannot be booked with somebody who could not open it', function () {
    actingAsSuperAdmin();
    $bystander = User::factory()->create();
    $bystander->roles()->attach(makeRole('clocker', ['attendance.clock']));
    $application = JobApplication::factory()->stage('applied')->create();

    $this->post(route('recruitment.interviews.store', $application), [
        'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        'mode' => 'online',
        'interviewer_id' => $bystander->id,
    ])->assertSessionHasErrors('interviewer_id');

    expect($application->interviews()->count())->toBe(0);
});

test('the interviewer is told when an interview is booked with them', function () {
    actingAsSuperAdmin();
    $interviewer = User::factory()->create();
    $interviewer->roles()->attach(makeRole('interviewer', ['recruitment.interview']));
    $application = JobApplication::factory()->stage('applied')->create();

    $this->post(route('recruitment.interviews.store', $application), [
        'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        'mode' => 'online',
        'interviewer_id' => $interviewer->id,
    ])->assertSessionHasNoErrors();

    $notice = $interviewer->notifications()->first();

    expect($notice)->not->toBeNull()
        ->and($notice->data['title'])->toBe('You have an interview')
        ->and($notice->data['body'])->toContain($application->applicant->full_name);
});

test('an interviewer opens the résumé of their candidate, not others or their documents', function () {
    $me = ivInterviewer();
    $mine = ivInterviewFor($me);
    $applicant = $mine->application->applicant;
    $applicant->update(['resume' => 'applicant-resumes/mine.pdf']);
    ApplicantDocument::create(['applicant_id' => $applicant->id, 'type' => 'government_id', 'title' => 'ID', 'file' => 'applicant-documents/id.pdf']);
    Applicant::factory()->create(['resume' => 'applicant-resumes/stranger.pdf']);

    $access = app(UploadAccess::class);

    expect($access->allows($me, 'applicant-resumes/mine.pdf'))->toBeTrue()
        ->and($access->allows($me, 'applicant-resumes/stranger.pdf'))->toBeFalse()
        ->and($access->allows($me, 'applicant-documents/id.pdf'))->toBeFalse();
});

test('department heads and staff may interview out of the box', function () {
    $organization = Organization::factory()->create();
    OrganizationProvisioner::provisionRoles($organization);

    $holds = fn (string $role): bool => Role::withoutGlobalScopes()
        ->where('organization_id', $organization->id)->where('name', $role)->firstOrFail()
        ->permissions()->where('name', 'recruitment.interview')->exists();

    expect($holds(Role::STAFF))->toBeTrue()
        ->and($holds(Role::DEPARTMENT_HEAD))->toBeTrue();
});

test('an interviewer finds the interviewing help from their screen', function () {
    $me = ivInterviewer();

    expect(HelpCenter::forPath($me, '/recruitment/interviews')['slug'] ?? null)->toBe('interviews-and-hiring');
});
