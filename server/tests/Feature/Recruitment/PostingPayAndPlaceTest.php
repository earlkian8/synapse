<?php

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Models\WorkLocation;
use App\Support\Recruitment\ApplicantScorer;
use Inertia\Testing\AssertableInertia as Assert;

/*
| What a posting pays and where it is done, and an expected salary that always
| says what it is per (ADR 0091). Experience takes half years.
*/

/** The fields a posting form sends, with overrides. */
function payPostingForm(array $overrides = []): array
{
    return [
        'title' => 'Payroll Officer',
        'recruitment_pipeline_id' => seedDefaultPipeline()->id,
        'employment_type' => 'regular',
        'openings' => 1,
        'status' => 'open',
        'closing_date' => now()->addMonth()->toDateString(),
        ...$overrides,
    ];
}

test('a posting carries its pay range, period, arrangement and site', function () {
    actingAsSuperAdmin();
    $site = WorkLocation::create(['name' => 'Makati office', 'latitude' => 14.55, 'longitude' => 121.02, 'radius_meters' => 150]);

    $this->post(route('recruitment.store'), payPostingForm([
        'salary_min' => 25000,
        'salary_max' => 35000,
        'salary_period' => 'monthly',
        'show_salary' => true,
        'work_arrangement' => 'hybrid',
        'work_location_id' => $site->id,
        'min_years_experience' => 1.5,
    ]))->assertSessionHasNoErrors();

    $posting = JobPosting::firstOrFail();

    expect($posting->payLabel())->toBe('₱25,000–₱35,000 a month')
        ->and($posting->work_arrangement)->toBe('hybrid')
        ->and($posting->work_location_id)->toBe($site->id)
        ->and($posting->min_years_experience)->toBe(1.5);
});

test('a pay figure reads as a reader would say it', function () {
    expect(JobPosting::money(600, null, 'daily'))->toBe('From ₱600 a day')
        ->and(JobPosting::money(null, 1200000, 'annual'))->toBe('Up to ₱1,200,000 a year')
        ->and(JobPosting::money(87.5, 87.5, 'hourly'))->toBe('₱87.50 an hour')
        ->and(JobPosting::money(null, null, 'monthly'))->toBeNull();
});

test('a range has to run upwards, and experience takes one decimal place', function () {
    actingAsSuperAdmin();

    $this->post(route('recruitment.store'), payPostingForm(['salary_min' => 40000, 'salary_max' => 30000]))
        ->assertSessionHasErrors('salary_max');
    $this->post(route('recruitment.store'), payPostingForm(['min_years_experience' => 1.25]))
        ->assertSessionHasErrors('min_years_experience');
    $this->post(route('recruitment.store'), payPostingForm(['salary_period' => 'weekly']))
        ->assertSessionHasErrors('salary_period');

    expect(JobPosting::count())->toBe(0);
});

test('a site from another workspace cannot be named', function () {
    actingAsSuperAdmin();
    $elsewhere = WorkLocation::withoutGlobalScopes()->create([
        'organization_id' => Organization::factory()->create()->id,
        'name' => 'Not ours', 'latitude' => 1, 'longitude' => 1, 'radius_meters' => 100,
    ]);

    $this->post(route('recruitment.store'), payPostingForm(['work_location_id' => $elsewhere->id]))
        ->assertSessionHasErrors('work_location_id');
});

test('the careers page shows the pay only when the employer chooses to', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $shown = JobPosting::factory()->for($org)->create(['status' => 'open', 'salary_min' => 20000, 'salary_max' => 25000, 'show_salary' => true, 'work_arrangement' => 'remote']);
    $hidden = JobPosting::factory()->for($org)->create(['status' => 'open', 'salary_min' => 50000, 'show_salary' => false]);

    $this->get("/careers/acme/jobs/{$shown->hashid}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('posting.pay', '₱20,000–₱25,000 a month')
            ->where('posting.work_arrangement', 'remote'));

    $this->get("/careers/acme/jobs/{$hidden->hashid}")
        ->assertInertia(fn (Assert $page) => $page->where('posting.pay', null));
});

test('an applicant gives expected pay in the posting’s terms, and half years', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $posting = JobPosting::factory()->for($org)->create(['status' => 'open', 'requires_resume' => false, 'salary_period' => 'daily']);

    $this->post("/careers/acme/jobs/{$posting->hashid}/apply", [
        'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
        'years_experience' => 2.5,
        'expected_salary' => 750,
    ])->assertSessionHasNoErrors();

    $application = JobApplication::withoutGlobalScopes()->firstOrFail();

    expect($application->expected_salary_period)->toBe('daily')
        ->and($application->expectedSalaryLabel())->toBe('₱750 a day')
        ->and(Applicant::withoutGlobalScopes()->firstOrFail()->years_experience)->toBe(2.5);
});

test('a posting that does not ask for expected pay records none', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $posting = JobPosting::factory()->for($org)->create(['status' => 'open', 'requires_resume' => false, 'asks_expected_salary' => false]);

    $this->post("/careers/acme/jobs/{$posting->hashid}/apply", [
        'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
        'expected_salary' => 90000,
    ])->assertSessionHasNoErrors();

    $application = JobApplication::withoutGlobalScopes()->firstOrFail();

    expect($application->expected_salary)->toBeNull()
        ->and($application->expected_salary_period)->toBeNull();
});

test('HR adds expected pay in the posting’s period unless they name another', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create(['salary_period' => 'monthly']);

    $this->post(route('recruitment.applications.store', $posting), [
        'first_name' => 'Ana', 'last_name' => 'Cruz', 'expected_salary' => 30000,
    ])->assertSessionHasNoErrors();

    $this->post(route('recruitment.applications.store', $posting), [
        'first_name' => 'Ben', 'last_name' => 'Uy', 'expected_salary' => 420000, 'expected_salary_period' => 'annual',
    ])->assertSessionHasNoErrors();

    expect(JobApplication::query()->orderBy('id')->pluck('expected_salary_period')->all())->toBe(['monthly', 'annual']);
});

test('half years count toward the experience score', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create(['min_years_experience' => 2]);
    $application = JobApplication::factory()->for($posting)->create();
    $application->applicant->update(['years_experience' => 1.5]);

    $fit = app(ApplicantScorer::class)->score($application->fresh(['applicant', 'jobPosting']));
    $experience = collect($fit['breakdown'])->firstWhere('key', 'experience');

    expect($experience['detail'])->toBe('1.5 yrs (needs 2)')
        ->and($experience['points'])->toBe((int) round(0.75 * $experience['max']));
});
