<?php

use App\Models\Applicant;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\RecruitmentPipeline;
use App\Models\RecruitmentPipelineStage;
use App\Support\Recruitment\ApplicantScorer;
use Illuminate\Database\Eloquent\Collection;

/**
 * An in-memory standard 6-stage pipeline (Applied → Screening → Interview →
 * Offer → Hired/Rejected) — the classic shape every pre-existing organisation
 * was backfilled onto. Built without a database so the scorer can be exercised
 * in isolation (mirrors the ApplicantModelTest approach).
 */
function makeStandardPipeline(): RecruitmentPipeline
{
    $definitions = [
        ['name' => 'Applied', 'kind' => 'open', 'position' => 0],
        ['name' => 'Screening', 'kind' => 'open', 'position' => 1],
        ['name' => 'Interview', 'kind' => 'open', 'position' => 2],
        ['name' => 'Offer', 'kind' => 'open', 'position' => 3],
        ['name' => 'Hired', 'kind' => 'won', 'position' => 4],
        ['name' => 'Rejected', 'kind' => 'lost', 'position' => 5],
    ];

    $stages = new Collection(array_map(
        fn (array $definition, int $index): RecruitmentPipelineStage => (new RecruitmentPipelineStage($definition))->forceFill(['id' => $index + 1]),
        $definitions,
        array_keys($definitions),
    ));

    $pipeline = (new RecruitmentPipeline)->forceFill(['id' => 1]);
    $pipeline->setRelation('stages', $stages);

    return $pipeline;
}

/**
 * Build an in-memory application with its relations set, so the scorer can be
 * exercised without a database.
 *
 * @param  array<string, mixed>  $application
 * @param  array<string, mixed>  $applicant
 * @param  list<string>  $interviewResults
 */
function makeApplication(
    string $stageName,
    array $application = [],
    array $applicant = [],
    ?JobPosting $posting = null,
    array $interviewResults = [],
    int $documentsCount = 0,
): JobApplication {
    $pipeline = makeStandardPipeline();
    $stage = $pipeline->stages->firstWhere('name', $stageName);

    $posting ??= new JobPosting;
    $posting->setRelation('pipeline', $pipeline);

    $applicantModel = new Applicant($applicant);
    $applicantModel->setAttribute('documents_count', $documentsCount);

    $model = new JobApplication($application);
    $model->setRelation('applicant', $applicantModel);
    $model->setRelation('jobPosting', $posting);
    $model->setRelation('pipelineStage', $stage);
    $model->setRelation('interviews', new Collection(
        array_map(fn (string $result): Interview => new Interview(['result' => $result]), $interviewResults),
    ));

    return $model;
}

test('a strong candidate scores high and is recommended to advance to offer', function () {
    $posting = new JobPosting(['min_years_experience' => 3, 'skills' => ['php', 'laravel', 'react', 'sql']]);
    $application = makeApplication(
        stageName: 'Interview',
        application: ['rating' => 4],
        applicant: ['headline' => 'Senior PHP / Laravel engineer', 'years_experience' => 6, 'resume' => 'r.pdf', 'notes' => 'react and sql'],
        posting: $posting,
        interviewResults: ['passed'],
        documentsCount: 2,
    );

    $scorer = new ApplicantScorer;
    $score = $scorer->score($application, $posting);

    expect($score['value'])->toBeGreaterThanOrEqual(75)
        ->and($score['band'])->toBe('strong');

    $recommendation = $scorer->recommendation($application, $score);
    $offerStage = $posting->pipeline->stages->firstWhere('name', 'Offer');

    expect($recommendation['action'])->toBe('advance')
        ->and($recommendation['stage_id'])->toBe($offerStage->id)
        ->and($recommendation['label'])->toBe('Advance to Offer');
});

test('an unrated brand-new applicant normalises over the signals that exist', function () {
    $application = makeApplication(
        stageName: 'Applied',
        application: ['rating' => null],
        applicant: ['resume' => 'r.pdf'],
        documentsCount: 0,
    );

    $scorer = new ApplicantScorer;
    $score = $scorer->score($application, $application->jobPosting);

    // Only rating (0/30) + documents (6/10) apply → 6/40 = 15.
    expect($score['value'])->toBe(15)
        ->and($score['breakdown'])->toHaveCount(2);

    $recommendation = $scorer->recommendation($application, $score);
    $screeningStage = $application->jobPosting->pipeline->stages->firstWhere('name', 'Screening');

    // Still moving forward — an entry-stage candidate is never recommended for
    // rejection outright, just screened, regardless of fit.
    expect($recommendation['action'])->toBe('advance')
        ->and($recommendation['stage_id'])->toBe($screeningStage->id)
        ->and($recommendation['label'])->toBe('Screen this candidate');
});

test('a weak screening candidate is recommended for rejection', function () {
    $application = makeApplication(
        stageName: 'Screening',
        application: ['rating' => 1],
        applicant: ['resume' => 'r.pdf'],
    );

    $scorer = new ApplicantScorer;
    $score = $scorer->score($application, $application->jobPosting);

    expect($scorer->recommendation($application, $score)['action'])->toBe('reject');
});

test('a failed interview blocks the offer recommendation', function () {
    $application = makeApplication(
        stageName: 'Interview',
        application: ['rating' => 5],
        applicant: ['resume' => 'r.pdf'],
        interviewResults: ['failed'],
    );

    $scorer = new ApplicantScorer;
    $score = $scorer->score($application, $application->jobPosting);

    expect($scorer->recommendation($application, $score)['action'])->toBe('reject');
});

test('the last open stage recommends hiring, not advancing', function () {
    $application = makeApplication(
        stageName: 'Offer',
        application: ['rating' => 5],
        applicant: ['resume' => 'r.pdf'],
        interviewResults: ['passed'],
    );

    $scorer = new ApplicantScorer;
    $score = $scorer->score($application, $application->jobPosting);

    expect($scorer->recommendation($application, $score)['action'])->toBe('hire');
});

test('a posting that does not require a résumé does not penalise a candidate for lacking one', function () {
    $posting = new JobPosting(['requires_resume' => false]);
    $application = makeApplication(
        stageName: 'Applied',
        application: ['rating' => null],
        applicant: [], // no résumé
        posting: $posting,
        documentsCount: 0,
    );

    $scorer = new ApplicantScorer;
    $score = $scorer->score($application, $posting);
    $documents = collect($score['breakdown'])->firstWhere('key', 'documents');

    expect($documents['points'])->toBe(6)
        ->and($documents['detail'])->toBe('No résumé required');
});
