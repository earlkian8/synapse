<?php

use App\Models\Applicant;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Support\Recruitment\ApplicantScorer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Build an in-memory application with its relations set, so the scorer can be
 * exercised without a database (mirrors the ApplicantModelTest approach).
 *
 * @param  array<string, mixed>  $application
 * @param  array<string, mixed>  $applicant
 * @param  list<string>  $interviewResults
 */
function makeApplication(
    array $application,
    array $applicant = [],
    ?JobPosting $posting = null,
    array $interviewResults = [],
    int $documentsCount = 0,
): JobApplication {
    $applicantModel = new Applicant($applicant);
    $applicantModel->setAttribute('documents_count', $documentsCount);

    $model = new JobApplication($application);
    $model->setRelation('applicant', $applicantModel);
    $model->setRelation('jobPosting', $posting ?? new JobPosting);
    $model->setRelation('interviews', new Collection(
        array_map(fn (string $result): Interview => new Interview(['result' => $result]), $interviewResults),
    ));

    return $model;
}

test('a strong candidate scores high and is recommended forward', function () {
    $posting = new JobPosting(['min_years_experience' => 3, 'skills' => ['php', 'laravel', 'react', 'sql']]);
    $application = makeApplication(
        application: ['stage' => 'interview', 'rating' => 4],
        applicant: ['headline' => 'Senior PHP / Laravel engineer', 'years_experience' => 6, 'resume' => 'r.pdf', 'notes' => 'react and sql'],
        posting: $posting,
        interviewResults: ['passed'],
        documentsCount: 2,
    );

    $scorer = new ApplicantScorer;
    $score = $scorer->score($application, $posting);

    expect($score['value'])->toBeGreaterThanOrEqual(75)
        ->and($score['band'])->toBe('strong');

    expect($scorer->recommendation($application, $score)['action'])->toBe('offer');
});

test('an unrated brand-new applicant normalises over the signals that exist', function () {
    $application = makeApplication(
        application: ['stage' => 'applied', 'rating' => null],
        applicant: ['resume' => 'r.pdf'],
        documentsCount: 0,
    );

    $scorer = new ApplicantScorer;
    $score = $scorer->score($application, $application->jobPosting);

    // Only rating (0/30) + documents (6/10) apply → 6/40 = 15.
    expect($score['value'])->toBe(15)
        ->and($score['breakdown'])->toHaveCount(2)
        ->and($scorer->recommendation($application, $score)['action'])->toBe('screening');
});

test('a weak screening candidate is recommended for rejection', function () {
    $application = makeApplication(
        application: ['stage' => 'screening', 'rating' => 1],
        applicant: ['resume' => 'r.pdf'],
    );

    $scorer = new ApplicantScorer;
    $score = $scorer->score($application, $application->jobPosting);

    expect($scorer->recommendation($application, $score)['action'])->toBe('reject');
});

test('a failed interview blocks the offer recommendation', function () {
    $application = makeApplication(
        application: ['stage' => 'interview', 'rating' => 5],
        applicant: ['resume' => 'r.pdf'],
        interviewResults: ['failed'],
    );

    $scorer = new ApplicantScorer;
    $score = $scorer->score($application, $application->jobPosting);

    expect($scorer->recommendation($application, $score)['action'])->toBe('reject');
});
