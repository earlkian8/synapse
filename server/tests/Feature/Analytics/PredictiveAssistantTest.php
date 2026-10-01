<?php

use App\Models\ActivityLog;
use App\Models\AttritionRiskRun;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\LocalModel;
use App\Models\Organization;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecastRun;
use App\Models\PromotionReadinessRun;
use App\Models\User;
use App\Services\Assistant\Modules\AttritionRiskModule;
use App\Services\Assistant\Modules\PerformanceForecastModule;
use App\Services\Assistant\Modules\PromotionReadinessModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Ml\AppraisalHistory;
use App\Support\Tenancy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| The three predictive capabilities of the assistant (ADR 0058): attrition
| risk, promotion readiness and the performance forecast — read from the
| stored runs, run and deleted through the screens' own classes, and never
| saying a word about pay. The inference service is faked at the HTTP
| boundary; Gemini is never called.
*/

function riskAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(AttritionRiskModule::class)->run($user, $tool, $args);
}

function readinessAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(PromotionReadinessModule::class)->run($user, $tool, $args);
}

function outlookAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(PerformanceForecastModule::class)->run($user, $tool, $args);
}

/** An active employee with a plain name, optionally in a department. */
function scoredPerson(string $first, string $last, ?Department $department = null): Employee
{
    return Employee::factory()->create([
        'first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix' => null,
        'employment_status' => 'active', 'department_id' => $department?->id,
    ]);
}

/**
 * An attrition run with one score per [employee, score, tier].
 *
 * @param  list<array{0: Employee, 1: float, 2: string}>  $rows
 */
function riskRun(array $rows, string $ranAt = 'now'): AttritionRiskRun
{
    $tiers = collect($rows)->countBy(fn (array $r): string => $r[2]);
    $run = AttritionRiskRun::create([
        'status' => 'completed', 'employees_scored' => count($rows),
        'high_count' => $tiers['high'] ?? 0, 'medium_count' => $tiers['medium'] ?? 0, 'low_count' => $tiers['low'] ?? 0,
        'average_score' => collect($rows)->avg(fn (array $r): float => $r[1]), 'average_confidence' => 0.875,
    ]);
    $run->forceFill(['created_at' => $ranAt === 'now' ? now() : $ranAt])->save();

    foreach ($rows as [$employee, $score, $tier]) {
        $run->scores()->create([
            'employee_id' => $employee->id, 'probability' => $score / 100, 'score' => $score, 'tier' => $tier, 'confidence' => 0.875,
            'factors' => [
                ['feature' => 'monthly_salary', 'label' => 'Monthly salary', 'impact' => 1.4, 'direction' => 'up'],
                ['feature' => 'years_since_promotion', 'label' => 'Years since promotion', 'impact' => 0.9, 'direction' => 'up'],
                ['feature' => 'tenure_years', 'label' => 'Tenure', 'impact' => -0.4, 'direction' => 'down'],
            ],
            'features' => ['employment_type' => 'regular', 'tenure_years' => 3.5, 'monthly_salary' => 41234.5, 'ever_promoted' => 0, 'years_since_promotion' => 3.5, 'absences_90d' => 2, 'lates_90d' => 1],
        ]);
    }

    return $run;
}

test('each surface opens with its view permission; running, training, deleting and switching need manage', function () {
    $risk = app(AttritionRiskModule::class);
    $viewer = actingAsUserWith(['analytics.attrition.view']);
    $manager = actingAsUserWith(['analytics.attrition.view', 'analytics.attrition.manage']);

    expect($risk->isAvailable(actingAsUserWith(['analytics.promotion.view'])))->toBeFalse()
        ->and(array_column($risk->tools($viewer), 'name'))->toBe(['attrition_risk_summary', 'find_attrition_risks', 'get_attrition_risk', 'get_attrition_model_status'])
        ->and(array_column($risk->tools($manager), 'name'))->toContain('run_attrition_assessment', 'train_attrition_model')
        ->and($risk->requiresConfirmation('delete_attrition_assessment'))->toBeTrue()
        ->and($risk->requiresConfirmation('switch_attrition_model'))->toBeTrue()
        ->and($risk->requiresConfirmation('run_attrition_assessment'))->toBeFalse()
        ->and(riskAgent($viewer, 'run_attrition_assessment')->detail)->toContain("don't have permission")
        ->and(array_column(app(PromotionReadinessModule::class)->tools(actingAsUserWith(['analytics.promotion.view'])), 'name'))->toContain('find_promotion_readiness')
        ->and(app(PerformanceForecastModule::class)->isReadOnly('get_forecast_model_status'))->toBeTrue();
});

