<?php

use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\AttritionRiskRun;
use App\Models\AttritionRiskScore;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Support\Ml\AttritionFeatureMapper;
use App\Support\OrganizationProvisioner;
use App\Support\Reports\MlSignals;
use App\Support\Tenancy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Attrition Risk (ADR 0043): an assessment maps every active employee's record to
 * the survey-trained model's eight inputs, scores them through the inference
 * service, and persists the run. The service is faked at the HTTP boundary, so
 * everything on the Laravel side of it — controller, assessor, mapper, client,
 * persistence — runs for real.
 */

/**
 * Fake the inference service: /health reports the given models as loaded, and
 * /predict/attrition echoes each instance back with a probability chosen by
 * `$probability` (from its features), tiered the way the service tiers.
 *
 * @param  list<string>  $models
 */
function fakeMlService(array $models = ['attrition'], ?Closure $probability = null): void
{
    $probability ??= fn (array $features): float => 0.5;

    Http::fake([
        '*/health' => Http::response([
            'status' => 'ok',
            'service' => 'synapse-ml-inference',
            'models' => collect($models)->mapWithKeys(fn (string $m): array => [$m => ['kind' => 'classifier', 'version' => null, 'feature_count' => 8, 'metrics' => []]])->all(),
        ]),
        '*/predict/attrition' => function (Request $request) use ($probability) {
            $results = collect($request->data()['instances'])->map(function (array $instance) use ($probability): array {
                $p = $probability($instance['features']);

                return [
                    'ref' => $instance['ref'],
                    'probability' => $p,
                    'score' => round($p * 100, 1),
                    'tier' => $p < 0.33 ? 'low' : ($p < 0.66 ? 'medium' : 'high'),
                    'factors' => [['feature' => 'absences_90d', 'label' => 'Absences (last 90 days)', 'impact' => 0.8, 'direction' => 'up']],
                ];
            })->all();

            return Http::response(['model' => 'attrition', 'model_version' => 'LogisticRegression@test', 'results' => $results]);
        },
    ]);
}

/** A day on an employee's attendance record, `$daysAgo` days back. */
function attendanceDay(Employee $employee, int $daysAgo, array $attributes = []): AttendanceRecord
{
    return AttendanceRecord::factory()->create([
        'employee_id' => $employee->id,
        'work_date' => today()->subDays($daysAgo)->toDateString(),
        ...$attributes,
    ]);
}

// ── The overview ─────────────────────────────────────────────────────────────

