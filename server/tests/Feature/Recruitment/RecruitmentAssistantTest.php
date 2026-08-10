<?php

use App\Models\Applicant;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Services\Assistant\Modules\RecruitmentModule;
use App\Services\Assistant\ToolResult;
use App\Support\Ai\GeminiClient;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Http;

/*
| The recruitment capability of the agentic assistant. Gemini decides *which*
| tool to call; these tests exercise what happens once it has — the module runs
| each action deterministically, permission-checked, validated and logged. No
| model call is made (except the one AI-read tool, where Gemini is faked).
| See App\Services\Assistant\Modules\RecruitmentModule.
*/

/** Run one assistant tool as the given user. */
function agent(User $user, string $tool, array $args = []): ToolResult
{
    return app(RecruitmentModule::class)->run($user, $tool, $args);
}

/** The tool names the module advertises to this user. */
function agentToolNames(User $user): array
{
    return array_column(app(RecruitmentModule::class)->tools($user), 'name');
}

/** A user holding every recruitment permission (but nothing else). */
function recruiter(): User
{
    return actingAsUserWith([
        'recruitment.view',
        'recruitment.create',
        'recruitment.update',
        'recruitment.delete',
        'recruitment.manage-pipeline',
        'recruitment.schedule-interviews',
        'recruitment.hire',
    ]);
}

// ── Tool surface & permissions ────────────────────────────────────────────────

test('the module advertises only the tools the user may run', function () {
    $viewer = actingAsUserWith(['recruitment.view']);
    $names = agentToolNames($viewer);

    expect($names)->toContain('find_job_postings', 'find_applications', 'recruitment_summary', 'rank_candidates', 'candidate_profile')
        ->not->toContain('create_job_posting')
        ->not->toContain('move_application')
        ->not->toContain('hire_applicant')
        ->not->toContain('schedule_interview');

    expect(agentToolNames(recruiter()))->toHaveCount(25);
});

test('every mutating tool is refused without its permission', function (string $tool, array $args) {
    $viewer = actingAsUserWith(['recruitment.view']);
    JobPosting::factory()->create(['title' => 'Backend Engineer']);
    JobApplication::factory()->create();

    $result = agent($viewer, $tool, $args);

    expect($result->failed())->toBeTrue()
        ->and($result->detail)->toContain('permission');
})->with([
    ['create_job_posting', ['title' => 'Nope']],
    ['update_job_posting', ['posting' => 'Backend Engineer', 'title' => 'Nope']],
    ['set_posting_status', ['posting' => 'Backend Engineer', 'status' => 'closed']],
    ['delete_job_posting', ['posting' => 'Backend Engineer']],
    ['add_applicant', ['first_name' => 'A', 'last_name' => 'B']],
    ['update_applicant', ['applicant' => 'A B', 'email' => 'a@b.test']],
    ['delete_applicant', ['applicant' => 'A B']],
    ['add_application', ['posting' => 'Backend Engineer', 'first_name' => 'A', 'last_name' => 'B']],
    ['move_application', ['applicant' => 'A B', 'stage' => 'screening']],
    ['advance_application', ['applicant' => 'A B']],
    ['update_application', ['applicant' => 'A B', 'rating' => 4]],
    ['reject_application', ['applicant' => 'A B']],
    ['withdraw_application', ['applicant' => 'A B']],
    ['hire_applicant', ['applicant' => 'A B']],
    ['schedule_interview', ['applicant' => 'A B', 'scheduled_at' => '2030-01-01 10:00', 'mode' => 'online']],
    ['update_interview', ['applicant' => 'A B', 'result' => 'passed']],
    ['cancel_interview', ['applicant' => 'A B']],
]);

// ── Vacancies ─────────────────────────────────────────────────────────────────

