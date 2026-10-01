<?php

use App\Models\ActivityLog;
use App\Models\AttritionRiskRun;
use App\Models\AttritionRiskScore;
use App\Models\Employee;
use App\Models\EmployeePromotion;
use App\Models\EvaluationPeriod;
use App\Models\LocalModel;
use App\Models\OffboardingCase;
use App\Models\Organization;
use App\Models\PerformanceEvaluation;
use App\Models\PromotionReadinessRun;
use App\Support\Ml\Graduation\AttritionGraduation;
use App\Support\Ml\Graduation\FieldCounts;
use App\Support\Ml\Graduation\PerformanceGraduation;
use App\Support\Ml\Graduation\PromotionGraduation;
use App\Support\Ml\Graduation\Requirement;
use App\Support\Ml\Graduation\TrainingSet;
use App\Support\Tenancy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Model graduation (ADR 0046): each predictive surface counts, from the
 * organisation's own records, exactly the examples its own model would learn from;
 * trains only when every requirement is met; offers a model only when it passed its
 * check against the general model; and scores with it only after someone switches.
 * The inference service is faked at the HTTP boundary.
 */

/**
 * Fake the inference service: `/health` lists `$models`; `/train/*` answers with
 * `$verdict`; `/predict/promotion` scores everyone 0.12.
 *
 * @param  list<string>  $models
 */
function fakeGraduationService(array $models = ['promotion', 'performance', 'attrition'], string $verdict = 'passed', int $predictStatus = 200): void
{
    Http::fake([
        '*/health' => Http::response([
            'status' => 'ok',
            'service' => 'synapse-ml-inference',
            'models' => collect($models)->mapWithKeys(fn (string $m): array => [$m => ['kind' => 'classifier', 'version' => null, 'feature_count' => 2, 'metrics' => []]])->all(),
        ]),
        '*/train/*' => Http::response([
            'model' => 'promotion',
            'tenant' => 'org-1',
            'verdict' => $verdict,
            'version' => $verdict === 'passed' ? '20260927120000-abcdef' : null,
            'findings' => $verdict === 'passed'
                ? ['On your own records, your model’s predicted chances of promotion were closer to what happened than the general model’s.']
                : ['On your own records, your model was not reliably more accurate than the general model.'],
            'comparison' => ['metric' => 'brier', 'better' => 'lower', 'local' => 0.071, 'reference' => 0.089, 'baseline' => 0.09,
                'wins_over_reference' => $verdict === 'passed' ? 0.97 : 0.64, 'wins_over_baseline' => 0.99, 'required_share' => 0.9,
                'examples' => 240, 'people' => 80],
            'counts' => ['promoted' => 120, 'not_promoted' => 120, 'promoted_with_change' => 60, 'people' => 80],
        ]),
        '*/predict/promotion' => fn (Request $request) => $predictStatus !== 200
            ? Http::response(['detail' => 'Unknown local model.'], $predictStatus)
            : Http::response([
                'model' => 'promotion',
                'model_version' => isset($request->data()['variant']) ? 'local:'.$request->data()['variant']['version'] : 'LogisticRegression@test',
                'results' => collect($request->data()['instances'])->map(fn (array $i): array => [
                    'ref' => $i['ref'], 'status' => 'scored', 'probability' => 0.12, 'score' => 48.0, 'tier' => 'medium',
                    'basis' => 'latest_appraisal', 'factors' => [], 'warnings' => [],
                ])->all(),
                'warnings' => [],
            ]),
    ]);
}

/** A closed review period that ended `$monthsAgo` months ago. */
function graduationPeriod(string $name, int $monthsAgo): EvaluationPeriod
{
    return EvaluationPeriod::factory()->create([
        'name' => $name,
        'start_date' => today()->subMonths($monthsAgo + 12),
        'end_date' => today()->subMonths($monthsAgo),
        'status' => 'closed',
    ]);
}

/** A completed appraisal, scored on appraisal form `$form`. */
function graduationAppraisal(Employee $employee, EvaluationPeriod $period, float $percent, string $form = 'v1'): PerformanceEvaluation
{
    return PerformanceEvaluation::create([
        'employee_id' => $employee->id,
        'evaluation_period_id' => $period->id,
        'template_sections' => [['name' => 'Results', 'form' => $form]],
        'template_bands' => [['label' => 'Meets']],
        'overall_percent' => $percent,
        'overall_score' => round(1 + $percent / 25, 2),
        'status' => 'acknowledged',
        'submitted_at' => now(),
    ]);
}