test('attrition is summarised, listed and read — and pay is never said', function () {
    $user = actingAsSuperAdmin();
    $sales = Department::factory()->create(['name' => 'Sales', 'code' => 'SAL']);
    $maria = scoredPerson('Maria', 'Santos', $sales);
    $jon = scoredPerson('Jon', 'Doe');
    riskRun([[$maria, 40, 'medium'], [$jon, 20, 'low']], now()->subDays(30)->toDateTimeString());
    riskRun([[$maria, 72, 'high'], [$jon, 25, 'low']]);

    $summary = riskAgent($user, 'attrition_risk_summary')->cards[0];
    $inSales = riskAgent($user, 'find_attrition_risks', ['department' => 'sal']);
    $high = collect(riskAgent($user, 'find_attrition_risks', ['tier' => 'high'])->cards)->pluck('title')->all();
    $read = riskAgent($user, 'get_attrition_risk', ['employee' => 'Maria Santos']);
    $everything = json_encode([$summary, $inSales->cards, $read->cards]);

    expect($summary['meta'])->toContain('High risk 1, At watch 0, Stable 1', 'Average risk 49/100')
        ->and(implode(' | ', $summary['meta']))->toContain('High risk +1, At watch -1, Stable unchanged')
        ->and($inSales->cards[0]['title'])->toBe('Maria Santos')
        ->and($inSales->cards[0]['meta'])->toContain('Risk 72/100', 'Most raised by: Since last promotion')
        ->and($high)->toBe(['Maria Santos'])
        ->and($read->cards[0]['meta'])->toContain(
            'Risk 72/100 — High risk: likely to leave — prioritise retention',
            'What moves it: Since last promotion raises it; Tenure lowers it',
            'Pay is also one of its inputs; it is not discussed here.',
            'Previous assessment: 40/100 (At watch)',
        )
        ->and(implode(' | ', $read->cards[0]['meta']))->toContain('Based on: Employment type: Regular; Tenure: 3.5 yrs; Promoted here before: Never; Since last promotion: 3.5 yrs; Absences, last 90 days: 2 days; Late arrivals, last 90 days: 1 time')
        ->toContain('Not on record (estimated): Overtime, last 90 days')
        ->and($everything)->not->toContain('41234')
        ->and($everything)->not->toContain('41,234')
        ->and($everything)->not->toContain('Monthly salary')
        ->and($everything)->not->toContain('₱')
        ->and(ActivityLog::query()->where('event', 'viewed')->where('description', 'Viewed the attrition risk of Maria Santos via assistant')->exists())->toBeTrue();
});

test('promotion readiness states the odds against the right average, and says who was left out and why', function () {
    $user = actingAsSuperAdmin();
    $ana = scoredPerson('Ana', 'Reyes');
    $new = scoredPerson('Nico', 'New');
    $run = PromotionReadinessRun::create([
        'status' => 'completed', 'employees_scored' => 1, 'high_count' => 1, 'medium_count' => 0, 'low_count' => 0, 'average_score' => 88,
        'unassessed' => [['employee_id' => $new->id, 'reason' => AppraisalHistory::NO_APPRAISAL]],
    ]);
    $run->scores()->create([
        'employee_id' => $ana->id, 'probability' => 0.24, 'score' => 88, 'tier' => 'high', 'basis' => 'two_appraisals',
        'factors' => [['feature' => 'rating_change', 'label' => 'Improvement', 'impact' => -3, 'direction' => 'down'], ['feature' => 'rating_latest', 'label' => 'Latest', 'impact' => 12.5, 'direction' => 'up']],
        'features' => ['rating_latest' => 91, 'rating_change' => -2], 'history' => [['label' => 'H2 2025', 'rating' => 93], ['label' => 'H1 2026', 'rating' => 91]],
    ]);

    $meta = readinessAgent($user, 'get_promotion_readiness', ['employee' => 'Ana Reyes'])->cards[0]['meta'];
    $declined = readinessAgent($user, 'find_promotion_readiness', ['declined' => true]);
    $nico = readinessAgent($user, 'get_promotion_readiness', ['employee' => 'Nico New'])->cards[0]['meta'][0];

    expect($meta)->toContain(
        'Readiness 88/100 — High: at least twice as likely as average to be promoted',
        'Of people with this record in the reference workforce, 24% were promoted within a year — 2.4× the average of 10%',
        'Rests on two appraisals',
        'What moves it: Latest appraisal +12.5 points; Change since the previous appraisal −3 points',
        'Appraisals: H2 2025 93%, H1 2026 91%',
    )
        ->and($declined->cards[0]['title'])->toBe('Nico New')
        ->and($declined->cards[0]['meta'])->toBe(['No appraisal on record', 'Complete an appraisal for them'])
        ->and($nico)->toBe('The model declined them: no appraisal on record — complete an appraisal for them.')
        ->and(readinessAgent($user, 'promotion_readiness_summary')->cards[0]['meta'])->toContain('Not scored: 1 — no appraisal on record');
});