test('it finds postings by status and closing window', function () {
    $user = recruiter();
    JobPosting::factory()->create(['title' => 'Backend Engineer', 'status' => 'open', 'closing_date' => now()->addDays(3)]);
    JobPosting::factory()->create(['title' => 'Designer', 'status' => 'open', 'closing_date' => now()->addMonths(3)]);
    JobPosting::factory()->create(['title' => 'Archivist', 'status' => 'closed', 'closing_date' => null]);

    expect(agent($user, 'find_job_postings', ['status' => 'closed'])->cards)->toHaveCount(1);

    $soon = agent($user, 'find_job_postings', ['closing_within_days' => 7]);
    expect($soon->cards)->toHaveCount(1)
        ->and($soon->cards[0]['title'])->toBe('Backend Engineer')
        ->and($soon->cards[0]['meta'])->toContain('closes in 3d');
});

test('it creates a posting with screening criteria', function () {
    $user = recruiter();
    $department = Department::factory()->create(['name' => 'Engineering']);
    $position = Position::factory()->create(['title' => 'Backend Engineer', 'department_id' => $department->id]);

    $result = agent($user, 'create_job_posting', [
        'title' => 'Senior Backend Engineer',
        'department' => 'engineering',
        'position' => 'Backend Engineer',
        'employment_type' => 'regular',
        'openings' => 2,
        'min_years_experience' => 5,
        'skills' => ['Laravel', 'PostgreSQL'],
        'closing_date' => now()->addMonth()->toDateString(),
    ]);

    $posting = JobPosting::where('title', 'Senior Backend Engineer')->first();

    expect($result->failed())->toBeFalse()
        ->and($posting)->not->toBeNull()
        ->and($posting->department_id)->toBe($department->id)
        ->and($posting->position_id)->toBe($position->id)
        ->and($posting->min_years_experience)->toBe(5)
        ->and($posting->skills)->toBe(['Laravel', 'PostgreSQL'])
        ->and($posting->status)->toBe('open');
});

test('it refuses to open a posting without a closing date, and to guess a department', function () {
    $user = recruiter();

    $noDeadline = agent($user, 'create_job_posting', ['title' => 'No Deadline', 'status' => 'open']);
    expect($noDeadline->failed())->toBeTrue()
        ->and($noDeadline->detail)->toContain('closing date');

    $unknownDepartment = agent($user, 'create_job_posting', [
        'title' => 'Ghost Role',
        'department' => 'Department of Mystery',
        'status' => 'draft',
    ]);
    expect($unknownDepartment->failed())->toBeTrue()
        ->and(JobPosting::where('title', 'Ghost Role')->exists())->toBeFalse();
});

test('it edits a posting, leaving untouched fields alone', function () {
    $user = recruiter();
    $posting = JobPosting::factory()->create([
        'title' => 'Backend Engineer',
        'openings' => 1,
        'requirements' => 'Original requirements',
        'closing_date' => now()->addWeek(),
    ]);

    $result = agent($user, 'update_job_posting', [
        'posting' => 'Backend Engineer',
        'openings' => 3,
        'skills' => 'Laravel, Vue',
        'closing_date' => now()->addMonths(2)->toDateString(),
    ]);

    $posting->refresh();

    expect($result->failed())->toBeFalse()
        ->and($posting->openings)->toBe(3)
        ->and($posting->skills)->toBe(['Laravel', 'Vue'])
        ->and($posting->requirements)->toBe('Original requirements')
        ->and($posting->closing_date->toDateString())->toBe(now()->addMonths(2)->toDateString());
});

test('it moves a posting through its lifecycle and guards publishing', function () {
    $user = recruiter();
    $posting = JobPosting::factory()->draft()->create(['title' => 'Backend Engineer', 'closing_date' => null]);

    $publish = agent($user, 'set_posting_status', ['posting' => 'Backend Engineer', 'status' => 'open']);
    expect($publish->failed())->toBeTrue()
        ->and($posting->fresh()->status)->toBe('draft');

    agent($user, 'set_posting_status', [
        'posting' => 'Backend Engineer',
        'status' => 'open',
        'closing_date' => now()->addMonth()->toDateString(),
    ]);
    expect($posting->fresh()->status)->toBe('open');

    agent($user, 'set_posting_status', ['posting' => 'Backend Engineer', 'status' => 'filled']);
    expect($posting->fresh()->status)->toBe('filled');
});

