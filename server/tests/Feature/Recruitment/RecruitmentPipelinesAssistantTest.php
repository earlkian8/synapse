<?php

use App\Models\ActivityLog;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Models\RecruitmentPipeline;
use App\Models\User;
use App\Services\Assistant\Modules\RecruitmentPipelinesModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Tenancy;

/*
| The recruitment-pipelines capability of the assistant: the hiring processes,
| changed stage by stage by the screen's own rules — a kept stage keeps its
| id, so the candidates on it stay where they are. Gemini is never called.
*/

function pipelinesAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(RecruitmentPipelinesModule::class)->run($user, $tool, $args);
}

/** The default pipeline every posting falls back to, under a known name. */
function standardHiring(): RecruitmentPipeline
{
    $pipeline = seedDefaultPipeline();
    $pipeline->update(['name' => 'Standard Hiring']);

    return $pipeline->load('stages');
}

/** @return list<string> */
function stageNames(RecruitmentPipeline $pipeline): array
{
    return $pipeline->stages()->pluck('name')->all();
}

test('only those who configure pipelines get the tools; removing and deleting wait for a confirm', function () {
    $module = app(RecruitmentPipelinesModule::class);

    expect($module->isAvailable(actingAsUserWith(['recruitment.view'])))->toBeFalse()
        ->and(array_column($module->tools(actingAsUserWith(['recruitment.configure-pipelines'])), 'name'))
        ->toContain('find_pipelines', 'create_pipeline', 'set_pipeline_stage', 'delete_pipeline')
        ->and($module->requiresConfirmation('remove_pipeline_stage'))->toBeTrue()
        ->and($module->requiresConfirmation('delete_pipeline'))->toBeTrue()
        ->and($module->requiresConfirmation('set_pipeline_stage'))->toBeFalse();

    expect(pipelinesAgent(actingAsUserWith(['recruitment.view']), 'find_pipelines')->detail)->toContain('permission');
});

test('a pipeline reads out stage by stage, with the candidates on each and its postings', function () {
    $user = actingAsSuperAdmin();
    standardHiring();
    $posting = JobPosting::factory()->create(['title' => 'Warehouse Picker']);
    JobApplication::factory()->count(2)->stage('screening')->create(['job_posting_id' => $posting->id]);

    $meta = implode(' | ', pipelinesAgent($user, 'get_pipeline', ['pipeline' => 'standard'])->cards[0]['meta']);

    expect($meta)->toContain('2. Screening (in progress) — 2 candidates')
        ->toContain('5. Hired (hired) — 0 candidates')
        ->toContain('Postings: Warehouse Picker');

    expect(app(Retriever::class)->retrieve($user, 'what hiring stages do we use?')?->toPrompt())
        ->toContain('Applied → Screening → Interview → Offer → Hired | rejected: Rejected');
});

test('a pipeline is made from its steps or a copy, by the screen’s rules', function () {
    $user = actingAsSuperAdmin();
    standardHiring();

    $steps = pipelinesAgent($user, 'create_pipeline', ['name' => 'Frontline', 'steps' => ['Applied', 'Trial shift'], 'rejected_stages' => ['Not proceeding', 'Withdrew']]);
    $copy = pipelinesAgent($user, 'create_pipeline', ['name' => 'Sales Hiring', 'copy_from' => 'standard hiring']);
    $neither = pipelinesAgent($user, 'create_pipeline', ['name' => 'Empty']);
    $taken = pipelinesAgent($user, 'create_pipeline', ['name' => 'frontline', 'steps' => ['Applied']]);
    $noHire = pipelinesAgent($user, 'create_pipeline', ['name' => 'Odd', 'steps' => ['Applied'], 'hired_stage' => ' ']);

    expect($steps->failed())->toBeFalse()
        ->and(stageNames(RecruitmentPipeline::query()->where('name', 'Frontline')->firstOrFail()))->toBe(['Applied', 'Trial shift', 'Hired', 'Not proceeding', 'Withdrew'])
        ->and(stageNames(RecruitmentPipeline::query()->where('name', 'Sales Hiring')->firstOrFail()))->toBe(['Applied', 'Screening', 'Interview', 'Offer', 'Hired', 'Rejected'])
        ->and($copy->failed())->toBeFalse()
        ->and($neither->failed())->toBeTrue()
        ->and($taken->detail)->toContain('already a pipeline called')
        ->and($noHire->failed())->toBeFalse()
        ->and(RecruitmentPipeline::query()->where('is_default', true)->value('name'))->toBe('Standard Hiring')
        ->and(ActivityLog::query()->where('description', 'Created recruitment pipeline "Frontline" via assistant')->exists())->toBeTrue();
});