function graduationPromotion(Employee $employee, int $monthsAgo): EmployeePromotion
{
    return EmployeePromotion::create(['employee_id' => $employee->id, 'effective_date' => today()->subMonths($monthsAgo)]);
}

/** Someone who left through a completed offboarding case `$monthsAgo` months ago. */
function graduationLeaver(string $type, int $monthsAgo, string $name = 'Leaver'): Employee
{
    $employee = Employee::factory()->create([
        'first_name' => $name,
        'employment_status' => OffboardingCase::EMPLOYMENT_STATUS_ON_COMPLETE[$type],
    ]);

    OffboardingCase::create([
        'employee_id' => $employee->id,
        'type' => $type,
        'status' => 'completed',
        'last_working_day' => today()->subMonths($monthsAgo),
        'completed_at' => today()->subMonths($monthsAgo),
    ]);

    return $employee;
}

/** A stored attrition risk score for `$employee`, as a run `$monthsAgo` months ago left it. */
function graduationSnapshot(Employee $employee, int $monthsAgo, array $features = ['tenure_years' => 2.5, 'monthly_salary' => 30000]): void
{
    $run = AttritionRiskRun::query()->whereDate('created_at', today()->subMonths($monthsAgo))->first();

    if ($run === null) {
        $run = AttritionRiskRun::create(['status' => 'completed']);
        $run->forceFill(['created_at' => today()->subMonths($monthsAgo)->setTime(9, 0)])->save();
    }

    AttritionRiskScore::create([
        'attrition_risk_run_id' => $run->id,
        'employee_id' => $employee->id,
        'probability' => 0.3, 'score' => 30, 'tier' => 'low', 'confidence' => 0.6,
        'features' => $features,
    ]);
}

/** Replace the promotion surface with one whose requirements are all met. */
function promotionReadyToTrain(): void
{
    app()->instance(PromotionGraduation::class, new class extends PromotionGraduation
    {
        public function trainingSet(): TrainingSet
        {
            return new TrainingSet([['group' => '1', 'features' => ['rating_latest' => 80.0], 'outcome' => 1]], ['appraisals' => 1]);
        }

        public function requirements(TrainingSet $set, FieldCounts $counts): array
        {
            return [new Requirement(key: 'promoted', label: 'Promotions that followed an appraisal', group: 'volume',
                current: 100, required: 100, summary: '', action: '', basis: '', source: '')];
        }
    });
}

function localPromotionModel(string $status = 'ready', array $attributes = []): LocalModel
{
    return LocalModel::create([
        'model' => 'promotion',
        'status' => $status,
        'version' => $status === 'failed' ? null : '20260927120000-'.substr(md5((string) microtime(true)), 0, 6),
        'examples' => 240,
        'findings' => [],
        ...$attributes,
    ]);
}

// ── Where each surface stands ────────────────────────────────────────────────

test('each surface shows where it stands, counted from its own records', function (string $route, string $model, array $keys) {
    actingAsSuperAdmin();
    fakeGraduationService();

    $this->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('graduation.model', $model)
            ->where('graduation.stage', 'provisional')
            ->where('graduation.gate_open', false)
            ->where('graduation.active', null)
            ->where('graduation.requirements', fn ($requirements) => collect($requirements)->pluck('key')->all() === [...$keys, 'service'])
            ->where('graduation.requirements', fn ($requirements) => collect($requirements)->firstWhere('key', 'service')['status'] === 'met')
            ->where('graduation.binding_key', $keys[0])
            ->has('graduation.fields'));
})->with([
    'promotion' => ['analytics.promotion-readiness.index', 'promotion', ['promoted', 'promoted_with_change', 'not_promoted', 'same_form']],
    'performance' => ['analytics.performance-forecast.index', 'performance', ['comparisons', 'cycles', 'same_form']],
    'attrition' => ['analytics.attrition.index', 'attrition', ['resigned', 'stayed', 'recorded_departures']],
]);

