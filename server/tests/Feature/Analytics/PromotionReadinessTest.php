<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\Organization;
use App\Models\PerformanceEvaluation;
use App\Models\PromotionReadinessRun;
use App\Models\PromotionReadinessScore;
use App\Support\Ml\AppraisalHistory;
use App\Support\Ml\PromotionFeatureMapper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Promotion Readiness (ADR 0045): an assessment reads each active employee's
 * completed appraisals, sends the model their latest attainment and its change on
 * the previous one, and persists what comes back. An employee the model declines —
 * no completed appraisal — is recorded on the run with the reason, never scored
 * from a guess. The service is faked at the HTTP boundary, so everything on the
 * Laravel side of it runs for real.
 */

/**
 * Fake the inference service the way the real promotion model behaves: an
 * instance without `rating_latest` is declined; the rest get a probability chosen
 * by `$probability`, tiered on lift over a 10 % base rate.
 *
 * @param  list<string>  $models
 */
function fakePromotionService(array $models = ['promotion'], ?Closure $probability = null, array $warnings = []): void
{
    $probability ??= fn (array $features): float => 0.12;

    Http::fake([
        '*/health' => Http::response([
            'status' => 'ok',
            'service' => 'synapse-ml-inference',
            'models' => collect($models)->mapWithKeys(fn (string $m): array => [$m => ['kind' => 'classifier', 'version' => null, 'feature_count' => 2, 'metrics' => []]])->all(),
        ]),
        '*/predict/promotion' => function (Request $request) use ($probability, $warnings) {
            $results = collect($request->data()['instances'])->map(function (array $instance) use ($probability): array {
                $features = $instance['features'];

                if (! isset($features['rating_latest'])) {
                    return ['ref' => $instance['ref'], 'status' => 'insufficient', 'missing' => ['rating_latest'],
                        'probability' => null, 'score' => null, 'tier' => null, 'basis' => null, 'factors' => null, 'warnings' => []];
                }

                $p = $probability($features);

                return [
                    'ref' => $instance['ref'],
                    'status' => 'scored',
                    'probability' => $p,
                    'score' => round(min(99.0, $p * 400), 1),
                    'tier' => $p >= 0.2 ? 'high' : ($p >= 0.1 ? 'medium' : 'low'),
                    'basis' => isset($features['rating_change']) ? 'two_appraisals' : 'latest_appraisal',
                    'factors' => [['feature' => 'rating_latest', 'label' => 'Latest appraisal', 'impact' => 6.0, 'direction' => 'up']],
                    'warnings' => [],
                ];
            })->all();

            return Http::response(['model' => 'promotion', 'model_version' => 'LogisticRegression@test', 'results' => $results, 'warnings' => $warnings]);
        },
    ]);
}

/** A review period that ended `$endedMonthsAgo` months ago (negative: still running). */
function reviewPeriod(string $name, int $endedMonthsAgo, string $status = 'closed'): EvaluationPeriod
{
    return EvaluationPeriod::factory()->create([
        'name' => $name,
        'start_date' => today()->subMonths($endedMonthsAgo + 12),
        'end_date' => today()->subMonths($endedMonthsAgo),
        'status' => $status,
    ]);
}

/** An appraisal with a result of `$percent` attainment. */
function appraise(Employee $employee, EvaluationPeriod $period, float $percent, string $status = 'acknowledged'): PerformanceEvaluation
{
    return PerformanceEvaluation::create([
        'employee_id' => $employee->id,
        'evaluation_period_id' => $period->id,
        'overall_percent' => $percent,
        'overall_score' => round(1 + $percent / 25, 2),
        'status' => $status,
        'submitted_at' => $status === 'draft' ? null : now(),
    ]);
}

/**
 * The features the assessor would send for this employee.
 *
 * @return array<string, float>
 */
function promotionFeatures(Employee $employee): array
{
    $loaded = Employee::query()
        ->whereKey($employee->id)
        ->with(['performanceEvaluations' => fn ($query) => $query->with('period:id,name,start_date,end_date')])
        ->sole();

    return app(PromotionFeatureMapper::class)->features($loaded);
}

// ── The overview ─────────────────────────────────────────────────────────────

