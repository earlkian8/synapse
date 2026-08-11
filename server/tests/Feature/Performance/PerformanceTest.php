<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\KpiCriterion;
use App\Models\PerformanceEvaluation;
use App\Models\RatingScale;
use App\Models\ReviewTemplate;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Conducting appraisals against an appraisal framework (ADR 0028): opening one,
 * scoring it on each criterion's own scale, submitting it into the tenant's own
 * rating band, and launching a whole cycle at once.
 */

/**
 * A framework with two weighted sections and one criterion in each, on the
 * scales given — the smallest shape that exercises two-level scoring.
 *
 * @param  array{0: int, 1: int}  $weights  The section weights.
 */
function framework(?RatingScale $goalScale = null, ?RatingScale $valueScale = null, array $weights = [60, 40]): ReviewTemplate
{
    $goalScale ??= RatingScale::factory()->percentage()->create(['name' => 'Goal attainment']);
    $valueScale ??= RatingScale::factory()->create(['name' => 'Five point', 'min' => 1, 'max' => 5]);

    $template = ReviewTemplate::factory()->create([
        'name' => 'Standard Review',
        'is_default' => true,
        'sections' => [
            ['key' => 'goals', 'name' => 'Goals', 'description' => null, 'weight' => $weights[0]],
            ['key' => 'values', 'name' => 'How we work', 'description' => null, 'weight' => $weights[1]],
        ],
    ]);

    $template->items()->createMany([
        [
            'kpi_criterion_id' => KpiCriterion::factory()->create(['name' => 'Goal attainment', 'rating_scale_id' => $goalScale->id])->id,
            'rating_scale_id' => $goalScale->id,
            'section_key' => 'goals',
            'name' => 'Goal attainment',
            'weight' => 100,
            'sort_order' => 0,
        ],
        [
            'kpi_criterion_id' => KpiCriterion::factory()->create(['name' => 'Teamwork', 'rating_scale_id' => $valueScale->id])->id,
            'rating_scale_id' => $valueScale->id,
            'section_key' => 'values',
            'name' => 'Teamwork',
            'weight' => 100,
            'sort_order' => 1,
        ],
    ]);

    return $template->refresh();
}

// ── The overview ─────────────────────────────────────────────────────────────

