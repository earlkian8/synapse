<?php

use App\Models\AttritionRiskRun;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PerformanceForecastRun;
use App\Models\PromotionReadinessRun;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The workforce summary of each predictive screen lives in Reports (ADR 0099): one
 * report per surface, read from its latest completed run, filtered like every other
 * report and exported to the same columns.
 */

/** A completed attrition run: Ana high (absences +36), Ben stable, in two departments. */
function predictiveAttritionRun(): array
{
    $finance = Department::factory()->create(['name' => 'Finance']);
    $sales = Department::factory()->create(['name' => 'Sales']);
    $ana = Employee::factory()->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes', 'suffix' => null, 'department_id' => $finance->id]);
    $ben = Employee::factory()->create(['first_name' => 'Ben', 'middle_name' => null, 'last_name' => 'Lim', 'suffix' => null, 'department_id' => $sales->id]);

    // An older run that must not be read.
    AttritionRiskRun::create(['status' => 'completed', 'employees_scored' => 1, 'high_count' => 0, 'medium_count' => 0, 'low_count' => 1, 'average_score' => 5])
        ->scores()->create(['employee_id' => $ben->id, 'probability' => 0.05, 'score' => 5, 'tier' => 'low', 'confidence' => 1, 'factors' => []]);

    $run = AttritionRiskRun::create(['status' => 'completed', 'employees_scored' => 2, 'high_count' => 1, 'medium_count' => 0, 'low_count' => 1, 'average_score' => 45]);
    $run->scores()->create([
        'employee_id' => $ana->id, 'probability' => 0.71, 'score' => 71.2, 'tier' => 'high', 'confidence' => 0.875,
        'factors' => [
            ['feature' => 'absences_90d', 'label' => 'Absences (last 90 days)', 'impact' => 0.359, 'direction' => 'up'],
            ['feature' => 'monthly_salary', 'label' => 'Monthly salary', 'impact' => 0.04, 'direction' => 'up'],
        ],
        'explanation' => ['baseline' => 22.1],
        'features' => ['monthly_salary' => 18000, 'absences_90d' => 7],
    ]);
    $run->scores()->create([
        'employee_id' => $ben->id, 'probability' => 0.19, 'score' => 18.6, 'tier' => 'low', 'confidence' => 1,
        'factors' => [['feature' => 'tenure_years', 'label' => 'Tenure', 'impact' => -0.05, 'direction' => 'down']],
        'explanation' => ['baseline' => 22.1],
    ]);

    return [$finance, $ana, $ben];
}