test('the overview renders an empty state before any assessment', function () {
    actingAsSuperAdmin();
    fakePromotionService();

    $this->get(route('analytics.promotion-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('analytics/promotion-readiness')
            ->where('run', null)
            ->where('service.connected', true));
});

test('the service only counts as connected when it has the promotion model loaded', function () {
    actingAsSuperAdmin();
    fakePromotionService(models: ['attrition']);

    $this->get(route('analytics.promotion-readiness.index'))
        ->assertInertia(fn (Assert $page) => $page->where('service.connected', false));
});

test('the overview ranks the run and lists who was not assessed, and why', function () {
    actingAsSuperAdmin();
    fakePromotionService(probability: fn (array $f): float => $f['rating_latest'] / 400);

    $fy = reviewPeriod('FY 2025', 9);
    $h1 = reviewPeriod('H1 2026', 3);
    $strong = Employee::factory()->create(['first_name' => 'Strong']);
    $steady = Employee::factory()->create(['first_name' => 'Steady']);
    appraise($strong, $fy, 70.0);
    appraise($strong, $h1, 88.0);
    appraise($steady, $fy, 64.0);
    $drafting = Employee::factory()->create(['first_name' => 'Drafting']);
    appraise($drafting, $h1, 90.0, 'draft');
    Employee::factory()->create(['first_name' => 'Newcomer']);

    $this->post(route('analytics.promotion-readiness.store'));

    $this->get(route('analytics.promotion-readiness.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('run.employees_scored', 2)
            ->where('run.scores.0.employee.full_name', fn (string $name) => str_starts_with($name, 'Strong'))
            ->where('run.scores.0.basis', 'two_appraisals')
            ->where('run.scores.0.features.rating_latest', 88)
            ->where('run.scores.0.features.rating_change', 18)
            ->where('run.scores.0.history.0.label', 'FY 2025')
            ->where('run.scores.0.history.1.label', 'H1 2026')
            ->where('run.scores.1.basis', 'latest_appraisal')
            ->has('run.unassessed', 2)
            ->where('run.unassessed', fn ($unassessed) => collect($unassessed)
                ->mapWithKeys(fn ($u) => [explode(' ', $u['employee']['full_name'])[0] => $u['reason']])
                ->all() == ['Drafting' => AppraisalHistory::APPRAISAL_IN_PROGRESS, 'Newcomer' => AppraisalHistory::NO_APPRAISAL])
            ->missing('run.model_version'));
});

// ── Running an assessment ────────────────────────────────────────────────────

test('an assessment scores those with a completed appraisal and records the rest', function () {
    $user = actingAsSuperAdmin();
    fakePromotionService(probability: fn (array $f): float => $f['rating_latest'] >= 80 ? 0.3 : 0.05);

    $period = reviewPeriod('FY 2025', 6);
    appraise(Employee::factory()->create(), $period, 85.0);
    appraise(Employee::factory()->create(), $period, 60.0);
    Employee::factory()->create();
    Employee::factory()->create(['employment_status' => 'resigned']);

    $this->post(route('analytics.promotion-readiness.store'))
        ->assertRedirect(route('analytics.promotion-readiness.index'));

    assertToast('success', '2 employees scored. 1 with no completed appraisal were left out.');

    $run = PromotionReadinessRun::sole();
    expect($run->employees_scored)->toBe(2)
        ->and($run->high_count)->toBe(1)
        ->and($run->low_count)->toBe(1)
        ->and($run->unassessed)->toHaveCount(1)
        ->and($run->generated_by)->toBe($user->id)
        ->and(PromotionReadinessScore::count())->toBe(2)
        ->and(ActivityLog::query()->where('log_name', 'promotion-readiness')->where('event', 'generated')
            ->where('description', 'like', '%1 not assessed%')->exists())->toBeTrue();
});

test('the request carries only the appraisal record — no department, pay or protected attribute', function () {
    actingAsSuperAdmin();
    fakePromotionService();

    $employee = Employee::factory()->create(['basic_salary' => 45000, 'gender' => 'female']);
    appraise($employee, reviewPeriod('FY 2024', 18), 66.5);
    appraise($employee, reviewPeriod('FY 2025', 6), 72.0);

    $this->post(route('analytics.promotion-readiness.store'));

    Http::assertSent(function (Request $request) use ($employee): bool {
        if (! str_ends_with($request->url(), '/predict/promotion')) {
            return false;
        }

        $features = $request->data()['instances'][0]['features'];

        return $request->data()['instances'][0]['ref'] === (string) $employee->id
            && $features == ['rating_latest' => 72.0, 'rating_change' => 5.5];
    });
});

test('an employee with nothing to send goes as an empty object, not a list', function () {
    actingAsSuperAdmin();
    fakePromotionService();
    Employee::factory()->create();

    $this->post(route('analytics.promotion-readiness.store'));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/predict/promotion')
        && str_contains($request->body(), '"features":{}'));
});

test('an employee the model declines is recorded as not assessed, never as a score', function () {
    actingAsSuperAdmin();
    fakePromotionService();
    Employee::factory()->create();

    $this->post(route('analytics.promotion-readiness.store'));

    $run = PromotionReadinessRun::sole();
    expect($run->employees_scored)->toBe(0)
        ->and(PromotionReadinessScore::count())->toBe(0)
        ->and($run->unassessed[0]['reason'])->toBe(AppraisalHistory::NO_APPRAISAL);
});

test('a contract mismatch reported by the service is logged', function () {
    actingAsSuperAdmin();
    fakePromotionService(warnings: ["Ignored inputs the 'promotion' model does not read: salary."]);
    Log::spy();
    appraise(Employee::factory()->create(), reviewPeriod('FY 2025', 6), 70.0);

    $this->post(route('analytics.promotion-readiness.store'));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'contract mismatch'))->once();
});

