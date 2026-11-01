<?php

use App\Models\AttritionRiskRun;
use App\Models\AttritionRiskScore;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecast;
use App\Models\PerformanceForecastRun;
use App\Models\PromotionReadinessRun;
use App\Models\PromotionReadinessScore;
use App\Services\Assistant\Modules\AttritionRiskModule;
use App\Support\Ml\PredictionExplanation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The predictive screens say why (ADR 0098). Each score keeps the explanation the
 * inference service gave with it — the typical record's score its reasons add up
 * from, what the next appraisal would do, the chance of every band — and each run
 * keeps what its scores were read against. The service is faked at the HTTP
 * boundary; everything on the Laravel side runs for real.
 */

/** The reference an attrition response carries: the typical employee and the survey. */
function explainedAttritionReference(): array
{
    return [
        'baseline' => 22.1,
        'typical' => ['absences_90d' => ['value' => 1.5, 'band' => '1–2 days']],
        'tiers' => ['medium_from' => 33.0, 'high_from' => 66.0],
        'evidence' => [
            'source' => 'survey', 'people' => 199, 'left' => 55, 'overall_rate' => 0.2764,
            'features' => ['absences_90d' => [
                ['band' => 'none', 'people' => 49, 'left' => 11, 'rate' => 0.2245],
                ['band' => '6–10 days', 'people' => 15, 'left' => 8, 'rate' => 0.5333],
            ]],
        ],
    ];
}

/**
 * Fake the services: each model answers with explanations unless `$explained` is
 * false (an inference service from before ADR 0098).
 */
function fakeExplainedServices(bool $explained = true): void
{
    $health = Http::response([
        'status' => 'ok',
        'service' => 'synapse-ml-inference',
        'models' => collect(['attrition', 'promotion', 'performance'])
            ->mapWithKeys(fn (string $m): array => [$m => ['kind' => 'classifier', 'version' => null, 'feature_count' => 1, 'metrics' => []]])->all(),
    ]);

    Http::fake([
        '*/health' => $health,
        '*/predict/attrition' => fn (Request $request) => Http::response([
            'model' => 'attrition', 'model_version' => 'SVC@test',
            'results' => collect($request->data()['instances'])->map(fn (array $i): array => [
                'ref' => $i['ref'], 'probability' => 0.58, 'score' => 58.0, 'tier' => 'medium',
                'factors' => [[
                    'feature' => 'absences_90d', 'label' => 'Absences (last 90 days)', 'impact' => 0.359, 'direction' => 'up',
                    'value' => 7, 'typical' => 1.5, 'band' => '6–10 days', 'typical_band' => '1–2 days',
                    'evidence' => ['rate' => 0.5333, 'people' => 15, 'typical_rate' => 0.2581, 'typical_people' => 62, 'overall_rate' => 0.2764, 'consistent' => true],
                ]],
                ...($explained ? ['baseline' => 22.1] : []),
            ])->all(),
            ...($explained ? ['reference' => explainedAttritionReference()] : []),
        ]),
        '*/predict/promotion' => fn (Request $request) => Http::response([
            'model' => 'promotion', 'model_version' => 'LR@test',
            'results' => collect($request->data()['instances'])->map(fn (array $i): array => [
                'ref' => $i['ref'], 'status' => 'scored', 'probability' => 0.12, 'score' => 71.2, 'tier' => 'medium',
                'basis' => 'latest_appraisal',
                'factors' => [['feature' => 'rating_latest', 'label' => 'Latest appraisal', 'impact' => 6.4, 'direction' => 'up', 'value' => 76.0, 'typical' => 69.98]],
                'baseline' => 64.8,
                'outlook' => ['points' => [['rating' => 40.0, 'score' => 0.0], ['rating' => 100.0, 'score' => 99.6]], 'medium_from' => 80.0, 'high_from' => 83.0, 'same_again' => 42.6],
                'warnings' => [],
            ])->all(),
            'reference' => ['base_rate' => 0.1, 'typical' => ['rating_latest' => 69.98, 'rating_change' => 1.83], 'tier_scores' => ['high' => 82.8, 'medium' => 67.2]],
        ]),
        '*/predict/performance' => fn (Request $request) => Http::response([
            'model' => 'performance', 'model_version' => 'HGB@test',
            'results' => collect($request->data()['instances'])->map(fn (array $i): array => [
                'ref' => $i['ref'], 'status' => 'scored', 'score' => 87.2,
                'interval' => ['low' => 80.2, 'high' => 94.8, 'coverage' => 0.8], 'band' => 'exceeds', 'confidence' => 0.906,
                'chances' => ['below' => 0.0, 'on_track' => 0.094, 'exceeds' => 0.906],
                'basis' => 'latest_appraisal', 'warnings' => [],
            ])->all(),
            'reference' => ['curve' => [['rating' => 85.0, 'forecast' => 86.9, 'low' => 79.8, 'high' => 94.5]], 'bands' => ['below' => 60.0, 'on_track' => 80.0], 'coverage' => 0.8],
        ]),
    ]);
}

/** An employee with one completed appraisal of `$percent`, in a period that has closed. */
function explainedAppraisee(float $percent): Employee
{
    $employee = Employee::factory()->create(['employment_status' => 'active']);
    $period = EvaluationPeriod::factory()->create([
        'name' => 'FY 2025', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => 'closed',
    ]);
    PerformanceEvaluation::create([
        'employee_id' => $employee->id, 'evaluation_period_id' => $period->id,
        'overall_percent' => $percent, 'overall_score' => round(1 + $percent / 25, 2),
        'status' => 'acknowledged', 'submitted_at' => now(),
    ]);

    return $employee;
}