test('it deletes a posting', function () {
    $user = recruiter();
    $posting = JobPosting::factory()->create(['title' => 'Backend Engineer']);

    $result = agent($user, 'delete_job_posting', ['posting' => 'Backend Engineer']);

    expect($result->failed())->toBeFalse()
        ->and(JobPosting::find($posting->id))->toBeNull();
});

// ── Candidate pool ────────────────────────────────────────────────────────────

test('it searches the candidate pool and adds someone new', function () {
    $user = recruiter();
    Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

    expect(agent($user, 'find_applicants', ['query' => 'Jane Doe'])->cards)->toHaveCount(1);

    $result = agent($user, 'add_applicant', [
        'first_name' => 'Mark',
        'last_name' => 'Reyes',
        'email' => 'mark@example.test',
        'headline' => 'Backend Engineer',
        'years_experience' => 6,
        'source' => 'referral',
    ]);

    $applicant = Applicant::where('email', 'mark@example.test')->first();

    expect($result->failed())->toBeFalse()
        ->and($applicant->years_experience)->toBe(6)
        ->and($applicant->source)->toBe('referral');
});

test('it will not add the same candidate to the pool twice', function () {
    $user = recruiter();
    Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.test']);

    $result = agent($user, 'add_applicant', [
        'first_name' => 'Janet',
        'last_name' => 'Doe',
        'email' => 'jane@example.test',
    ]);

    expect($result->failed())->toBeTrue()
        ->and(Applicant::count())->toBe(1);
});

test('it updates a candidate profile and refuses an empty edit', function () {
    $user = recruiter();
    $applicant = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'headline' => 'Junior Dev']);

    $result = agent($user, 'update_applicant', [
        'applicant' => 'Jane Doe',
        'headline' => 'Senior Developer',
        'years_experience' => 9,
    ]);

    expect($result->failed())->toBeFalse()
        ->and($applicant->fresh()->headline)->toBe('Senior Developer')
        ->and($applicant->fresh()->years_experience)->toBe(9);

    expect(agent($user, 'update_applicant', ['applicant' => 'Jane Doe'])->failed())->toBeTrue();
});

test('it removes a candidate, but never one who was hired', function () {
    $user = recruiter();
    $applicant = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    Applicant::factory()->create(['first_name' => 'Hired', 'last_name' => 'Person'])
        ->applications()->save(JobApplication::factory()->make(['stage' => 'hired']));

    expect(agent($user, 'delete_applicant', ['applicant' => 'Jane Doe'])->failed())->toBeFalse()
        ->and(Applicant::find($applicant->id))->toBeNull();

    $hired = agent($user, 'delete_applicant', ['applicant' => 'Hired Person']);
    expect($hired->failed())->toBeTrue()
        ->and(Applicant::where('last_name', 'Person')->exists())->toBeTrue();
});

// ── Pipeline ──────────────────────────────────────────────────────────────────

test('it lists a pipeline and can single out the stalled candidates', function () {
    $user = recruiter();
    $posting = JobPosting::factory()->create(['title' => 'Backend Engineer']);
    JobApplication::factory()->create([
        'job_posting_id' => $posting->id,
        'stage' => 'screening',
        'applied_at' => now()->subDays(30),
    ]);
    JobApplication::factory()->create([
        'job_posting_id' => $posting->id,
        'stage' => 'applied',
        'applied_at' => now()->subDay(),
    ]);

    expect(agent($user, 'find_applications', ['posting' => 'Backend Engineer'])->cards)->toHaveCount(2)
        ->and(agent($user, 'find_applications', ['stage' => 'screening'])->cards)->toHaveCount(1)
        ->and(agent($user, 'find_applications', ['stalled' => true])->cards)->toHaveCount(1);
});