test('a forecast reads on 0–100 with its range and chance, and says how it did once the period is appraised', function () {
    $user = actingAsSuperAdmin();
    $period = EvaluationPeriod::factory()->create(['name' => 'H2 2026', 'status' => 'open']);
    $ana = scoredPerson('Ana', 'Reyes');
    $run = PerformanceForecastRun::create([
        'status' => 'completed', 'target_period_id' => $period->id, 'employees_scored' => 1,
        'exceeds_count' => 0, 'on_track_count' => 1, 'below_count' => 0, 'average_rating' => 74, 'average_confidence' => 0.71,
    ]);
    $run->forecasts()->create([
        'employee_id' => $ana->id, 'predicted_rating' => 74, 'predicted_low' => 66, 'predicted_high' => 81, 'confidence' => 0.71, 'band' => 'on_track',
        'features' => ['rating_latest' => 72], 'history' => [['label' => 'H1 2026', 'rating' => 72]],
    ]);
    PerformanceEvaluation::create(['employee_id' => $ana->id, 'evaluation_period_id' => $period->id, 'overall_percent' => 77, 'overall_score' => 4.08, 'status' => 'acknowledged', 'submitted_at' => now()]);

    $row = outlookAgent($user, 'find_performance_forecasts', ['tier' => 'on_track'])->cards[0];
    $meta = outlookAgent($user, 'get_performance_forecast', ['employee' => 'ana reyes'])->cards[0]['meta'];
    $summary = outlookAgent($user, 'performance_forecast_summary')->cards[0]['meta'];

    expect($row['badge'])->toBe('On track')
        ->and($row['meta'])->toBe(['74% (likely 66–81) · On track, 71% chance', 'Last appraisal 72%'])
        ->and($meta)->toContain('74% (likely 66–81) · On track, 71% chance — projected to meet expectations', 'Appraisals it rests on: H1 2026 72%', 'Actual result: 77%, inside the forecast range')
        ->and($summary)->toContain('Forecast for H2 2026', 'Average forecast 74%', 'Checked against 1 completed appraisals — too few to judge yet (a fair verdict needs about 20)');
});

test('a new assessment runs through the screen’s own path, and an unreachable service is said plainly', function () {
    $user = actingAsSuperAdmin();
    scoredPerson('Maria', 'Santos');

    $up = true;
    Http::fake(['*/predict/attrition' => function (Request $request) use (&$up) {
        return $up
            ? Http::response(['model' => 'attrition', 'model_version' => 'test', 'results' => collect($request->data()['instances'])
                ->map(fn (array $i): array => ['ref' => $i['ref'], 'probability' => 0.7, 'score' => 70, 'tier' => 'high', 'factors' => []])->all()])
            : Http::response('down', 503);
    }]);

    $ran = riskAgent($user, 'run_attrition_assessment');

    expect($ran->failed())->toBeFalse()
        ->and($ran->detail)->toBe('1 person scored.')
        ->and(AttritionRiskRun::query()->sole()->high_count)->toBe(1)
        ->and(ActivityLog::query()->where('description', 'like', 'Ran an attrition-risk assessment (1 employees, 1 high risk) via assistant')->exists())->toBeTrue();

    $up = false;

    $failed = riskAgent($user, 'run_attrition_assessment');

    expect($failed->failed())->toBeTrue()
        ->and($failed->detail)->not->toContain('503')
        ->and(AttritionRiskRun::query()->count())->toBe(1);
});

