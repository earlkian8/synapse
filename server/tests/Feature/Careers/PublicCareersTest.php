<?php

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The public, unauthenticated careers surface: an org board, per-posting pages,
| and the application form. These run as a guest — no tenant is bound — so the
| controller must resolve the organisation from the URL and stamp submissions
| with it. See App\Http\Controllers\Public\CareersController.
*/

function openPosting(Organization $org, array $attributes = []): JobPosting
{
    return JobPosting::factory()->for($org)->create([
        'status' => 'open',
        ...$attributes,
    ]);
}

// ── Browsing ────────────────────────────────────────────────────────────────

test('the public board lists only open postings for the organisation', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    openPosting($org, ['title' => 'Open Role']);
    JobPosting::factory()->for($org)->create(['status' => 'draft', 'title' => 'Hidden Draft']);

    $this->get('/careers/acme')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('careers/index')
            ->where('organization.name', $org->name)
            ->has('postings', 1)
            ->where('postings.0.title', 'Open Role'));
});

test('an open posting page renders its application form', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $posting = openPosting($org, ['title' => 'Backend Engineer']);

    $this->get("/careers/acme/jobs/{$posting->hashid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('careers/show')
            ->where('posting.title', 'Backend Engineer')
            ->has('documentTypes'));
});

test('a non-open posting is not publicly visible', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $posting = JobPosting::factory()->for($org)->create(['status' => 'draft']);

    $this->get("/careers/acme/jobs/{$posting->hashid}")->assertNotFound();
});

test('a posting cannot be reached through another organisation slug', function () {
    $orgA = Organization::factory()->create(['slug' => 'acme']);
    $orgB = Organization::factory()->create(['slug' => 'globex']);
    $posting = openPosting($orgA);

    $this->get("/careers/globex/jobs/{$posting->hashid}")->assertNotFound();
});

test('the bare /careers landing redirects only when a single organisation exists', function () {
    $org = Organization::factory()->create(['slug' => 'solo']);

    $this->get('/careers')->assertRedirect('/careers/solo');

    Organization::factory()->create();

    $this->get('/careers')->assertNotFound();
});

// ── Applying ──────────────────────────────────────────────────────────────────

test('a guest can apply with a résumé and supporting documents', function () {
    Storage::fake('public');
    $org = Organization::factory()->create(['slug' => 'acme']);
    $posting = openPosting($org);

    $this->post("/careers/acme/jobs/{$posting->hashid}/apply", [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'current_location' => 'Cebu City',
        'years_experience' => 4,
        'cover_note' => 'Excited to apply.',
        'resume' => UploadedFile::fake()->create('cv.pdf', 120, 'application/pdf'),
        'documents' => [
            ['type' => 'cover_letter', 'file' => UploadedFile::fake()->create('cover.pdf', 50, 'application/pdf')],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $applicant = Applicant::where('email', 'jane@example.com')->first();
    expect($applicant)->not->toBeNull()
        ->and($applicant->organization_id)->toBe($org->id)
        ->and($applicant->source)->toBe('website')
        ->and($applicant->current_location)->toBe('Cebu City')
        ->and($applicant->resume)->not->toBeNull()
        ->and($applicant->documents()->count())->toBe(1);

    $application = JobApplication::where('applicant_id', $applicant->id)->first();
    expect($application)->not->toBeNull()
        ->and($application->job_posting_id)->toBe($posting->id)
        ->and($application->stage)->toBe('applied')
        ->and($application->organization_id)->toBe($org->id);
});

test('a public application requires a résumé', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $posting = openPosting($org);

    $this->post("/careers/acme/jobs/{$posting->hashid}/apply", [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
    ])->assertSessionHasErrors('resume');

    expect(Applicant::where('email', 'jane@example.com')->exists())->toBeFalse();
});

test('a filled honeypot is silently dropped without creating an application', function () {
    Storage::fake('public');
    $org = Organization::factory()->create(['slug' => 'acme']);
    $posting = openPosting($org);

    $this->post("/careers/acme/jobs/{$posting->hashid}/apply", [
        'first_name' => 'Bot',
        'last_name' => 'Spammer',
        'email' => 'bot@example.com',
        'resume' => UploadedFile::fake()->create('cv.pdf', 120, 'application/pdf'),
        'hp_field' => 'http://spam.example',
    ])->assertRedirect();

    expect(Applicant::where('email', 'bot@example.com')->exists())->toBeFalse();
});

test('applying twice with the same email does not duplicate the application', function () {
    Storage::fake('public');
    $org = Organization::factory()->create(['slug' => 'acme']);
    $posting = openPosting($org);

    $payload = fn () => [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'resume' => UploadedFile::fake()->create('cv.pdf', 120, 'application/pdf'),
    ];

    $this->post("/careers/acme/jobs/{$posting->hashid}/apply", $payload())->assertRedirect();
    $this->post("/careers/acme/jobs/{$posting->hashid}/apply", $payload())->assertRedirect();

    $applicant = Applicant::where('email', 'jane@example.com')->first();
    expect(JobApplication::where('applicant_id', $applicant->id)->count())->toBe(1);
});
