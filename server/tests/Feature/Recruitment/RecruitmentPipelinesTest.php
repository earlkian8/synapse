<?php

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Models\RecruitmentPipeline;
use App\Support\Tenancy;

/*
| Configurable hiring pipelines (ADR 0029) — the setup surface that replaces
| the old system-wide hardcoded 6-stage list, plus the end-to-end behaviour a
| fully custom pipeline (different stage names, a different length) needs to
| get right: entry stage, advancing, hiring, rejecting, and the fit-scoring
| opt-out. See App\Models\RecruitmentPipeline, App\Http\Controllers\Recruitment\RecruitmentPipelineController.
*/

// ── Setup: creating & editing pipelines ─────────────────────────────────────────

test('a pipeline is created with its ordered stages', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.recruitment-pipelines.store'), [
        'name' => 'Warehouse Hiring',
        'stages' => [
            ['name' => 'Application Received', 'kind' => 'open'],
            ['name' => 'Physical Assessment', 'kind' => 'open'],
            ['name' => 'Trial Shift', 'kind' => 'open'],
            ['name' => 'Hired', 'kind' => 'won'],
            ['name' => 'Not Selected', 'kind' => 'lost'],
        ],
    ])->assertSessionHasNoErrors();

    $pipeline = RecruitmentPipeline::where('name', 'Warehouse Hiring')->first();

    expect($pipeline)->not->toBeNull()
        ->and($pipeline->stages)->toHaveCount(5)
        ->and($pipeline->stages->pluck('name')->all())->toBe([
            'Application Received', 'Physical Assessment', 'Trial Shift', 'Hired', 'Not Selected',
        ])
        ->and($pipeline->wonStage()->name)->toBe('Hired')
        ->and($pipeline->defaultLostStage()->name)->toBe('Not Selected');
});

test('a pipeline needs exactly one hired stage and at least one rejected stage', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.recruitment-pipelines.store'), [
        'name' => 'Broken Pipeline',
        'stages' => [
            ['name' => 'Applied', 'kind' => 'open'],
            ['name' => 'Hired', 'kind' => 'won'],
            ['name' => 'Also Hired', 'kind' => 'won'],
        ],
    ])->assertSessionHasErrors('stages');

    expect(RecruitmentPipeline::where('name', 'Broken Pipeline')->exists())->toBeFalse();
});

test('the first pipeline an organisation creates becomes the default automatically', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.recruitment-pipelines.store'), [
        'name' => 'Only Pipeline',
        'is_default' => false,
        'stages' => [
            ['name' => 'Applied', 'kind' => 'open'],
            ['name' => 'Hired', 'kind' => 'won'],
            ['name' => 'Rejected', 'kind' => 'lost'],
        ],
    ]);

    expect(RecruitmentPipeline::where('name', 'Only Pipeline')->first()->is_default)->toBeTrue();
});

test('marking a second pipeline default un-defaults the first', function () {
    actingAsSuperAdmin();
    $first = seedDefaultPipeline();

    $this->post(route('setup.recruitment-pipelines.store'), [
        'name' => 'Second Pipeline',
        'is_default' => true,
        'stages' => [
            ['name' => 'Applied', 'kind' => 'open'],
            ['name' => 'Hired', 'kind' => 'won'],
            ['name' => 'Rejected', 'kind' => 'lost'],
        ],
    ]);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and(RecruitmentPipeline::where('name', 'Second Pipeline')->first()->is_default)->toBeTrue();
});

test('editing a pipeline can drop a stage that has no applications on it', function () {
    actingAsSuperAdmin();
    $pipeline = seedDefaultPipeline();
    $keep = $pipeline->stages->reject(fn ($s) => $s->name === 'Offer')->values();

    $this->post(route('setup.recruitment-pipelines.update', $pipeline), [
        'name' => $pipeline->name,
        'is_default' => true,
        'stages' => $keep->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'kind' => $s->kind])->all(),
    ])->assertSessionHasNoErrors();

    expect($pipeline->fresh()->stages->pluck('name')->all())->not->toContain('Offer');
});

test('editing a pipeline cannot drop a stage that still has candidates on it', function () {
    actingAsSuperAdmin();
    $pipeline = seedDefaultPipeline();
    $screening = $pipeline->stages->firstWhere('name', 'Screening');
    JobApplication::factory()->stage('screening')->create();

    $keep = $pipeline->stages->reject(fn ($s) => $s->id === $screening->id)->values();

    $this->post(route('setup.recruitment-pipelines.update', $pipeline), [
        'name' => $pipeline->name,
        'is_default' => true,
        'stages' => $keep->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'kind' => $s->kind])->all(),
    ]);

    expect($pipeline->fresh()->stages->pluck('name')->all())->toContain('Screening');
});