test('a run is deleted by which one it is, and the card says what the page would show', function () {
    $user = actingAsSuperAdmin();
    $maria = scoredPerson('Maria', 'Santos');
    $older = riskRun([[$maria, 40, 'medium']], '2026-08-01 09:00:00');
    riskRun([[$maria, 72, 'high']], '2026-09-01 09:00:00');
    riskRun([[$maria, 70, 'high']], '2026-09-01 15:00:00');
    $module = app(AttritionRiskModule::class);

    expect($module->consequence($user, 'delete_attrition_assessment', ['run' => 'latest']))
        ->toBe('It deletes the assessment of Sep 1, 2026, with the scores of 1 person; they cannot be recovered. The page would show the one of Sep 1, 2026 instead.')
        ->and(riskAgent($user, 'delete_attrition_assessment', ['run' => '2026-09-01'])->detail)->toContain('2 assessments ran on 2026-09-01')
        ->and(riskAgent($user, 'delete_attrition_assessment', ['run' => 'yesterday-ish'])->failed())->toBeTrue();

    $deleted = riskAgent($user, 'delete_attrition_assessment', ['run' => '2026-08-01']);

    expect($deleted->label)->toBe('Deleted the assessment of Aug 1, 2026')
        ->and(AttritionRiskRun::query()->find($older->id))->toBeNull()
        ->and(ActivityLog::query()->where('description', 'Deleted an attrition-risk assessment run via assistant')->exists())->toBeTrue();
});

test('the model is switched only to one that passed its check, and back', function () {
    $user = actingAsSuperAdmin();
    $module = app(PromotionReadinessModule::class);

    $none = readinessAgent($user, 'switch_promotion_model', ['to' => 'own']);
    $candidate = LocalModel::create(['model' => 'promotion', 'status' => 'ready', 'version' => 'v1', 'examples' => 240, 'counts' => ['promoted' => 30, 'not_promoted' => 210]]);

    expect($none->detail)->toContain('no trained model that passed its check')
        ->and($module->consequence($user, 'switch_promotion_model', ['to' => 'own']))->toContain("your organisation's own model, trained on 240 of your records");

    $switched = readinessAgent($user, 'switch_promotion_model', ['to' => 'own']);
    $back = readinessAgent($user, 'switch_promotion_model', ['to' => 'general']);
    $again = readinessAgent($user, 'switch_promotion_model', ['to' => 'general']);

    expect($switched->failed())->toBeFalse()
        ->and($candidate->fresh()->status)->toBe('retired')
        ->and($back->label)->toBe('Switched back to the general model')
        ->and($again->detail)->toBe('This page already uses the general model.')
        ->and(ActivityLog::query()->where('description', "Switched Promotion Readiness to the organisation's own model via assistant")->exists())->toBeTrue();
});

test('the model status says what training still needs, and when the service is down', function () {
    $user = actingAsSuperAdmin();
    Http::fake(['*/health' => Http::response('down', 503)]);

    $card = riskAgent($user, 'get_attrition_model_status')->cards[0];

    expect($card['title'])->toBe('Using the general model')
        ->and($card['subtitle'])->toMatch('/^\d+ of \d+ requirements met$/')
        ->and(implode(' | ', $card['meta']))->toContain('Still needed — ')
        ->toContain('The prediction service is not reachable right now');
});

test('another workspace’s runs are never read, and a question is answered from the brief without names', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();
    app(Tenancy::class)->runFor(Organization::factory()->create(), function (): void {
        riskRun([[Employee::factory()->create(['employment_status' => 'active']), 90, 'high']]);
    });
    app(Tenancy::class)->set($mine);

    expect(riskAgent($user, 'attrition_risk_summary')->detail)->toBe('No assessment has been run yet.')
        ->and(riskAgent($user, 'find_attrition_risks')->failed())->toBeTrue();

    $maria = scoredPerson('Maria', 'Santos');
    riskRun([[$maria, 72, 'high']]);

    $brief = app(Retriever::class)->retrieve($user, 'who is at risk of leaving?')?->toPrompt();

    expect($brief)->toContain('High risk 1, At watch 0, Stable 0')
        ->and($brief)->not->toContain('Maria');
});
