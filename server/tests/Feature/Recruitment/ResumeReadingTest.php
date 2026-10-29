<?php

use App\Jobs\ReadApplicantFiles;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Services\Assistant\Modules\RecruitmentModule;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| The ATS reads what candidates send and matches skills by meaning (ADR 0097):
| a résumé is read once it arrives, however it arrived; its skills count in the
| fit score; a government ID is never read; files waiting from before are read
| by recruitment:read-files; and postings keep nice-to-haves apart.
*/

/** A real (minimal) Word document holding the given lines. */
function atsWordFile(string $name, string ...$lines): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'ats-docx-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $paragraphs = implode('', array_map(fn (string $line): string => '<w:p><w:r><w:t>'.htmlspecialchars($line, ENT_XML1).'</w:t></w:r></w:p>', $lines));
    $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$paragraphs.'</w:body></w:document>');
    $zip->close();

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
}

/** Store a Word file on the uploads disk and return its path. */
function atsStoredWordFile(string $folder, string ...$lines): string
{
    return atsWordFile('file.docx', ...$lines)->store($folder, 'uploads');
}

/** The `skills` item of an application's fit, as the detail endpoint serves it. */
function atsSkillsOf(JobApplication $application): array
{
    $fit = test()->getJson(route('recruitment.applications.show', $application))->assertOk()->json('data.fit');

    return collect($fit['breakdown'])->firstWhere('key', 'skills');
}