test('the attrition report lists the latest run, highest risk first, with what raises each score', function () {
    actingAsUserWith(['analytics.attrition.view']);
    predictiveAttritionRun();

    $this->get(route('reports.index', ['report' => 'attrition-risk']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('active.key', 'attrition-risk')
            ->where('active.group', 'Analytics')
            ->has('active.rows', 2)
            ->where('active.rows.0.full_name', 'Ana Reyes')
            ->where('active.rows.0.score', 71)
            ->where('active.rows.0.tier', 'High risk')
            ->where('active.rows.0.reason', 'Absences (last 90 days) +36')
            ->where('active.rows.0.confidence', '88%')
            ->where('active.rows.1.tier', 'Stable')
            ->where('active.rows.1.reason', '—')
            ->where('active.summary.1', ['label' => 'High risk', 'value' => '1'])
            ->where('active.charts.0.segments', [['label' => 'High risk', 'value' => 1], ['label' => 'Stable', 'value' => 1]])
            ->where('active.charts.1.bars.0', ['label' => 'Finance', 'value' => 71])
            ->where('active.charts.2.bars', [['label' => 'Absences (last 90 days)', 'value' => 1], ['label' => 'Monthly salary', 'value' => 1]]));
});

test('the attrition report filters by department and tier, and never shows pay', function () {
    actingAsUserWith(['analytics.attrition.view']);
    [$finance] = predictiveAttritionRun();

    $this->get(route('reports.index', ['report' => 'attrition-risk', 'department' => $finance->id]))
        ->assertInertia(fn (Assert $page) => $page->has('active.rows', 1)->where('active.rows.0.full_name', 'Ana Reyes'));

    $this->get(route('reports.index', ['report' => 'attrition-risk', 'tier' => 'low']))
        ->assertInertia(fn (Assert $page) => $page->has('active.rows', 1)->where('active.rows.0.full_name', 'Ben Lim'));

    $csv = $this->get(route('reports.export', ['report' => 'attrition-risk']))->assertOk()->streamedContent();

    expect($csv)->toContain('Raised most by')->toContain('Ana Reyes')->not->toContain('18000')->not->toContain('18,000');
});

test('the predictive reports are offered only to those who may see each screen', function () {
    actingAsUserWith(['analytics.promotion.view']);

    $this->get(route('reports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reports', fn ($reports) => collect($reports)->pluck('key')->all() === ['promotion-readiness']));

    $this->get(route('reports.export', ['report' => 'attrition-risk']))->assertForbidden();
});

test('the readiness report shows the appraisals each score rests on', function () {
    actingAsUserWith(['analytics.promotion.view']);
    $employee = Employee::factory()->create(['first_name' => 'Cara', 'middle_name' => null, 'last_name' => 'Uy', 'suffix' => null]);
    $run = PromotionReadinessRun::create(['status' => 'completed', 'employees_scored' => 1, 'high_count' => 1, 'medium_count' => 0, 'low_count' => 0, 'average_score' => 91]);
    $run->scores()->create([
        'employee_id' => $employee->id, 'probability' => 0.31, 'score' => 91.4, 'tier' => 'high', 'basis' => 'two_appraisals',
        'factors' => [['feature' => 'rating_change', 'label' => 'Change on the previous appraisal', 'impact' => 22.4, 'direction' => 'up']],
        'explanation' => ['baseline' => 50.1],
        'features' => ['rating_latest' => 88.25, 'rating_change' => 9.5],
    ]);

    $this->get(route('reports.index', ['report' => 'promotion-readiness']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('active.rows.0.score', 91)
            ->where('active.rows.0.tier', 'High')
            ->where('active.rows.0.latest', '88.3%')
            ->where('active.rows.0.change', '+9.5 pts')
            ->where('active.rows.0.basis', 'Two appraisals')
            ->where('active.rows.0.reason', 'Change on the previous appraisal +22')
            ->where('active.charts.2.segments', [['label' => 'Two appraisals', 'value' => 1]]));
});

test('the forecast report shows each likely range and the chance of its band', function () {
    actingAsUserWith(['analytics.performance.view']);
    $employee = Employee::factory()->create();
    $run = PerformanceForecastRun::create(['status' => 'completed', 'employees_scored' => 1, 'exceeds_count' => 0, 'on_track_count' => 1, 'below_count' => 0, 'average_rating' => 74.2]);
    $run->forecasts()->create([
        'employee_id' => $employee->id, 'predicted_rating' => 74.24, 'predicted_low' => 66.1, 'predicted_high' => 82.4,
        'confidence' => 0.71, 'band' => 'on_track', 'features' => ['rating_latest' => 76.0], 'history' => [],
    ]);

    $this->get(route('reports.index', ['report' => 'performance-forecast', 'band' => 'on_track']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('active.rows.0.forecast', 74.2)
            ->where('active.rows.0.range', '66–82')
            ->where('active.rows.0.band', 'On track')
            ->where('active.rows.0.chance', '71%')
            ->where('active.rows.0.latest', '76.0%'));

    $this->get(route('reports.index', ['report' => 'performance-forecast', 'band' => 'exceeds']))
        ->assertInertia(fn (Assert $page) => $page->has('active.rows', 0));
});

test('before a first run each predictive report is simply empty', function () {
    actingAsSuperAdmin();

    foreach (['attrition-risk', 'promotion-readiness', 'performance-forecast'] as $key) {
        $this->get(route('reports.index', ['report' => $key]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('active.key', $key)->has('active.rows', 0));
    }
});