test('it adds a candidate to a pipeline, reusing the pool entry by email', function () {
    $user = recruiter();
    JobPosting::factory()->create(['title' => 'Backend Engineer']);
    Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.test']);

    $result = agent($user, 'add_application', [
        'posting' => 'Backend Engineer',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.test',
        'rating' => 4,
        'expected_salary' => 80000,
    ]);

    $application = JobApplication::first();

    expect($result->failed())->toBeFalse()
        ->and(Applicant::count())->toBe(1)
        ->and($application->stage)->toBe('applied')
        ->and($application->rating)->toBe(4);

    // A second attempt on the same posting is refused rather than duplicated.
    $again = agent($user, 'add_application', [
        'posting' => 'Backend Engineer',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.test',
    ]);

    expect($again->failed())->toBeTrue()
        ->and(JobApplication::count())->toBe(1);
});

test('it moves a candidate, reinstates a rejected one, and never un-hires', function () {
    $user = recruiter();
    $applicant = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $application = JobApplication::factory()->create(['applicant_id' => $applicant->id, 'stage' => 'applied']);

    agent($user, 'move_application', ['applicant' => 'Jane Doe', 'stage' => 'interview']);
    expect($application->fresh()->stage)->toBe('interview');

    $application->rejectWith('Not a fit');
    agent($user, 'move_application', ['applicant' => 'Jane Doe', 'stage' => 'screening']);
    expect($application->fresh()->stage)->toBe('screening')
        ->and($application->fresh()->rejected_reason)->toBeNull();

    $employee = Employee::factory()->create();
    $application->update(['stage' => 'hired', 'hired_employee_id' => $employee->id]);

    $blocked = agent($user, 'move_application', ['applicant' => 'Jane Doe', 'stage' => 'offer']);
    expect($blocked->failed())->toBeTrue()
        ->and($application->fresh()->stage)->toBe('hired');
});

test('it takes the recommended next step, but stops short of rejecting or hiring', function () {
    $user = recruiter();
    $strong = Applicant::factory()->create(['first_name' => 'Strong', 'last_name' => 'Candidate', 'years_experience' => 10]);
    $application = JobApplication::factory()->create([
        'applicant_id' => $strong->id,
        'stage' => 'applied',
        'rating' => 5,
    ]);

    $advanced = agent($user, 'advance_application', ['applicant' => 'Strong Candidate']);
    expect($advanced->failed())->toBeFalse()
        ->and($application->fresh()->stage)->toBe('screening');

    // At offer stage the recommendation is to hire — an irreversible step the
    // agent reports back instead of taking on its own.
    $application->update(['stage' => 'offer']);
    $atOffer = agent($user, 'advance_application', ['applicant' => 'Strong Candidate']);
    expect($atOffer->failed())->toBeTrue()
        ->and($atOffer->detail)->toContain('Hire candidate')
        ->and($application->fresh()->stage)->toBe('offer');

    // A weak candidate in screening is recommended for rejection — also refused.
    $weak = Applicant::factory()->create(['first_name' => 'Weak', 'last_name' => 'Candidate', 'years_experience' => null]);
    $weakApplication = JobApplication::factory()->create([
        'applicant_id' => $weak->id,
        'stage' => 'screening',
        'rating' => null,
    ]);

    $rejectSuggested = agent($user, 'advance_application', ['applicant' => 'Weak Candidate']);
    expect($rejectSuggested->failed())->toBeTrue()
        ->and($weakApplication->fresh()->stage)->toBe('screening');
});