test('the overview renders, scoped to a review cycle', function () {
    actingAsSuperAdmin();
    $period = EvaluationPeriod::factory()->create();

    $this->get(route('performance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('performance/index')
            ->where('currentPeriodId', $period->id)
            ->has('evaluations')
            ->has('templates')
            ->has('distribution')
            ->has('byDepartment')
            ->has('stats.coverage')
            ->has('can'));
});

test('the overview defaults to the open cycle, not the newest one', function () {
    actingAsSuperAdmin();
    EvaluationPeriod::factory()->closed()->create(['name' => 'Last year', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31']);
    $open = EvaluationPeriod::factory()->create(['name' => 'Current', 'start_date' => '2025-01-01', 'end_date' => '2025-06-30']);

    $this->get(route('performance.index'))
        ->assertInertia(fn (Assert $page) => $page->where('currentPeriodId', $open->id));
});

test('viewing needs the performance.view permission', function () {
    actingAsUserWith([]);

    $this->get(route('performance.index'))->assertForbidden();
});

// ── Opening an appraisal ─────────────────────────────────────────────────────

test('opening an appraisal seeds the scorecard from the framework and freezes it', function () {
    actingAsSuperAdmin();
    $template = framework();
    $period = EvaluationPeriod::factory()->create();
    $employee = Employee::factory()->create();

    $this->post(route('performance.store'), [
        'employee_id' => $employee->id,
        'evaluation_period_id' => $period->id,
    ])->assertRedirect();

    $evaluation = PerformanceEvaluation::firstOrFail();

    expect($evaluation->review_template_id)->toBe($template->id)
        ->and($evaluation->template_name)->toBe('Standard Review')
        ->and($evaluation->template_bands)->toHaveCount(5)
        ->and($evaluation->scores)->toHaveCount(2);

    $goals = $evaluation->scores->firstWhere('section_key', 'goals');

    expect($goals->section_name)->toBe('Goals')
        ->and((float) $goals->section_weight)->toBe(60.0)
        ->and($goals->scale_type)->toBe('percentage')
        ->and((float) $goals->scale_max)->toBe(100.0);
});

test('retuning a framework never rewrites an appraisal already opened', function () {
    actingAsSuperAdmin();
    $template = framework();
    $period = EvaluationPeriod::factory()->create();
    $employee = Employee::factory()->create();

    $this->post(route('performance.store'), [
        'employee_id' => $employee->id,
        'evaluation_period_id' => $period->id,
    ]);

    $template->update(['name' => 'Renamed', 'sections' => [
        ['key' => 'goals', 'name' => 'Everything', 'description' => null, 'weight' => 100],
    ]]);

    $evaluation = PerformanceEvaluation::firstOrFail();

    expect($evaluation->template_name)->toBe('Standard Review')
        ->and($evaluation->scores->firstWhere('section_key', 'goals')->section_name)->toBe('Goals');
});

test('an appraisal cannot be opened in a cycle that is not open', function () {
    actingAsSuperAdmin();
    framework();
    $period = EvaluationPeriod::factory()->closed()->create();

    $this->post(route('performance.store'), [
        'employee_id' => Employee::factory()->create()->id,
        'evaluation_period_id' => $period->id,
    ]);

    assertToast('warning', 'not open');
    expect(PerformanceEvaluation::count())->toBe(0);
});

test('an employee is not appraised twice in the same cycle', function () {
    actingAsSuperAdmin();
    framework();
    $period = EvaluationPeriod::factory()->create();
    $employee = Employee::factory()->create();

    $payload = ['employee_id' => $employee->id, 'evaluation_period_id' => $period->id];

    $this->post(route('performance.store'), $payload);
    $this->post(route('performance.store'), $payload);

    assertToast('warning', 'Already appraised');
    expect(PerformanceEvaluation::count())->toBe(1);
});

test('opening refuses when no framework covers the employee', function () {
    actingAsSuperAdmin();
    $period = EvaluationPeriod::factory()->create();

    $this->post(route('performance.store'), [
        'employee_id' => Employee::factory()->create()->id,
        'evaluation_period_id' => $period->id,
    ]);

    assertToast('warning', 'No appraisal framework');
});

test('the narrowest framework wins over the catch-all', function () {
    actingAsSuperAdmin();
    framework();
    $department = Department::factory()->create();
    $targeted = framework(weights: [50, 50]);
    $targeted->update([
        'name' => 'Department Review',
        'is_default' => false,
        'applies_to' => 'department',
        'applies_to_values' => [(string) $department->id],
    ]);

    $period = EvaluationPeriod::factory()->create();

    $this->post(route('performance.store'), [
        'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        'evaluation_period_id' => $period->id,
    ]);

    expect(PerformanceEvaluation::firstOrFail()->template_name)->toBe('Department Review');
});

// ── Scoring ──────────────────────────────────────────────────────────────────

test('saving derives attainment from two-level weighting and reads it into a band', function () {
    actingAsSuperAdmin();
    framework();
    $evaluation = openAppraisal();

    $goals = $evaluation->scores->firstWhere('section_key', 'goals');
    $values = $evaluation->scores->firstWhere('section_key', 'values');

    // 100% of goals (60% of the appraisal) + 1/5 on values (0% attained, 40%).
    $this->patch(route('performance.update', $evaluation), [
        'remarks' => null,
        'scores' => [
            ['id' => $goals->id, 'score' => 100, 'remarks' => null],
            ['id' => $values->id, 'score' => 1, 'remarks' => null],
        ],
    ])->assertSessionHasNoErrors();

    $evaluation->refresh();

    expect((float) $evaluation->overall_percent)->toBe(60.0)
        ->and((float) $evaluation->overall_score)->toBe(3.4)
        ->and($evaluation->result_label)->toBe('Meets Expectations')
        ->and($evaluation->result_band)->toBe('meets');
});

test('a rating outside its own line’s scale is refused', function () {
    actingAsSuperAdmin();
    framework();
    $evaluation = openAppraisal();
    $values = $evaluation->scores->firstWhere('section_key', 'values');

    $this->patch(route('performance.update', $evaluation), [
        'remarks' => null,
        // The values line is on 1–5; 90 belongs to the percentage line.
        'scores' => [['id' => $values->id, 'score' => 90, 'remarks' => null]],
    ]);

    assertToast('warning', 'outside its criterion');
    expect($values->refresh()->score)->toBeNull();
});

test('a rating that is not one of a level scale’s levels is refused', function () {
    actingAsSuperAdmin();
    $levels = RatingScale::factory()->levels()->create(['name' => 'Expectation']);
    framework(valueScale: $levels);
    $evaluation = openAppraisal();
    $values = $evaluation->scores->firstWhere('section_key', 'values');

    $this->patch(route('performance.update', $evaluation), [
        'remarks' => null,
        // 2.5 sits inside the bounds but is not a level this scale defines.
        'scores' => [['id' => $values->id, 'score' => 2.5, 'remarks' => null]],
    ]);

    assertToast('warning', 'outside its criterion');
});

test('an unfinished scorecard cannot be submitted', function () {
    actingAsSuperAdmin();
    framework();
    $evaluation = openAppraisal();

    $this->patch(route('performance.update', $evaluation), [
        'remarks' => null,
        'scores' => [['id' => $evaluation->scores->first()->id, 'score' => 50, 'remarks' => null]],
    ]);

    $this->post(route('performance.submit', $evaluation));

    assertToast('warning', 'Rate every criterion');
    expect($evaluation->refresh()->status)->toBe('draft');
});

test('submitting locks the appraisal and records the band', function () {
    actingAsSuperAdmin();
    framework();
    $evaluation = scoreAppraisal(openAppraisal(), 100, 5);

    $this->post(route('performance.submit', $evaluation))->assertSessionHasNoErrors();

    $evaluation->refresh();

    expect($evaluation->status)->toBe('submitted')
        ->and($evaluation->submitted_at)->not->toBeNull()
        ->and((float) $evaluation->overall_percent)->toBe(100.0)
        ->and($evaluation->result_label)->toBe('Outstanding');

    // A submitted card is closed to further scoring.
    $this->patch(route('performance.update', $evaluation), ['remarks' => null, 'scores' => []]);
    assertToast('warning', 'no longer be edited');
});

test('a submitted appraisal can be signed off, and only then', function () {
    actingAsSuperAdmin();
    framework();
    $evaluation = scoreAppraisal(openAppraisal(), 80, 4);

    $this->post(route('performance.acknowledge', $evaluation));
    assertToast('warning', 'Only a submitted');

    $this->post(route('performance.submit', $evaluation));
    $this->post(route('performance.acknowledge', $evaluation));

    expect($evaluation->refresh()->status)->toBe('acknowledged')
        ->and($evaluation->acknowledged_at)->not->toBeNull();
});

test('a draft can be discarded but a submitted appraisal is kept', function () {
    actingAsSuperAdmin();
    framework();
    $draft = scoreAppraisal(openAppraisal(), 70, 3);

    $this->delete(route('performance.destroy', $draft))->assertRedirect(route('performance.index'));
    expect(PerformanceEvaluation::count())->toBe(0);

    $submitted = scoreAppraisal(openAppraisal(), 70, 3);
    $this->post(route('performance.submit', $submitted));
    $this->delete(route('performance.destroy', $submitted));

    assertToast('warning', 'cannot be deleted');
    expect(PerformanceEvaluation::count())->toBe(1);
});

// ── The scorecard page ───────────────────────────────────────────────────────

test('the scorecard renders the derived result alongside the appraisal', function () {
    actingAsSuperAdmin();
    framework();
    $evaluation = scoreAppraisal(openAppraisal(), 100, 5);

    $this->get(route('performance.show', $evaluation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('performance/show')
            ->where('result.percent', 100)
            ->where('result.band.label', 'Outstanding')
            ->has('result.sections', 2)
            ->has('evaluation.bands', 5)
            ->has('support'));
});

// ── Launching a cycle ────────────────────────────────────────────────────────

test('launching a cycle opens an appraisal for everyone active', function () {
    actingAsSuperAdmin();
    framework();
    $period = EvaluationPeriod::factory()->create();
    Employee::factory()->count(3)->create();
    Employee::factory()->create(['employment_status' => 'resigned']);

    $this->post(route('performance.cycles.store'), [
        'evaluation_period_id' => $period->id,
        'scope' => 'all',
    ])->assertSessionHasNoErrors();

    assertToast('success', 'Opened 3 appraisals');
    expect(PerformanceEvaluation::count())->toBe(3);
});

test('re-launching a cycle skips anyone already appraised', function () {
    actingAsSuperAdmin();
    framework();
    $period = EvaluationPeriod::factory()->create();
    Employee::factory()->count(2)->create();

    $payload = ['evaluation_period_id' => $period->id, 'scope' => 'all'];

    $this->post(route('performance.cycles.store'), $payload);
    Employee::factory()->create();
    $this->post(route('performance.cycles.store'), $payload);

    assertToast('success', '2 already had one');
    expect(PerformanceEvaluation::count())->toBe(3);
});

test('a launch can be scoped to chosen departments', function () {
    actingAsSuperAdmin();
    framework();
    $period = EvaluationPeriod::factory()->create();
    $included = Department::factory()->create();
    $excluded = Department::factory()->create();

    Employee::factory()->count(2)->create(['department_id' => $included->id]);
    Employee::factory()->create(['department_id' => $excluded->id]);

    $this->post(route('performance.cycles.store'), [
        'evaluation_period_id' => $period->id,
        'scope' => 'departments',
        'department_ids' => [$included->id],
    ]);

    expect(PerformanceEvaluation::count())->toBe(2);
});

test('a launch needs departments when it is scoped to them', function () {
    actingAsSuperAdmin();
    framework();

    $this->post(route('performance.cycles.store'), [
        'evaluation_period_id' => EvaluationPeriod::factory()->create()->id,
        'scope' => 'departments',
    ])->assertSessionHasErrors('department_ids');
});

test('a cycle cannot be launched while its period is closed', function () {
    actingAsSuperAdmin();
    framework();
    Employee::factory()->create();

    $this->post(route('performance.cycles.store'), [
        'evaluation_period_id' => EvaluationPeriod::factory()->closed()->create()->id,
        'scope' => 'all',
    ]);

    assertToast('warning', 'review period is open');
    expect(PerformanceEvaluation::count())->toBe(0);
});

test('launching needs the performance.manage permission', function () {
    actingAsUserWith(['performance.view']);

    $this->post(route('performance.cycles.store'), [
        'evaluation_period_id' => EvaluationPeriod::factory()->create()->id,
        'scope' => 'all',
    ])->assertForbidden();
});

// ── The export ───────────────────────────────────────────────────────────────

test('the export reports the rating in the company’s own words', function () {
    actingAsSuperAdmin();
    framework();
    $evaluation = scoreAppraisal(openAppraisal(), 100, 5);
    $this->post(route('performance.submit', $evaluation));

    $csv = $this->get(route('performance.export'))->assertOk()->streamedContent();

    expect($csv)->toContain('Rating')
        ->toContain('Outstanding')
        ->toContain('Standard Review');
});

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Open an appraisal for a fresh employee in a fresh open cycle. */
function openAppraisal(): PerformanceEvaluation
{
    test()->post(route('performance.store'), [
        'employee_id' => Employee::factory()->create()->id,
        'evaluation_period_id' => EvaluationPeriod::factory()->create()->id,
    ]);

    return PerformanceEvaluation::with('scores')->latest('id')->firstOrFail();
}

/** Rate both lines of a two-section appraisal. */
function scoreAppraisal(PerformanceEvaluation $evaluation, float $goals, float $values): PerformanceEvaluation
{
    test()->patch(route('performance.update', $evaluation), [
        'remarks' => null,
        'scores' => [
            ['id' => $evaluation->scores->firstWhere('section_key', 'goals')->id, 'score' => $goals, 'remarks' => null],
            ['id' => $evaluation->scores->firstWhere('section_key', 'values')->id, 'score' => $values, 'remarks' => null],
        ],
    ]);

    return $evaluation->refresh();
}
