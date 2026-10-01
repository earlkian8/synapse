<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecast;
use App\Models\PerformanceForecastRun;
use App\Support\Ml\AppraisalHistory;
use App\Support\Ml\ForecastTrackRecord;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Performance Forecast (ADR 0045): a forecast of a period reads only the
 * appraisals completed for periods that ended before it began, and stores what
 * the model says — the rating, the range four in five next ratings land in, the
 * band and the chance that band is right. Employees with nothing to forecast from
 * are recorded with the reason. Once the period's appraisals are completed, the
 * run is checked against them. The service is faked at the HTTP boundary.
 */

/**
 * Fake the inference service the way the real performance model behaves: an
 * instance without `rating_latest` is declined; the rest are forecast two points
 * up, ±7.5, with a fixed confidence.
 *
 * @param  list<string>  $models
 */
function fakeForecastService(array $models = ['performance']): void
{
    Http::fake([
        '*/health' => Http::response([
            'status' => 'ok',
            'service' => 'synapse-ml-inference',
            'models' => collect($models)->mapWithKeys(fn (string $m): array => [$m => ['kind' => 'regressor', 'version' => null, 'feature_count' => 1, 'metrics' => []]])->all(),
        ]),
        '*/predict/performance' => function (Request $request) {
            $results = collect($request->data()['instances'])->map(function (array $instance): array {
                $latest = $instance['features']['rating_latest'] ?? null;

                if ($latest === null) {
                    return ['ref' => $instance['ref'], 'status' => 'insufficient', 'missing' => ['rating_latest'],
                        'score' => null, 'interval' => null, 'band' => null, 'confidence' => null, 'warnings' => []];
                }

                $point = $latest + 2;

                return [
                    'ref' => $instance['ref'],
                    'status' => 'scored',
                    'score' => $point,
                    'interval' => ['low' => $point - 7.5, 'high' => $point + 7.5, 'coverage' => 0.8],
                    'band' => PerformanceForecast::bandFor($point),
                    'confidence' => 0.85,
                    'basis' => 'latest_appraisal',
                    'warnings' => [],
                ];
            })->all();

            return Http::response(['model' => 'performance', 'model_version' => 'HGB@test', 'results' => $results, 'warnings' => []]);
        },
    ]);
}

/** A review period from `$start` to `$end` (Y-m-d). */
function cycle(string $name, string $start, string $end, string $status = 'closed'): EvaluationPeriod
{
    return EvaluationPeriod::factory()->create(['name' => $name, 'start_date' => $start, 'end_date' => $end, 'status' => $status]);
}

function rate(Employee $employee, EvaluationPeriod $period, float $percent, string $status = 'acknowledged'): PerformanceEvaluation
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

/** @return array<string, mixed>|null */
function sentForecastFeatures(Employee $employee): ?array
{
    $sent = null;

    Http::assertSent(function (Request $request) use ($employee, &$sent): bool {
        if (! str_ends_with($request->url(), '/predict/performance')) {
            return false;
        }

        foreach ($request->data()['instances'] as $instance) {
            if ($instance['ref'] === (string) $employee->id) {
                $sent = $instance['features'];
            }
        }

        return true;
    });

    return $sent;
}

// ── The forecast window ──────────────────────────────────────────────────────

test('a forecast never reads the appraisal of the period it forecasts', function () {
    actingAsSuperAdmin();
    fakeForecastService();

    $fy = cycle('FY 2025', '2025-01-01', '2025-12-31');
    $h1 = cycle('H1 2026', '2026-01-01', '2026-06-30', 'open');
    $employee = Employee::factory()->create();
    rate($employee, $fy, 65.17);
    rate($employee, $h1, 58.33, 'draft');

    $this->post(route('analytics.performance-forecast.store'));

    expect(sentForecastFeatures($employee))->toBe(['rating_latest' => 65.17]);

    $run = PerformanceForecastRun::sole();
    expect($run->target_period_id)->toBe($h1->id)
        ->and($run->forecasts()->sole()->history)->toBe([['label' => 'FY 2025', 'rating' => 65.17]]);
});

test('an employee appraised only in the forecast period is not forecast, and says why', function () {
    actingAsSuperAdmin();
    fakeForecastService();

    cycle('FY 2025', '2025-01-01', '2025-12-31');
    $h1 = cycle('H1 2026', '2026-01-01', '2026-06-30', 'open');
    $onlyNow = Employee::factory()->create();
    rate($onlyNow, $h1, 72.0, 'submitted');
    $drafting = Employee::factory()->create();
    rate($drafting, $h1, 72.0, 'draft');
    $newcomer = Employee::factory()->create();

    $this->post(route('analytics.performance-forecast.store'));

    assertToast('success', '0 employees projected. 3 with no appraisal to forecast from were left out.');
    $reasons = collect(PerformanceForecastRun::sole()->unassessed)->pluck('reason', 'employee_id');

    expect($reasons[$onlyNow->id])->toBe(AppraisalHistory::NONE_BEFORE_PERIOD)
        ->and($reasons[$drafting->id])->toBe(AppraisalHistory::APPRAISAL_IN_PROGRESS)
        ->and($reasons[$newcomer->id])->toBe(AppraisalHistory::NO_APPRAISAL)
        ->and(PerformanceForecast::count())->toBe(0);
});