test('it rates an application and refuses a rating with nothing to set', function () {
    $user = recruiter();
    $applicant = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $application = JobApplication::factory()->create(['applicant_id' => $applicant->id, 'rating' => null]);

    expect(agent($user, 'update_application', ['applicant' => 'Jane Doe', 'rating' => 5])->failed())->toBeFalse()
        ->and($application->fresh()->rating)->toBe(5);

    expect(agent($user, 'update_application', ['applicant' => 'Jane Doe'])->failed())->toBeTrue();
    expect(agent($user, 'update_application', ['applicant' => 'Jane Doe', 'rating' => 9])->failed())->toBeTrue();
});

test('it rejects and withdraws applications', function () {
    $user = recruiter();
    $jane = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $mark = Applicant::factory()->create(['first_name' => 'Mark', 'last_name' => 'Reyes']);
    $rejected = JobApplication::factory()->create(['applicant_id' => $jane->id, 'stage' => 'screening']);
    $withdrawn = JobApplication::factory()->create(['applicant_id' => $mark->id, 'stage' => 'applied']);

    agent($user, 'reject_application', ['applicant' => 'Jane Doe', 'reason' => 'Withdrew from the process']);
    expect($rejected->fresh()->stage)->toBe('rejected')
        ->and($rejected->fresh()->rejected_reason)->toBe('Withdrew from the process');

    agent($user, 'withdraw_application', ['applicant' => 'Mark Reyes']);
    expect(JobApplication::find($withdrawn->id))->toBeNull()
        ->and(Applicant::find($mark->id))->not->toBeNull();
});

test('it hires a candidate through the shared hire bridge', function () {
    $user = recruiter();
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id]);
    $posting = JobPosting::factory()->create([
        'title' => 'Backend Engineer',
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

    $result = agent($user, 'hire_applicant', ['applicant' => 'Future Hire', 'send_invitation' => false]);

    $employee = Employee::where('last_name', 'Hire')->first();

    expect($result->failed())->toBeFalse()
        ->and($employee)->not->toBeNull()
        ->and($application->fresh()->hired_employee_id)->toBe($employee->id)
        ->and($posting->fresh()->status)->toBe('filled');
});

// ── Interviews ────────────────────────────────────────────────────────────────

test('it lists upcoming interviews', function () {
    $user = recruiter();
    Interview::factory()->create(['scheduled_at' => now()->addDays(2)]);
    Interview::factory()->create(['scheduled_at' => now()->subDays(2)]);

    expect(agent($user, 'find_interviews')->cards)->toHaveCount(1)
        ->and(agent($user, 'find_interviews', ['when' => 'past'])->cards)->toHaveCount(1)
        ->and(agent($user, 'find_interviews', ['when' => 'all'])->cards)->toHaveCount(2);
});

test('scheduling an interview pulls the candidate into the interview stage', function () {
    $user = recruiter();
    $applicant = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $application = JobApplication::factory()->create(['applicant_id' => $applicant->id, 'stage' => 'applied']);

    $result = agent($user, 'schedule_interview', [
        'applicant' => 'Jane Doe',
        'scheduled_at' => now()->addDays(3)->setTime(14, 0)->toDateTimeString(),
        'mode' => 'online',
        'interviewer' => $user->full_name,
        'location' => 'Google Meet',
    ]);

    $interview = $application->interviews()->first();

    expect($result->failed())->toBeFalse()
        ->and($application->fresh()->stage)->toBe('interview')
        ->and($interview->mode)->toBe('online')
        ->and($interview->interviewer_id)->toBe($user->id);
});