test('a stage is added, renamed and moved — and the candidates on kept stages stay where they are', function () {
    $user = actingAsSuperAdmin();
    $pipeline = standardHiring();
    $application = JobApplication::factory()->stage('screening')->create();
    $screening = $application->recruitment_pipeline_stage_id;

    $added = pipelinesAgent($user, 'set_pipeline_stage', ['pipeline' => 'Standard Hiring', 'stage' => 'Assessment', 'after' => 'Screening']);
    $renamed = pipelinesAgent($user, 'set_pipeline_stage', ['pipeline' => 'Standard Hiring', 'stage' => 'Screening', 'new_name' => 'Phone screen']);
    $moved = pipelinesAgent($user, 'set_pipeline_stage', ['pipeline' => 'Standard Hiring', 'stage' => 'Offer', 'first' => true]);
    $rejectedLast = pipelinesAgent($user, 'set_pipeline_stage', ['pipeline' => 'Standard Hiring', 'stage' => 'Withdrew', 'kind' => 'lost']);
    $rekind = pipelinesAgent($user, 'set_pipeline_stage', ['pipeline' => 'Standard Hiring', 'stage' => 'Interview', 'kind' => 'lost']);
    $clash = pipelinesAgent($user, 'set_pipeline_stage', ['pipeline' => 'Standard Hiring', 'stage' => 'Interview', 'new_name' => 'phone screen']);

    expect($added->failed() || $renamed->failed() || $moved->failed() || $rejectedLast->failed())->toBeFalse()
        ->and(stageNames($pipeline))->toBe(['Offer', 'Applied', 'Phone screen', 'Assessment', 'Interview', 'Hired', 'Rejected', 'Withdrew'])
        ->and($application->fresh()->recruitment_pipeline_stage_id)->toBe($screening)
        ->and($rekind->detail)->toContain('changed on the Recruitment Pipelines screen')
        ->and($clash->detail)->toContain('already has a stage called');
});

test('a stage candidates sit on is not removed, an empty one is, and the card says so first', function () {
    $user = actingAsSuperAdmin();
    $pipeline = standardHiring();
    JobApplication::factory()->stage('screening')->create();
    $module = app(RecruitmentPipelinesModule::class);

    expect($module->consequence($user, 'remove_pipeline_stage', ['pipeline' => 'Standard Hiring', 'stage' => 'Screening']))->toContain('1 candidate sit on it, so it will be refused')
        ->and(pipelinesAgent($user, 'remove_pipeline_stage', ['pipeline' => 'Standard Hiring', 'stage' => 'Screening'])->detail)->toContain('still have candidates on them')
        ->and(pipelinesAgent($user, 'remove_pipeline_stage', ['pipeline' => 'Standard Hiring', 'stage' => 'Offer'])->failed())->toBeFalse()
        ->and(pipelinesAgent($user, 'remove_pipeline_stage', ['pipeline' => 'Standard Hiring', 'stage' => 'Hired'])->detail)->toBe('A pipeline needs exactly one "Hired" stage.')
        ->and(stageNames($pipeline))->toBe(['Applied', 'Screening', 'Interview', 'Hired', 'Rejected']);
});

test('the default moves only to another pipeline, and a pipeline postings run on is not deleted', function () {
    $user = actingAsSuperAdmin();
    standardHiring();
    pipelinesAgent($user, 'create_pipeline', ['name' => 'Frontline', 'steps' => ['Applied']]);
    JobPosting::factory()->create();

    pipelinesAgent($user, 'update_pipeline', ['pipeline' => 'Frontline', 'make_default' => true]);
    $inUse = pipelinesAgent($user, 'delete_pipeline', ['pipeline' => 'Standard Hiring']);
    $unused = pipelinesAgent($user, 'delete_pipeline', ['pipeline' => 'Frontline']);

    expect($inUse->detail)->toContain('in use by one or more job postings')
        ->and($unused->failed())->toBeFalse()
        ->and(RecruitmentPipeline::query()->where('is_default', true)->value('name'))->toBe('Standard Hiring');
});

test('another workspace’s pipelines are never found or changed', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();
    standardHiring();

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => RecruitmentPipeline::factory()->withStandardStages()->create(['name' => 'Their Hiring']));
    app(Tenancy::class)->set($mine);

    expect(pipelinesAgent($user, 'get_pipeline', ['pipeline' => 'Their Hiring'])->failed())->toBeTrue()
        ->and(pipelinesAgent($user, 'delete_pipeline', ['pipeline' => 'Their Hiring'])->failed())->toBeTrue()
        ->and(array_column(pipelinesAgent($user, 'find_pipelines')->cards, 'title'))->toBe(['Standard Hiring']);
});