test('with no upcoming period a forecast reads the latest completed appraisal', function () {
    actingAsSuperAdmin();
    fakeForecastService();

    $employee = Employee::factory()->create();
    rate($employee, cycle('FY 2024', '2024-01-01', '2024-12-31'), 60.0);
    rate($employee, cycle('FY 2025', '2025-01-01', '2025-12-31'), 74.0);

    $this->post(route('analytics.performance-forecast.store'));

    expect(sentForecastFeatures($employee))->toEqual(['rating_latest' => 74.0])
        ->and(PerformanceForecastRun::sole()->target_period_id)->toBeNull();
});

// ── What is stored ───────────────────────────────────────────────────────────

test('a forecast stores the model’s rating, range, band and confidence as given', function () {
    actingAsSuperAdmin();
    fakeForecastService();

    $fy = cycle('FY 2025', '2025-01-01', '2025-12-31');
    cycle('H1 2026', '2026-01-01', '2026-06-30', 'open');
    rate($high = Employee::factory()->create(), $fy, 88.0);
    rate($low = Employee::factory()->create(), $fy, 50.0);

    $this->post(route('analytics.performance-forecast.store'))
        ->assertRedirect(route('analytics.performance-forecast.index'));

    $forecast = PerformanceForecast::query()->where('employee_id', $high->id)->sole();
    expect((float) $forecast->predicted_rating)->toBe(90.0)
        ->and((float) $forecast->predicted_low)->toBe(82.5)
        ->and((float) $forecast->predicted_high)->toBe(97.5)
        ->and($forecast->band)->toBe('exceeds')
        ->and((float) $forecast->confidence)->toBe(0.85);

    $run = PerformanceForecastRun::sole();
    expect($run->exceeds_count)->toBe(1)
        ->and($run->below_count)->toBe(1)
        ->and(ActivityLog::query()->where('log_name', 'performance-forecast')->where('event', 'generated')->exists())->toBeTrue();
});

test('the service only counts as connected when it has the performance model loaded', function () {
    actingAsSuperAdmin();
    fakeForecastService(models: ['promotion']);

    $this->get(route('analytics.performance-forecast.index'))
        ->assertInertia(fn (Assert $page) => $page->where('service.connected', false));
});

// ── The track record ─────────────────────────────────────────────────────────

test('once the period is appraised, the page checks the forecast against what happened', function () {
    actingAsSuperAdmin();
    fakeForecastService();

    $fy = cycle('FY 2025', '2025-01-01', '2025-12-31');
    $h1 = cycle('H1 2026', '2026-01-01', '2026-06-30', 'open');
    $hit = Employee::factory()->create();
    $miss = Employee::factory()->create();
    $pending = Employee::factory()->create();
    foreach ([$hit, $miss, $pending] as $employee) {
        rate($employee, $fy, 70.0);
    }

    $this->post(route('analytics.performance-forecast.store'));

    $this->get(route('analytics.performance-forecast.index'))
        ->assertInertia(fn (Assert $page) => $page->where('track_record.checked', 0)->where('track_record.forecasts', 3));

    rate($hit, $h1, 74.0);      // forecast 72 [64.5–79.5]: inside, same band
    rate($miss, $h1, 55.0);     // outside, and below rather than on track
    rate($pending, $h1, 90.0, 'draft');   // not completed: not checked

    $this->get(route('analytics.performance-forecast.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('track_record.checked', 2)
            ->where('track_record.mean_error', 9.5)
            ->where('track_record.within_range', 0.5)
            ->where('track_record.band_right', 0.5)
            ->where('track_record.expected_band_right', 0.85)
            ->where("track_record.actuals.{$hit->id}", 74)
            ->missing("track_record.actuals.{$pending->id}"));
});

test('a run that forecast no period has no track record', function () {
    testOrganization();

    expect(app(ForecastTrackRecord::class)->for(PerformanceForecastRun::create(['status' => 'completed'])))->toBeNull();
});

// ── Permissions ──────────────────────────────────────────────────────────────

test('viewing needs analytics.performance.view, and running needs manage', function () {
    actingAsUserWith(['analytics.performance.view']);
    fakeForecastService();

    $this->get(route('analytics.performance-forecast.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
    $this->post(route('analytics.performance-forecast.store'))->assertForbidden();

    actingAsUserWith([]);
    $this->get(route('analytics.performance-forecast.index'))->assertForbidden();
});