test('an unreachable service leaves a warning and records nothing', function () {
    actingAsSuperAdmin();
    Employee::factory()->create();
    Http::fake(['*' => Http::failedConnection()]);

    $this->post(route('analytics.promotion-readiness.store'))->assertRedirect();

    assertToast('warning', 'temporarily unavailable');
    expect(PromotionReadinessRun::count())->toBe(0);
});

test('an assessment only ever reads the current organisation', function () {
    actingAsSuperAdmin();
    fakePromotionService();

    $ours = Employee::factory()->create();
    appraise($ours, reviewPeriod('FY 2025', 6), 70.0);
    Employee::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->post(route('analytics.promotion-readiness.store'));

    $run = PromotionReadinessRun::sole();
    expect(PromotionReadinessScore::pluck('employee_id')->all())->toBe([$ours->id])
        ->and($run->unassessed)->toBeNull();
});

// ── The feature mapper ───────────────────────────────────────────────────────

test('the mapper reads attainment, not the 1–5 index', function () {
    testOrganization();
    $employee = Employee::factory()->create();
    appraise($employee, reviewPeriod('FY 2025', 6), 65.17);

    expect(promotionFeatures($employee))->toBe(['rating_latest' => 65.17]);
});

test('the mapper orders appraisals by when their period ended, not by when they were entered', function () {
    testOrganization();
    $employee = Employee::factory()->create();
    // Entered newest-first: the later period's appraisal has the lower id.
    appraise($employee, reviewPeriod('FY 2025', 6), 80.0);
    appraise($employee, reviewPeriod('FY 2024', 18), 60.0);

    expect(promotionFeatures($employee))->toBe(['rating_latest' => 80.0, 'rating_change' => 20.0]);
});

test('the mapper never reads a draft', function () {
    testOrganization();
    $employee = Employee::factory()->create();
    appraise($employee, reviewPeriod('FY 2025', 6), 70.0);
    appraise($employee, reviewPeriod('H1 2026', -1, 'open'), 40.0, 'draft');

    expect(promotionFeatures($employee))->toBe(['rating_latest' => 70.0]);
});

test('the mapper sends no change when there is only one appraisal, rather than inventing one', function () {
    testOrganization();
    $employee = Employee::factory()->create();
    appraise($employee, reviewPeriod('FY 2025', 6), 77.0, 'submitted');

    expect(promotionFeatures($employee))->not->toHaveKey('rating_change');
});

// ── Deleting and permissions ─────────────────────────────────────────────────

test('deleting a run removes its scores and is logged', function () {
    actingAsSuperAdmin();
    fakePromotionService();
    appraise(Employee::factory()->create(), reviewPeriod('FY 2025', 6), 70.0);

    $this->post(route('analytics.promotion-readiness.store'));
    $run = PromotionReadinessRun::sole();

    $this->delete(route('analytics.promotion-readiness.destroy', $run))
        ->assertRedirect(route('analytics.promotion-readiness.index'));

    expect(PromotionReadinessRun::count())->toBe(0)
        ->and(PromotionReadinessScore::count())->toBe(0)
        ->and(ActivityLog::query()->where('log_name', 'promotion-readiness')->where('event', 'deleted')->exists())->toBeTrue();
});

test('viewing needs analytics.promotion.view, and running needs manage', function () {
    actingAsUserWith(['analytics.promotion.view']);
    fakePromotionService();

    $this->get(route('analytics.promotion-readiness.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
    $this->post(route('analytics.promotion-readiness.store'))->assertForbidden();

    actingAsUserWith([]);
    $this->get(route('analytics.promotion-readiness.index'))->assertForbidden();
});