test('a résumé sent on the careers page is read, and what it says counts', function () {
    Storage::fake('uploads');
    $org = Organization::factory()->create(['slug' => 'acme']);
    $posting = JobPosting::factory()->for($org)->create([
        'status' => 'open',
        'skills' => ['JavaScript', 'Java'],
        'preferred_skills' => ['Docker'],
    ]);

    $this->post("/careers/acme/jobs/{$posting->hashid}/apply", [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'email' => 'ana@example.com',
        'resume' => atsWordFile('ana-reyes.docx', 'WORK EXPERIENCE', 'Front-end developer, Acme · Jan 2020 – Present', 'TypeScript, Angular and Docker Compose'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $applicant = Applicant::query()->where('email', 'ana@example.com')->firstOrFail();

    expect($applicant->resume_read_status)->toBe(Applicant::READ_DONE)
        ->and($applicant->resume_text)->toContain('TypeScript, Angular');

    app(Tenancy::class)->set($org);
    actingAsSuperAdmin();

    $skills = atsSkillsOf(JobApplication::query()->where('applicant_id', $applicant->id)->firstOrFail());
    $byName = collect($skills['skills'])->keyBy('skill');

    expect($skills['resume'])->toBe('read')
        ->and($byName['JavaScript']['status'])->toBe('implied')
        ->and($byName['JavaScript']['via'])->toBe('TypeScript')
        ->and($byName['JavaScript']['source'])->toBe('résumé')
        // "Java" is not in "JavaScript", and nothing near Java is there.
        ->and($byName['Java']['status'])->toBe('missing')
        ->and($byName['Docker']['tier'])->toBe('nice')
        ->and($byName['Docker']['status'])->toBe('found')
        ->and($skills['detail'])->toBe('1/2 must-haves · 1/1 nice-to-haves');
});

test('the text read from files never reaches the browser', function () {
    actingAsSuperAdmin();
    $applicant = Applicant::factory()->create();
    $applicant->forceFill(['resume_text' => 'SECRET RÉSUMÉ TEXT', 'documents_text' => 'SECRET DOCUMENT TEXT'])->saveQuietly();
    $application = JobApplication::factory()->create(['applicant_id' => $applicant->id]);

    $json = $this->getJson(route('recruitment.applications.show', $application))->assertOk()->getContent();

    expect($json)->not->toContain('SECRET')
        ->and($applicant->fresh()->toArray())->not->toHaveKey('resume_text');
});

test('a new résumé forgets the old reading, and a removed one leaves nothing behind', function () {
    testOrganization();
    $applicant = Applicant::factory()->create(['resume' => 'applicant-resumes/old.pdf']);
    $applicant->forceFill(['resume_text' => 'Old text', 'resume_read_status' => Applicant::READ_DONE])->saveQuietly();

    $applicant->update(['resume' => 'applicant-resumes/new.pdf']);

    expect($applicant->fresh()->resume_read_status)->toBe(Applicant::READ_PENDING)
        ->and($applicant->fresh()->resume_text)->toBeNull();

    $applicant->update(['resume' => null]);

    expect($applicant->fresh()->resume_read_status)->toBeNull();

    // Anything else changing leaves the reading alone.
    $applicant->forceFill(['resume' => 'applicant-resumes/x.pdf', 'resume_text' => 'Kept', 'resume_read_status' => Applicant::READ_DONE])->saveQuietly();
    $applicant->update(['headline' => 'Accountant']);

    expect($applicant->fresh()->resume_text)->toBe('Kept');
});

test('the reading job reads what is waiting and gathers the documents’ text, never a government ID', function () {
    Storage::fake('uploads');
    $org = testOrganization();

    $applicant = Applicant::factory()->create(['resume' => atsStoredWordFile('applicant-resumes', 'Payroll and BIR filing')]);
    $applicant->documents()->create(['title' => 'Certificate', 'type' => 'certificate', 'file' => atsStoredWordFile('applicant-documents', 'QuickBooks certified')]);
    $id = $applicant->documents()->create(['title' => 'ID', 'type' => 'government_id', 'file' => atsStoredWordFile('applicant-documents', 'PASSPORT P1234567')]);
    $applicant->documents()->create(['title' => 'Broken', 'type' => 'other', 'file' => UploadedFile::fake()->create('broken.pdf', 10, 'application/pdf')->store('applicant-documents', 'uploads')]);

    expect($id->read_status)->toBe(ApplicantDocument::READ_SKIPPED);

    ReadApplicantFiles::dispatchSync($org->id, $applicant->id);

    $applicant->refresh();
    $statuses = $applicant->documents()->orderBy('id')->pluck('read_status')->all();

    expect($applicant->resume_read_status)->toBe(Applicant::READ_DONE)
        ->and($applicant->resume_text)->toContain('BIR filing')
        ->and($applicant->documents_text)->toContain('QuickBooks certified')
        ->and($applicant->documents_text)->not->toContain('PASSPORT')
        ->and($statuses)->toBe([ApplicantDocument::READ_DONE, ApplicantDocument::READ_SKIPPED, ApplicantDocument::READ_UNREADABLE]);
});

test('recruitment:read-files reads files left waiting, and leaves a fresh upload to its own request', function () {
    Storage::fake('uploads');
    testOrganization();

    $waiting = Applicant::factory()->create(['resume' => atsStoredWordFile('applicant-resumes', 'Laravel developer')]);
    Applicant::query()->whereKey($waiting->id)->toBase()->update(['updated_at' => now()->subHour()]);
    $fresh = Applicant::factory()->create(['resume' => atsStoredWordFile('applicant-resumes', 'Vue developer')]);

    app(Tenancy::class)->forget();
    $this->artisan('recruitment:read-files')->assertSuccessful();

    expect($waiting->fresh()->resume_read_status)->toBe(Applicant::READ_DONE)
        ->and($fresh->fresh()->resume_read_status)->toBe(Applicant::READ_PENDING);

    $this->artisan('recruitment:read-files', ['--again' => true])->assertSuccessful();

    expect($fresh->fresh()->resume_read_status)->toBe(Applicant::READ_DONE)
        ->and($fresh->fresh()->resume_text)->toContain('Vue developer');
});

test('the skill catalogue is served to whoever may see recruitment', function () {
    actingAsUserWith(['recruitment.view']);

    $this->getJson(route('recruitment.skills'))
        ->assertOk()
        ->assertJsonStructure(['skills' => [['name', 'aliases', 'proven_by', 'proven_by_count', 'related']], 'qualifiers', 'generic']);

    actingAsUserWith(['employees.view']);

    $this->getJson(route('recruitment.skills'))->assertForbidden();
});

test('a posting keeps its nice-to-have skills apart from its must-haves', function () {
    actingAsSuperAdmin();
    $pipeline = seedDefaultPipeline();

    $this->post(route('recruitment.store'), [
        'title' => 'Platform Engineer',
        'recruitment_pipeline_id' => $pipeline->id,
        'employment_type' => 'regular',
        'openings' => 1,
        'status' => 'draft',
        'skills' => ['Kubernetes', 'Strong knowledge of infrastructure as code'],
        'preferred_skills' => ['Go', 'Terraform'],
    ])->assertSessionHasNoErrors();

    $posting = JobPosting::query()->where('title', 'Platform Engineer')->firstOrFail();

    expect($posting->skills)->toBe(['Kubernetes', 'Strong knowledge of infrastructure as code'])
        ->and($posting->preferred_skills)->toBe(['Go', 'Terraform']);

    $this->post(route('recruitment.update', $posting), [
        'title' => 'Platform Engineer',
        'recruitment_pipeline_id' => $pipeline->id,
        'employment_type' => 'regular',
        'openings' => 1,
        'status' => 'draft',
        'preferred_skills' => array_fill(0, 21, 'Go'),
    ])->assertSessionHasErrors('preferred_skills');
});

test('the pipeline export says which skills each candidate shows and lacks', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create(['skills' => ['JavaScript', 'Docker'], 'use_fit_scoring' => true]);
    $applicant = Applicant::factory()->create(['headline' => 'TypeScript developer', 'notes' => null]);
    JobApplication::factory()->create(['job_posting_id' => $posting->id, 'applicant_id' => $applicant->id, 'cover_note' => null]);

    $csv = $this->get(route('recruitment.pipeline.export', $posting))->assertOk()->streamedContent();
    $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
    $row = array_combine($rows[0], $rows[1]);

    expect($row['Skills shown'])->toBe('JavaScript')
        ->and($row['Skills missing'])->toBe('Docker');
});

test('the assistant creates and edits postings with nice-to-have skills', function () {
    $user = actingAsUserWith(['recruitment.view', 'recruitment.create', 'recruitment.update']);
    seedDefaultPipeline();
    $module = app(RecruitmentModule::class);

    $created = $module->run($user, 'create_job_posting', [
        'title' => 'Data Analyst',
        'status' => 'draft',
        'skills' => ['SQL', 'Excel'],
        'preferred_skills' => 'Power BI, Python',
    ]);

    $posting = JobPosting::query()->where('title', 'Data Analyst')->firstOrFail();

    expect($created->failed())->toBeFalse()
        ->and($posting->preferred_skills)->toBe(['Power BI', 'Python']);

    $module->run($user, 'update_job_posting', ['posting' => 'Data Analyst', 'openings' => 2]);
    expect($posting->fresh()->preferred_skills)->toBe(['Power BI', 'Python']);

    $module->run($user, 'update_job_posting', ['posting' => 'Data Analyst', 'preferred_skills' => ['Tableau']]);
    expect($posting->fresh()->preferred_skills)->toBe(['Tableau']);
});

test('the assistant’s candidate profile names the must-haves a candidate lacks', function () {
    $user = actingAsUserWith(['recruitment.view']);
    $posting = JobPosting::factory()->create(['title' => 'Backend Engineer', 'skills' => ['PHP', 'Docker', 'AWS']]);
    $applicant = Applicant::factory()->create(['first_name' => 'Lito', 'last_name' => 'Garcia', 'headline' => 'Laravel developer', 'notes' => null]);
    JobApplication::factory()->create(['job_posting_id' => $posting->id, 'applicant_id' => $applicant->id, 'cover_note' => null]);

    $result = app(RecruitmentModule::class)->run($user, 'candidate_profile', ['candidate' => 'Lito Garcia']);
    $meta = implode(' | ', $result->cards[0]['meta'] ?? []);

    expect($result->failed())->toBeFalse()
        ->and($meta)->toContain('Skills 1/3 skills matched')
        ->and($meta)->toContain('missing Docker, AWS');
});