test('the overview renders an empty state before any assessment', function () {
    actingAsSuperAdmin();
    fakeMlService();

    $this->get(route('analytics.attrition.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('analytics/attrition')
            ->where('run', null)
            ->where('runs', [])
            ->where('service.connected', true)
            ->where('can.manage', true));
});

test('the service only counts as connected when it has the attrition model loaded', function () {
    actingAsSuperAdmin();
    fakeMlService(models: ['promotion', 'performance']);

    $this->get(route('analytics.attrition.index'))
        ->assertInertia(fn (Assert $page) => $page->where('service.connected', false));
});

test('the overview shows the newest run, ranked highest risk first, and can switch to an older one', function () {
    actingAsSuperAdmin();
    fakeMlService(probability: fn (array $f): float => ($f['absences_90d'] ?? 0) > 3 ? 0.8 : 0.2);

    $steady = Employee::factory()->create(['first_name' => 'Steady']);
    $absent = Employee::factory()->create(['first_name' => 'Absent']);
    attendanceDay($steady, 3);
    foreach (range(1, 5) as $day) {
        attendanceDay($absent, $day, ['status' => 'absent']);
    }

    $this->post(route('analytics.attrition.store'));
    $older = AttritionRiskRun::query()->latestFirst()->first();
    $this->post(route('analytics.attrition.store'));

    $this->get(route('analytics.attrition.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 2)
            ->where('run.employees_scored', 2)
            ->where('run.high_count', 1)
            ->where('run.scores.0.employee.full_name', fn (string $name) => str_starts_with($name, 'Absent'))
            ->where('run.scores.0.tier', 'high')
            ->where('run.scores.0.factors.0.feature', 'absences_90d')
            ->where('run.scores.0.features.absences_90d', 5)
            ->missing('run.model_version'));

    $this->get(route('analytics.attrition.index', ['run' => $older->hashid]))
        ->assertInertia(fn (Assert $page) => $page->where('run.hashid', $older->hashid));
});

// ── Running an assessment ────────────────────────────────────────────────────

test('an assessment scores every active employee and records the run', function () {
    $user = actingAsSuperAdmin();
    fakeMlService(probability: fn (array $f): float => match ($f['employment_type'] ?? null) {
        'contractual' => 0.9,
        'part_time' => 0.5,
        default => 0.1,
    });

    Employee::factory()->create(['employment_type' => 'contractual']);
    Employee::factory()->create(['employment_type' => 'part_time']);
    Employee::factory()->count(2)->create(['employment_type' => 'regular']);
    Employee::factory()->create(['employment_status' => 'resigned']);

    $this->post(route('analytics.attrition.store'))
        ->assertRedirect(route('analytics.attrition.index'));

    assertToast('success', '4 employees scored');

    $run = AttritionRiskRun::sole();
    expect($run->employees_scored)->toBe(4)
        ->and($run->high_count)->toBe(1)
        ->and($run->medium_count)->toBe(1)
        ->and($run->low_count)->toBe(2)
        ->and((float) $run->average_score)->toBe(40.0)
        ->and($run->model_version)->toBe('LogisticRegression@test')
        ->and($run->generated_by)->toBe($user->id)
        ->and($run->scores()->count())->toBe(4);

    expect(ActivityLog::query()->where('log_name', 'attrition-risk')->where('event', 'generated')->exists())->toBeTrue();
});

test('the request carries the survey inputs and never a protected attribute', function () {
    actingAsSuperAdmin();
    fakeMlService();

    $employee = Employee::factory()->create([
        'employment_type' => 'regular',
        'date_hired' => today()->subYears(4),
        'basic_salary' => 18500,
    ]);
    attendanceDay($employee, 2);

    $this->post(route('analytics.attrition.store'));

    Http::assertSent(function (Request $request) use ($employee): bool {
        if (! str_ends_with($request->url(), '/predict/attrition')) {
            return false;
        }

        $instance = $request->data()['instances'][0];

        return $instance['ref'] === (string) $employee->id
            && array_keys($instance['features']) == array_intersect(
                ['employment_type', 'tenure_years', 'monthly_salary', 'ever_promoted', 'years_since_promotion', 'absences_90d', 'lates_90d', 'overtime_hours_90d'],
                array_keys($instance['features']),
            )
            && $instance['features']['monthly_salary'] == 18500
            && ! array_intersect(array_keys($instance['features']), ['gender', 'civil_status', 'birth_date', 'age', 'marital_status']);
    });
});

test('confidence reflects how much of the record was real, not imputed', function () {
    actingAsSuperAdmin();
    fakeMlService();

    $tracked = Employee::factory()->create(['first_name' => 'Tracked']);
    attendanceDay($tracked, 5);
    Employee::factory()->create(['first_name' => 'Untracked']);

    $this->post(route('analytics.attrition.store'));

    $scores = AttritionRiskScore::with('employee')->get()->keyBy(fn ($s) => $s->employee->first_name);

    expect((float) $scores['Tracked']->confidence)->toBe(1.0)
        // No attendance in the window: the three attendance inputs are imputed.
        ->and((float) $scores['Untracked']->confidence)->toBe(0.625)
        ->and($scores['Untracked']->features)->not->toHaveKey('absences_90d');
});

test('an unreachable service leaves a warning and records nothing', function () {
    actingAsSuperAdmin();
    Employee::factory()->create();

    Http::fake(['*' => Http::failedConnection()]);

    $this->post(route('analytics.attrition.store'))->assertRedirect();

    assertToast('warning', 'temporarily unavailable');
    expect(AttritionRiskRun::count())->toBe(0);
});

test('a service error leaves a warning and records nothing', function () {
    actingAsSuperAdmin();
    Employee::factory()->create();

    Http::fake(['*/predict/attrition' => Http::response(['detail' => "Unknown model 'attrition'."], 404)]);

    $this->post(route('analytics.attrition.store'))->assertRedirect();

    assertToast('warning', "Couldn't complete the prediction");
    expect(AttritionRiskRun::count())->toBe(0);
});

test('with nobody active there is nothing to assess', function () {
    actingAsSuperAdmin();
    fakeMlService();

    $this->post(route('analytics.attrition.store'))->assertRedirect();

    assertToast('warning', 'no active employees');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/predict/'));
});

test('an assessment only ever scores the current organisation', function () {
    actingAsSuperAdmin();
    fakeMlService();

    $ours = Employee::factory()->create();
    Employee::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->post(route('analytics.attrition.store'));

    expect(AttritionRiskScore::pluck('employee_id')->all())->toBe([$ours->id]);
});

// ── Deleting a run ───────────────────────────────────────────────────────────

test('deleting a run removes its scores and is logged', function () {
    actingAsSuperAdmin();
    fakeMlService();
    Employee::factory()->count(2)->create();

    $this->post(route('analytics.attrition.store'));
    $run = AttritionRiskRun::sole();

    $this->delete(route('analytics.attrition.destroy', $run))
        ->assertRedirect(route('analytics.attrition.index'));

    assertToast('success', 'deleted');
    expect(AttritionRiskRun::count())->toBe(0)
        ->and(AttritionRiskScore::count())->toBe(0)
        ->and(ActivityLog::query()->where('log_name', 'attrition-risk')->where('event', 'deleted')->exists())->toBeTrue();
});

// ── Permissions ──────────────────────────────────────────────────────────────

test('viewing needs analytics.attrition.view', function () {
    actingAsUserWith([]);

    $this->get(route('analytics.attrition.index'))->assertForbidden();
});

test('a viewer sees the page but cannot run or delete an assessment', function () {
    actingAsUserWith(['analytics.attrition.view']);
    fakeMlService();

    $this->get(route('analytics.attrition.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));

    $this->post(route('analytics.attrition.store'))->assertForbidden();

    $run = AttritionRiskRun::create(['status' => 'completed']);
    $this->delete(route('analytics.attrition.destroy', $run))->assertForbidden();
    expect(AttritionRiskRun::count())->toBe(1);
});

test('a run from another organisation cannot be deleted', function () {
    actingAsSuperAdmin();

    $foreign = Organization::factory()->create();
    $run = AttritionRiskRun::create(['organization_id' => $foreign->id, 'status' => 'completed']);

    $this->delete(route('analytics.attrition.destroy', $run->hashid))->assertNotFound();
    expect(AttritionRiskRun::withoutGlobalScopes()->count())->toBe(1);
});

// ── The feature mapper ───────────────────────────────────────────────────────

/**
 * Load an employee exactly the way the assessor does, then map it.
 *
 * @return array<string, float|int|string>
 */
function mappedFeatures(Employee $employee): array
{
    $since = today()->subDays(AttritionFeatureMapper::WINDOW_DAYS)->toDateString();
    $until = today()->toDateString();
    $window = fn ($query) => $query->whereBetween('work_date', [$since, $until]);

    $loaded = Employee::query()
        ->whereKey($employee->id)
        ->with(['promotions' => fn ($query) => $query->orderByDesc('effective_date')])
        ->withCount([
            'attendanceRecords as attendance_days_90d' => $window,
            'attendanceRecords as absences_90d' => fn ($query) => $window($query)->where('status', 'absent'),
            'attendanceRecords as lates_90d' => fn ($query) => $window($query)->where('late_minutes', '>', 0),
        ])
        ->withSum(['attendanceRecords as overtime_minutes_90d' => $window], 'overtime_minutes')
        ->sole();

    return app(AttritionFeatureMapper::class)->features($loaded);
}

test('the mapper counts the last 90 days of absences, lates and overtime', function () {
    testOrganization();
    $employee = Employee::factory()->create();

    attendanceDay($employee, 1, ['status' => 'absent']);
    attendanceDay($employee, 2, ['status' => 'absent']);
    attendanceDay($employee, 3, ['status' => 'late', 'late_minutes' => 12]);
    // A day that ended as a half day still started late.
    attendanceDay($employee, 4, ['status' => 'half_day', 'late_minutes' => 150]);
    attendanceDay($employee, 5, ['overtime_minutes' => 90]);
    attendanceDay($employee, 6, ['overtime_minutes' => 45]);
    // Leave and rest days are not absences.
    attendanceDay($employee, 7, ['status' => 'on_leave']);
    attendanceDay($employee, 8, ['status' => 'day_off']);
    // Outside the window.
    attendanceDay($employee, 120, ['status' => 'absent', 'late_minutes' => 30, 'overtime_minutes' => 600]);

    $features = mappedFeatures($employee);

    expect($features['absences_90d'])->toBe(2)
        ->and($features['lates_90d'])->toBe(2)
        ->and($features['overtime_hours_90d'])->toBe(2.25);
});

test('the mapper sends no attendance at all for an employee whose attendance is not tracked', function () {
    testOrganization();
    $employee = Employee::factory()->create();
    attendanceDay($employee, 200, ['status' => 'absent']);

    expect(mappedFeatures($employee))
        ->not->toHaveKeys(['absences_90d', 'lates_90d', 'overtime_hours_90d']);
});

test('the mapper treats never having been promoted as waiting the whole tenure', function () {
    testOrganization();
    $employee = Employee::factory()->create([
        'date_hired' => today()->subYears(3)->subMonths(6),
        'employment_type' => 'probationary',
    ]);

    $features = mappedFeatures($employee);

    expect($features['ever_promoted'])->toBe(0)
        ->and($features['years_since_promotion'])->toBe($features['tenure_years'])
        ->and($features['tenure_years'])->toEqualWithDelta(3.5, 0.01)
        ->and($features['employment_type'])->toBe('probationary');
});

test('the mapper measures time since the latest promotion', function () {
    testOrganization();
    $employee = Employee::factory()->create(['date_hired' => today()->subYears(8)]);
    $employee->promotions()->create(['effective_date' => today()->subYears(5)]);
    $employee->promotions()->create(['effective_date' => today()->subYears(2)]);

    $features = mappedFeatures($employee);

    expect($features['ever_promoted'])->toBe(1)
        ->and($features['years_since_promotion'])->toEqualWithDelta(2.0, 0.01);
});

test('the mapper leaves out what the record does not hold', function () {
    testOrganization();
    // Hire date is mandatory on every employee; salary is not.
    $employee = Employee::factory()->create(['basic_salary' => null]);

    expect(mappedFeatures($employee))
        ->not->toHaveKey('monthly_salary')
        ->toHaveKeys(['tenure_years', 'years_since_promotion', 'ever_promoted']);
});

// ── Reports ──────────────────────────────────────────────────────────────────

test('reports carry the latest attrition run as a signal', function () {
    testOrganization();
    expect(app(MlSignals::class)->attrition())->toBeNull();

    AttritionRiskRun::create(['status' => 'completed', 'employees_scored' => 10, 'high_count' => 2, 'medium_count' => 3, 'low_count' => 5]);

    expect(app(MlSignals::class)->attrition())
        ->toMatchArray(['key' => 'attrition', 'value' => '2 high risk', 'href' => '/analytics/attrition'])
        ->and(app(MlSignals::class)->forGroup('Workforce')[0]['key'])->toBe('attrition');
});

test('a new organisation gives its department heads view access, and its HR manager both', function () {
    seedPermissions();
    $organization = Organization::factory()->create();
    app(Tenancy::class)->set($organization);

    OrganizationProvisioner::provisionRoles($organization);

    $granted = fn (string $role): array => Role::where('name', $role)->sole()->permissions()->pluck('name')->all();

    expect($granted(Role::DEPARTMENT_HEAD))->toContain('analytics.attrition.view')
        ->not->toContain('analytics.attrition.manage')
        ->and($granted(Role::HR_MANAGER))->toContain('analytics.attrition.view', 'analytics.attrition.manage')
        ->and($granted(Role::STAFF))->not->toContain('analytics.attrition.view');
});