test('the service requirement is unmet while the service lacks the surface’s model', function () {
    actingAsSuperAdmin();
    fakeGraduationService(models: ['attrition']);

    $this->get(route('analytics.promotion-readiness.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('graduation.requirements', fn ($requirements) => collect($requirements)->firstWhere('key', 'service')['status'] === 'waiting'));
});

test('a surface starts collecting once what its own model would learn from is being recorded', function () {
    actingAsSuperAdmin();
    fakeGraduationService();
    graduationAppraisal(Employee::factory()->create(), graduationPeriod('FY 2025', 6), 75.0);

    $this->get(route('analytics.promotion-readiness.index'))
        ->assertInertia(fn (Assert $page) => $page->where('graduation.stage', 'collecting'));
});

// ── What each surface's own model would learn from ───────────────────────────

test('promotion credits each promotion once, waits for its follow-up, and leaves out who left early', function () {
    testOrganization();
    [$p1, $p2, $p3] = [graduationPeriod('FY 2023', 30), graduationPeriod('FY 2024', 18), graduationPeriod('FY 2025', 6)];

    // Promoted between her second and third appraisals: credited to the second.
    $alice = Employee::factory()->create();
    graduationAppraisal($alice, $p1, 70.0);
    graduationAppraisal($alice, $p2, 80.0);
    graduationAppraisal($alice, $p3, 85.0);
    graduationPromotion($alice, 12);
    // Promoted within the year after his only appraisal.
    $bob = Employee::factory()->create();
    graduationAppraisal($bob, $p1, 60.0);
    graduationPromotion($bob, 20);
    // Promoted with no appraisal in the year before.
    graduationPromotion(Employee::factory()->create(), 3);
    // Left before his appraisal's year was up, without a promotion.
    graduationAppraisal(graduationLeaver('resignation', 24), $p1, 65.0);
    // Promoted after an appraisal whose follow-up is still open.
    $eve = Employee::factory()->create();
    graduationAppraisal($eve, $p3, 90.0);
    graduationPromotion($eve, 2);

    $set = app(PromotionGraduation::class)->trainingSet();

    expect($set->counts)->toMatchArray([
        'promoted' => 2, 'not_promoted' => 1, 'promoted_with_change' => 1, 'with_change' => 1, 'same_form' => 1,
        'promotions' => 4, 'promotions_pending' => 1, 'promotions_unusable' => 1, 'left_early' => 1,
    ])->and($set->rows)->toContain(
        ['group' => (string) $alice->id, 'features' => ['rating_latest' => 70.0], 'outcome' => 0],
        ['group' => (string) $alice->id, 'features' => ['rating_latest' => 80.0, 'rating_change' => 10.0], 'outcome' => 1],
        ['group' => (string) $bob->id, 'features' => ['rating_latest' => 60.0], 'outcome' => 1],
    )->and($set->rows)->toHaveCount(3);

    $promoted = collect(app(PromotionGraduation::class)->requirements($set, new FieldCounts))->firstWhere('key', 'promoted');
    expect($promoted->note)->toContain('1 promotion on record can’t count')->toContain('1 more will count');
});

test('performance pairs consecutive cycles, skips lapses and notices a changed form', function () {
    testOrganization();
    [$p1, $p2, $p3] = [graduationPeriod('FY 2023', 30), graduationPeriod('FY 2024', 18), graduationPeriod('FY 2025', 6)];

    $alice = Employee::factory()->create();
    graduationAppraisal($alice, $p1, 70.0);
    graduationAppraisal($alice, $p2, 74.0);
    graduationAppraisal($alice, $p3, 79.0, form: 'v2');
    // Two years between appraisals is a lapse, not a cycle-to-cycle step.
    $bob = Employee::factory()->create();
    graduationAppraisal($bob, $p1, 60.0);
    graduationAppraisal($bob, $p3, 66.0);

    $set = app(PerformanceGraduation::class)->trainingSet();

    expect($set->counts)->toMatchArray(['comparisons' => 2, 'cycles' => 2, 'same_form' => 1, 'lapsed' => 1])
        ->and($set->rows)->toBe([
            ['group' => (string) $alice->id, 'features' => ['rating_latest' => 70.0], 'outcome' => 74.0, 'cycle' => "period-{$p2->id}"],
            ['group' => (string) $alice->id, 'features' => ['rating_latest' => 74.0], 'outcome' => 79.0, 'cycle' => "period-{$p3->id}"],
        ]);

    $sameForm = collect(app(PerformanceGraduation::class)->requirements($set, new FieldCounts))->firstWhere('key', 'same_form');
    expect($sameForm->current)->toBe(50.0)->and($sameForm->met())->toBeFalse();
});

test('attrition reads stored snapshots a year apart, and only a resignation as leaving', function () {
    testOrganization();

    $stayer = Employee::factory()->create();
    graduationSnapshot($stayer, 30, ['tenure_years' => 2.5, 'monthly_salary' => 30000, 'not_an_input' => 1]);
    graduationSnapshot($stayer, 24);   // inside the first snapshot's year: not taken
    graduationSnapshot($stayer, 3);    // its year has not passed
    graduationSnapshot(graduationLeaver('resignation', 20, 'Quit'), 30);
    graduationSnapshot(graduationLeaver('termination', 20, 'Fired'), 30);
    $ghost = Employee::factory()->create(['employment_status' => 'resigned']);
    graduationSnapshot($ghost, 30);    // left with no offboarding record

    $set = app(AttritionGraduation::class)->trainingSet();

    expect($set->counts)->toMatchArray(['resigned' => 1, 'stayed' => 1, 'pending' => 1, 'other_exit' => 1, 'unrecorded_exit' => 1])
        ->and($set->rows)->toContain(['group' => (string) $stayer->id, 'features' => ['tenure_years' => 2.5, 'monthly_salary' => 30000], 'outcome' => 0])
        ->and(collect($set->rows)->where('outcome', 1))->toHaveCount(1);

    // Two of the three people who left have a recorded type.
    $recorded = collect(app(AttritionGraduation::class)->requirements($set, new FieldCounts))->firstWhere('key', 'recorded_departures');
    expect($recorded->current)->toBe(66.7)->and($recorded->met())->toBeFalse();
});

// ── Training ─────────────────────────────────────────────────────────────────

test('nothing is trained while a requirement is unmet', function () {
    actingAsSuperAdmin();
    fakeGraduationService();

    $this->from(route('analytics.promotion-readiness.index'))
        ->post(route('analytics.promotion-readiness.graduation.train'))
        ->assertRedirect(route('analytics.promotion-readiness.index'));

    assertToast('warning', 'Not every requirement is met yet');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/train/'));
    expect(LocalModel::count())->toBe(0);
});

test('a model that passes its check is offered, not switched to', function () {
    $user = actingAsSuperAdmin();
    fakeGraduationService();
    promotionReadyToTrain();

    $this->post(route('analytics.promotion-readiness.graduation.train'));

    assertToast('success', 'passed its check');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/train/promotion')
        && $request->data()['tenant'] === 'org-'.testOrganization()->id
        && $request->data()['rows'][0]['outcome'] === 1);

    $model = LocalModel::sole();
    expect($model->status)->toBe('ready')
        ->and($model->version)->toBe('20260927120000-abcdef')
        ->and($model->trained_by)->toBe($user->id)
        ->and($model->comparison['wins_over_reference'])->toBe(0.97)
        ->and(ActivityLog::query()->where('log_name', 'promotion-readiness')->where('event', 'trained')
            ->where('description', 'like', '%passed its check%')->exists())->toBeTrue();

    $this->get(route('analytics.promotion-readiness.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('graduation.stage', 'collecting')
            ->where('graduation.active', null)
            ->where('graduation.latest.status', 'ready')
            ->where('graduation.latest.hashid', $model->hashid));
});

test('a model that fails its check is recorded with the reason, and nothing changes', function () {
    actingAsSuperAdmin();
    fakeGraduationService(verdict: 'failed');
    promotionReadyToTrain();
    $earlier = localPromotionModel('ready');

    $this->post(route('analytics.promotion-readiness.graduation.train'));

    assertToast('info', 'didn’t pass its check');
    $failed = LocalModel::query()->where('status', 'failed')->sole();
    expect($failed->version)->toBeNull()
        ->and($failed->findings)->toBe(['On your own records, your model was not reliably more accurate than the general model.'])
        // The model that passed earlier is still the one on offer.
        ->and($earlier->fresh()->status)->toBe('ready');
});

test('a newer model that passes supersedes one not yet in use', function () {
    actingAsSuperAdmin();
    fakeGraduationService();
    promotionReadyToTrain();
    $earlier = localPromotionModel('ready');

    $this->post(route('analytics.promotion-readiness.graduation.train'));

    expect($earlier->fresh()->status)->toBe('retired')
        ->and(LocalModel::query()->where('status', 'ready')->count())->toBe(1);
});

// ── Switching ────────────────────────────────────────────────────────────────

test('after switching, the next run is scored by the organisation’s own model and says so', function () {
    $user = actingAsSuperAdmin();
    fakeGraduationService();
    $previous = localPromotionModel('active');
    $model = localPromotionModel('ready');
    graduationAppraisal(Employee::factory()->create(), graduationPeriod('FY 2025', 6), 75.0);

    $this->post(route('analytics.promotion-readiness.graduation.activate', $model));

    assertToast('success', 'now uses your organisation’s own model');
    expect($model->fresh())->status->toBe('active')->activated_by->toBe($user->id)
        ->and($previous->fresh()->status)->toBe('retired');

    $this->post(route('analytics.promotion-readiness.store'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/predict/promotion')
        && $request->data()['variant'] === ['tenant' => 'org-'.testOrganization()->id, 'version' => $model->version]);
    expect(PromotionReadinessRun::sole())->local_model_id->toBe($model->id)->model_version->toBe("local:{$model->version}");

    $this->get(route('analytics.promotion-readiness.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('graduation.stage', 'graduated')
            ->where('graduation.active.hashid', $model->hashid)
            ->where('run.scored_by', 'own'));
});

test('switching back returns the surface to the general model', function () {
    actingAsSuperAdmin();
    fakeGraduationService();
    $model = localPromotionModel('active');
    graduationAppraisal(Employee::factory()->create(), graduationPeriod('FY 2025', 6), 75.0);

    $this->delete(route('analytics.promotion-readiness.graduation.revert'));

    assertToast('success', 'back on the general model');
    expect($model->fresh()->status)->toBe('retired')
        ->and(ActivityLog::query()->where('event', 'retired')->where('log_name', 'promotion-readiness')->exists())->toBeTrue();

    $this->post(route('analytics.promotion-readiness.store'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/predict/promotion') && ! isset($request->data()['variant']));
    expect(PromotionReadinessRun::sole()->local_model_id)->toBeNull();

    $this->delete(route('analytics.promotion-readiness.graduation.revert'));
    assertToast('warning', 'already uses the general model');
});

test('only a model that passed its check can be switched to, on its own surface', function () {
    actingAsSuperAdmin();
    fakeGraduationService();
    $failed = localPromotionModel('failed');

    $this->post(route('analytics.promotion-readiness.graduation.activate', $failed));
    assertToast('warning', 'Only a model that passed its check');
    expect($failed->fresh()->status)->toBe('failed');

    $this->post(route('analytics.attrition.graduation.activate', localPromotionModel('ready')))->assertNotFound();
});

test('a missing own model is reported, never quietly replaced by the general one', function () {
    actingAsSuperAdmin();
    fakeGraduationService(predictStatus: 404);
    localPromotionModel('active');
    graduationAppraisal(Employee::factory()->create(), graduationPeriod('FY 2025', 6), 75.0);

    $this->post(route('analytics.promotion-readiness.store'));

    assertToast('warning', 'own model isn’t available');
    expect(PromotionReadinessRun::count())->toBe(0);
});

// ── Access ───────────────────────────────────────────────────────────────────

test('graduating a surface needs its manage permission', function () {
    actingAsUserWith(['analytics.promotion.view']);
    fakeGraduationService();
    $model = localPromotionModel('ready');

    $this->post(route('analytics.promotion-readiness.graduation.train'))->assertForbidden();
    $this->post(route('analytics.promotion-readiness.graduation.activate', $model))->assertForbidden();
    $this->delete(route('analytics.promotion-readiness.graduation.revert'))->assertForbidden();

    $this->get(route('analytics.promotion-readiness.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', false)->has('graduation'));
});

test('another organisation’s model cannot be switched to', function () {
    actingAsSuperAdmin();
    fakeGraduationService();
    $mine = testOrganization();

    app(Tenancy::class)->set(Organization::factory()->create());
    $theirs = localPromotionModel('ready');
    app(Tenancy::class)->set($mine);

    $this->post(route('analytics.promotion-readiness.graduation.activate', $theirs))->assertNotFound();
    expect(LocalModel::withoutGlobalScopes()->find($theirs->id)->status)->toBe('ready');
});