test('it reschedules an interview and records its outcome', function () {
    $user = recruiter();
    $applicant = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $application = JobApplication::factory()->create(['applicant_id' => $applicant->id, 'stage' => 'interview']);
    $interview = Interview::factory()->create([
        'job_application_id' => $application->id,
        'scheduled_at' => now()->addDays(2),
        'result' => 'pending',
    ]);

    agent($user, 'update_interview', [
        'applicant' => 'Jane Doe',
        'scheduled_at' => now()->addDays(5)->setTime(9, 30)->toDateTimeString(),
        'mode' => 'onsite',
    ]);

    expect($interview->fresh()->mode)->toBe('onsite')
        ->and($interview->fresh()->scheduled_at->format('H:i'))->toBe('09:30');

    $recorded = agent($user, 'update_interview', [
        'applicant' => 'Jane Doe',
        'result' => 'passed',
        'feedback' => 'Excellent systems thinking.',
    ]);

    expect($recorded->failed())->toBeFalse()
        ->and($interview->fresh()->result)->toBe('passed')
        ->and($interview->fresh()->feedback)->toBe('Excellent systems thinking.');

    expect(agent($user, 'update_interview', ['applicant' => 'Jane Doe'])->failed())->toBeTrue();
});

test('it cancels a scheduled interview', function () {
    $user = recruiter();
    $applicant = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $application = JobApplication::factory()->create(['applicant_id' => $applicant->id, 'stage' => 'interview']);
    $interview = Interview::factory()->create([
        'job_application_id' => $application->id,
        'scheduled_at' => now()->addDays(2),
    ]);

    expect(agent($user, 'cancel_interview', ['applicant' => 'Jane Doe'])->failed())->toBeFalse()
        ->and(Interview::find($interview->id))->toBeNull();
});

// ── Decision support ──────────────────────────────────────────────────────────

test('it summarises recruitment org-wide and per posting', function () {
    $user = recruiter();
    $posting = JobPosting::factory()->create(['title' => 'Backend Engineer', 'status' => 'open']);
    JobApplication::factory()->create(['job_posting_id' => $posting->id, 'stage' => 'offer', 'applied_at' => now()->subDays(3)]);
    JobApplication::factory()->create(['job_posting_id' => $posting->id, 'stage' => 'applied', 'applied_at' => now()->subDays(40)]);

    $overall = agent($user, 'recruitment_summary');
    expect($overall->failed())->toBeFalse()
        ->and($overall->cards[0]['title'])->toBe('Recruitment overview')
        ->and($overall->cards[0]['kind'])->toBe('insight')
        ->and(implode(' ', $overall->cards[0]['meta']))->toContain('1 stalled');

    $pipeline = agent($user, 'recruitment_summary', ['posting' => 'Backend Engineer']);
    expect($pipeline->cards[0]['title'])->toBe('Backend Engineer')
        ->and($pipeline->cards[0]['subtitle'])->toContain('2 active of 2 applicants');

    expect(agent($user, 'recruitment_summary', ['posting' => 'No Such Role'])->failed())->toBeTrue();
});

test('it ranks a posting\'s candidates by fit', function () {
    $user = recruiter();
    $posting = JobPosting::factory()->create(['title' => 'Backend Engineer', 'min_years_experience' => 5]);

    $strong = Applicant::factory()->create(['first_name' => 'Strong', 'last_name' => 'Fit', 'years_experience' => 8]);
    $weak = Applicant::factory()->create(['first_name' => 'Weak', 'last_name' => 'Fit', 'years_experience' => 0]);

    JobApplication::factory()->create(['job_posting_id' => $posting->id, 'applicant_id' => $strong->id, 'rating' => 5, 'stage' => 'screening']);
    JobApplication::factory()->create(['job_posting_id' => $posting->id, 'applicant_id' => $weak->id, 'rating' => 1, 'stage' => 'screening']);
    JobApplication::factory()->create(['job_posting_id' => $posting->id, 'stage' => 'rejected', 'rating' => 5]);

    $result = agent($user, 'rank_candidates', ['posting' => 'Backend Engineer']);

    // The rejected card is out of contention, so only the two live ones rank.
    expect($result->cards)->toHaveCount(2)
        ->and($result->cards[0]['title'])->toBe('Strong Fit')
        ->and($result->cards[0]['badge'])->toBe('#1')
        ->and($result->cards[1]['title'])->toBe('Weak Fit');
});