test('a pipeline still in use by a posting cannot be deleted', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create();

    $this->delete(route('setup.recruitment-pipelines.destroy', $posting->recruitment_pipeline_id));

    expect(RecruitmentPipeline::find($posting->recruitment_pipeline_id))->not->toBeNull();
});

test('deleting the default pipeline promotes another one', function () {
    actingAsSuperAdmin();
    $default = seedDefaultPipeline();
    $other = RecruitmentPipeline::factory()->withStandardStages()->create(['name' => 'Backup Pipeline']);

    $this->delete(route('setup.recruitment-pipelines.destroy', $default));

    expect(RecruitmentPipeline::find($default->id))->toBeNull()
        ->and($other->fresh()->is_default)->toBeTrue();
});

test('configuring pipelines is permission gated', function () {
    actingAsUserWith(['recruitment.view']);

    $this->get(route('setup.recruitment-pipelines.index'))->assertForbidden();
});

test('pipelines are isolated per organisation', function () {
    actingAsSuperAdmin();
    seedDefaultPipeline();

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => RecruitmentPipeline::factory()->withStandardStages()->create());

    $this->get(route('setup.recruitment-pipelines.index'))
        ->assertInertia(fn ($page) => $page->has('pipelines', 1));
});

// ── A fully custom pipeline, end to end ─────────────────────────────────────────

test('a posting on a custom pipeline moves through its own named stages, then hires', function () {
    actingAsSuperAdmin();

    $pipeline = RecruitmentPipeline::factory()->create(['name' => 'Warehouse Hiring']);
    $pipeline->syncStages([
        ['name' => 'Application Received', 'kind' => 'open'],
        ['name' => 'Physical Assessment', 'kind' => 'open'],
        ['name' => 'Trial Shift', 'kind' => 'open'],
        ['name' => 'Hired', 'kind' => 'won'],
        ['name' => 'Not Selected', 'kind' => 'lost'],
    ]);

    $posting = JobPosting::factory()->create([
        'recruitment_pipeline_id' => $pipeline->id,
        'requires_resume' => false,
        'status' => 'open',
    ]);

    // A candidate enters at the pipeline's own first stage — not "Applied".
    $applicant = Applicant::factory()->create();
    $this->post(route('recruitment.applications.store', $posting), ['applicant_id' => $applicant->id])
        ->assertSessionHasNoErrors();

    $application = $posting->applications()->first();
    expect($application->pipelineStage->name)->toBe('Application Received');

    // Move forward through the custom stages.
    $physical = $pipeline->stages->firstWhere('name', 'Physical Assessment');
    $this->patch(route('recruitment.applications.stage', $application), ['stage_id' => $physical->id])
        ->assertSessionHasNoErrors();
    expect($application->fresh()->pipelineStage->name)->toBe('Physical Assessment');

    $trial = $pipeline->stages->firstWhere('name', 'Trial Shift');
    $this->patch(route('recruitment.applications.stage', $application), ['stage_id' => $trial->id])
        ->assertSessionHasNoErrors();
    expect($application->fresh()->pipelineStage->name)->toBe('Trial Shift');

    // Hiring moves it to the pipeline's own "won" stage, whatever it's called.
    $this->post(route('recruitment.applications.hire', $application))->assertSessionHasNoErrors();
    expect($application->fresh()->pipelineStage->name)->toBe('Hired')
        ->and($application->fresh()->hired_employee_id)->not->toBeNull();
});

test('a posting with fit scoring turned off carries no fit or recommendation', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create(['use_fit_scoring' => false]);
    $application = JobApplication::factory()->create(['job_posting_id' => $posting->id]);

    $this->get(route('recruitment.applications.show', $application))
        ->assertOk()
        ->assertJsonPath('data.fit', null)
        ->assertJsonPath('data.recommendation', null);
});

test('a posting with fit scoring on carries a fit score and recommendation', function () {
    actingAsSuperAdmin();
    $posting = JobPosting::factory()->create(['use_fit_scoring' => true]);
    $application = JobApplication::factory()->create(['job_posting_id' => $posting->id, 'rating' => 4]);

    $this->get(route('recruitment.applications.show', $application))
        ->assertOk()
        ->assertJsonPath('data.recommendation.action', 'advance');
});