test('an attrition run keeps each score’s typical-employee baseline and what the run was read against', function () {
    actingAsSuperAdmin();
    fakeExplainedServices();
    Employee::factory()->create(['employment_status' => 'active']);

    $this->post(route('analytics.attrition.store'));

    $run = AttritionRiskRun::sole();
    $score = AttritionRiskScore::sole();
    expect($score->explanation)->toBe(['baseline' => 22.1])
        ->and($score->factors[0]['evidence']['rate'])->toBe(0.5333)
        ->and($run->reference['evidence']['people'])->toBe(199);

    $this->get(route('analytics.attrition.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('run.reference.baseline', 22.1)
            ->where('run.reference.tiers.high_from', 66)
            ->where('run.reference.evidence.features.absences_90d.1.band', '6–10 days')
            ->where('run.scores.0.explanation.baseline', 22.1)
            ->where('run.scores.0.factors.0.band', '6–10 days')
            ->where('run.scores.0.factors.0.typical_band', '1–2 days')
            ->where('run.scores.0.factors.0.evidence.consistent', true));
});

test('a service from before explanations leaves a run unexplained rather than invented', function () {
    actingAsSuperAdmin();
    fakeExplainedServices(explained: false);
    Employee::factory()->create(['employment_status' => 'active']);

    $this->post(route('analytics.attrition.store'));

    expect(AttritionRiskScore::sole()->explanation)->toBeNull()
        ->and(AttritionRiskRun::sole()->reference)->toBeNull();

    $this->get(route('analytics.attrition.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('run.reference', null)
            ->where('run.scores.0.explanation', null));
});

test('a readiness score keeps its baseline and what the next appraisal would do', function () {
    actingAsSuperAdmin();
    fakeExplainedServices();
    explainedAppraisee(76.0);

    $this->post(route('analytics.promotion-readiness.store'));

    $score = PromotionReadinessScore::sole();
    expect($score->explanation['baseline'])->toBe(64.8)
        ->and($score->explanation['outlook']['high_from'])->toBe(83)
        ->and(PromotionReadinessRun::sole()->reference['tier_scores'])->toBe(['high' => 82.8, 'medium' => 67.2]);

    $this->get(route('analytics.promotion-readiness.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('run.reference.tier_scores.medium', 67.2)
            ->where('run.scores.0.explanation.outlook.same_again', 42.6)
            ->has('run.scores.0.explanation.outlook.points', 2)
            ->where('run.scores.0.factors.0.typical', 69.98));
});

test('a forecast keeps the chance of every band, and its run the model’s rating curve', function () {
    actingAsSuperAdmin();
    fakeExplainedServices();
    explainedAppraisee(85.5);

    $this->post(route('analytics.performance-forecast.store'));

    expect(PerformanceForecast::sole()->explanation['chances'])->toBe(['below' => 0, 'on_track' => 0.094, 'exceeds' => 0.906])
        ->and(PerformanceForecastRun::sole()->reference['curve'][0]['forecast'])->toBe(86.9);

    $this->get(route('analytics.performance-forecast.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('run.reference.bands.on_track', 80)
            ->where('run.forecasts.0.explanation.chances.exceeds', 0.906));
});

test('the explanation keeps only what each model explains with', function () {
    expect(PredictionExplanation::forScore('attrition', ['baseline' => 20.5, 'outlook' => ['x' => 1], 'score' => 40]))->toBe(['baseline' => 20.5])
        ->and(PredictionExplanation::forScore('promotion', ['baseline' => null, 'outlook' => null]))->toBeNull()
        ->and(PredictionExplanation::forScore('performance', ['chances' => 'not a map']))->toBeNull()
        ->and(PredictionExplanation::forRun(['reference' => []]))->toBeNull()
        ->and(PredictionExplanation::forRun(['reference' => ['baseline' => 1]]))->toBe(['baseline' => 1]);
});

test('the assistant gives an explained risk its points from a typical employee — and still never pay', function () {
    $user = actingAsSuperAdmin();
    $maria = Employee::factory()->create(['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos', 'suffix' => null, 'employment_status' => 'active']);
    $run = AttritionRiskRun::create(['status' => 'completed', 'employees_scored' => 1, 'high_count' => 1, 'medium_count' => 0, 'low_count' => 0, 'average_score' => 67.1, 'average_confidence' => 1]);
    $run->scores()->create([
        'employee_id' => $maria->id, 'probability' => 0.671, 'score' => 67.1, 'tier' => 'high', 'confidence' => 1,
        'factors' => [
            ['feature' => 'years_since_promotion', 'label' => 'Time since last promotion', 'impact' => 0.1428, 'direction' => 'up'],
            ['feature' => 'monthly_salary', 'label' => 'Monthly salary', 'impact' => -0.051, 'direction' => 'down'],
            ['feature' => 'tenure_years', 'label' => 'Tenure', 'impact' => -0.031, 'direction' => 'down'],
        ],
        'explanation' => ['baseline' => 22.1],
        'features' => ['tenure_years' => 1.9, 'monthly_salary' => 22076, 'years_since_promotion' => 0.67, 'ever_promoted' => 1],
    ]);

    $read = app(AttritionRiskModule::class)->run($user, 'get_attrition_risk', ['employee' => 'Maria Santos']);
    $meta = implode(' | ', $read->cards[0]['meta']);

    expect($meta)->toContain('What moves it: Since last promotion raises it (+14.3 points); Tenure lowers it (−3.1 points)')
        ->toContain('A typical employee scores 22/100')
        ->not->toContain('Monthly salary')
        ->not->toContain('5.1 points');
});