test('it reads out one candidate profile with fit, rank and the next step', function () {
    $user = recruiter();
    $posting = JobPosting::factory()->create(['title' => 'Backend Engineer']);
    $applicant = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    JobApplication::factory()->create([
        'job_posting_id' => $posting->id,
        'applicant_id' => $applicant->id,
        'stage' => 'screening',
        'rating' => 4,
    ]);

    $result = agent($user, 'candidate_profile', ['applicant' => 'Jane Doe']);
    $meta = implode(' | ', $result->cards[0]['meta']);

    expect($result->failed())->toBeFalse()
        ->and($result->cards[0]['title'])->toBe('Jane Doe')
        ->and($meta)->toContain('Fit ')
        ->and($meta)->toContain('Rank 1 of 1')
        ->and($meta)->toContain('Rated 4/5')
        ->and($meta)->toContain('Next: ');

    expect(agent($user, 'candidate_profile', ['applicant' => 'Nobody Here'])->failed())->toBeTrue();
});

test('it returns the saved AI read without spending a model call, and refreshes on request', function () {
    $user = recruiter();
    $applicant = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $application = JobApplication::factory()->create([
        'applicant_id' => $applicant->id,
        'stage' => 'screening',
        'ai_insights' => [
            'available' => true,
            'headline' => 'Saved verdict',
            'summary' => 'From the last read.',
            'strengths' => ['Deep PHP experience'],
            'concerns' => ['Thin on leadership'],
            'recommendation' => 'Interview them.',
        ],
    ]);

    Http::preventStrayRequests();

    $saved = agent($user, 'candidate_insights', ['applicant' => 'Jane Doe']);
    expect($saved->failed())->toBeFalse()
        ->and($saved->cards[0]['subtitle'])->toContain('Saved verdict')
        ->and($saved->cards[0]['badge'])->toBe('AI read');

    Http::fake(['*generativelanguage*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => json_encode([
            'headline' => 'Fresh verdict',
            'summary' => 'Read the résumé again.',
            'strengths' => ['Ships fast'],
            'concerns' => ['No tests'],
            'document_insights' => 'No readable documents were attached.',
            'interview_questions' => ['How do you test?'],
            'recommendation' => 'Advance to interview.',
        ])]]]]],
    ])]);
    $this->app->instance(GeminiClient::class, new GeminiClient(apiKey: 'test-key', model: 'gemini-2.5-flash'));

    $fresh = agent($user, 'candidate_insights', ['applicant' => 'Jane Doe', 'refresh' => true]);

    expect($fresh->failed())->toBeFalse()
        ->and($fresh->cards[0]['subtitle'])->toContain('Fresh verdict')
        ->and($application->fresh()->ai_insights['headline'])->toBe('Fresh verdict');
});

test('the AI read degrades gracefully when the model is unavailable', function () {
    $user = recruiter();
    $applicant = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    JobApplication::factory()->create(['applicant_id' => $applicant->id]);

    $this->app->instance(GeminiClient::class, new GeminiClient(apiKey: null, model: 'gemini-2.5-flash'));

    $result = agent($user, 'candidate_insights', ['applicant' => 'Jane Doe']);

    expect($result->failed())->toBeTrue()
        ->and($result->detail)->toContain('GEMINI_API_KEY');
});

// ── Tenancy ───────────────────────────────────────────────────────────────────

test('the agent never sees another organisation\'s recruitment data', function () {
    $user = recruiter();
    JobPosting::factory()->create(['title' => 'Backend Engineer']);

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, function () {
        JobPosting::factory()->count(3)->create(['title' => 'Backend Engineer']);
    });

    expect(agent($user, 'find_job_postings', ['query' => 'Backend'])->cards)->toHaveCount(1);
});
