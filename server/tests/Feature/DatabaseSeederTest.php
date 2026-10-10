<?php

use App\Models\AppraisalReview;
use App\Models\AttritionRiskRun;
use App\Models\CalibrationSession;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceGoal;
use App\Models\RecruitmentPipeline;
use App\Models\Role;
use App\Models\User;
use App\Support\Ml\Graduation\ModelGraduation;
use App\Support\Performance\PerformanceScorer;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The demo seed actually runs, and produces a workspace that holds together.
 *
 * Nothing else in the suite exercises the seeders — every other test builds its
 * own data from factories — so a schema change can (and did) leave the seed
 * writing a column that no longer exists, with the break only showing up the
 * next time somebody ran `migrate:fresh --seed`. This walks the whole seed and
 * asserts the invariants an alpha tester would notice first.
 *
 * Deliberately two tests, not a dataset: the seed is the expensive part (seven
 * years of workforce history, and six weeks of attendance for a 120-person
 * team), and paying for it once per assertion would dominate the suite's runtime.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    app(Tenancy::class)->set(Organization::orderBy('id')->first());
});

test('the seed produces a coherent demo workspace', function () {
    // 1. Two logins: the owner, and a plain employee for the mobile app.
    expect(User::count())->toBe(2);

    $owner = User::where('email', DatabaseSeeder::ACCOUNT_EMAIL)->sole();

    expect($owner->roles->pluck('name'))->toContain(Role::SUPER_ADMIN)
        // Both companies, so the workspace switcher is demoable (ADR 0023).
        ->and($owner->memberships()->count())->toBe(2)
        // Linked to a roster line, so the mobile self-service app resolves a self record.
        ->and($owner->employee()->exists())->toBeTrue();

    $staff = User::where('email', DatabaseSeeder::MOBILE_EMPLOYEE_EMAIL)->sole();

    expect($staff->roles->pluck('name')->all())->toBe([Role::STAFF])
        ->and($staff->memberships()->count())->toBe(1)
        // Its own roster line, so the mobile app signs in as an ordinary employee.
        ->and($staff->employee?->email)->toBe(DatabaseSeeder::MOBILE_EMPLOYEE_EMAIL)
        ->and($staff->employee->is($owner->employee))->toBeFalse();

    // 2. Every posting hires through a pipeline the module can actually drive.
    $pipelines = RecruitmentPipeline::with('stages')->get();

    expect($pipelines)->not->toBeEmpty()
        ->and($pipelines->where('is_default', true))->toHaveCount(1)
        ->and(JobPosting::whereNull('recruitment_pipeline_id')->count())->toBe(0);

    foreach ($pipelines as $pipeline) {
        expect($pipeline->entryStage())->not->toBeNull()
            ->and($pipeline->wonStage())->not->toBeNull()
            ->and($pipeline->defaultLostStage())->not->toBeNull();
    }

    // 3. Every candidate sits on a stage of their *own* posting's pipeline —
    //    the invariant the old free-string `stage` column let the seeder break.
    $applications = JobApplication::with('jobPosting.pipeline.stages', 'pipelineStage')->get();

    expect($applications)->not->toBeEmpty();

    foreach ($applications as $application) {
        expect($application->pipelineStage)->not->toBeNull()
            ->and($application->jobPosting->pipeline->stages->pluck('id'))
            ->toContain($application->recruitment_pipeline_stage_id);
    }

    // 4. The shapes the modules have to survive, not just the happy path: a
    //    posting that opted out of both a résumé and automatic ranking (the
    //    generic case ADR 0029 exists for), postings that ask their own
    //    questions, the full posting lifecycle, and a hire that reached the
    //    roster the way the recruitment → workforce bridge leaves it.
    expect(JobPosting::where('use_fit_scoring', false)->where('requires_resume', false)->exists())->toBeTrue()
        ->and(JobPosting::has('screeningQuestions')->exists())->toBeTrue()
        ->and(JobPosting::pluck('status')->unique()->values()->all())->toContain('draft', 'open', 'filled')
        ->and(JobApplication::whereNotNull('hired_employee_id')->exists())->toBeTrue();

    // 5. The mobile demo employee can sign in to the mobile app.
    $this->postJson(route('api.auth.login'), [
        'email' => DatabaseSeeder::MOBILE_EMPLOYEE_EMAIL,
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertOk()
        ->assertJsonPath('user.organization.id', Organization::orderBy('id')->value('id'));

    // 6. The workforce history is enough for all three predictive surfaces to
    //    graduate: every requirement is met on every surface.
    foreach (array_keys(ModelGraduation::SURFACES) as $model) {
        $check = app(ModelGraduation::class)->check($model, serviceReady: true);

        expect($check['gate_open'])->toBeTrue("{$model}: ".collect($check['requirements'])
            ->reject(fn (array $r): bool => $r['status'] === 'met')->pluck('key')->join(', '));
    }

    // 7. People came and went — through Offboarding, so every departure is dated
    //    and typed — and about 120 are on the roster today.
    $departed = Employee::query()->whereIn('employment_status', ['resigned', 'terminated']);

    expect((clone $departed)->count())->toBeGreaterThan(100)
        ->and((clone $departed)->whereDoesntHave('offboardingCase', fn ($q) => $q->where('status', 'completed'))->count())->toBe(0)
        ->and(Employee::where('employment_status', 'active')->count())->toBeBetween(100, 140);

    // 8. The history's appraisals are real scorecards: the stored result is what
    //    the scorer derives from their lines.
    $history = PerformanceEvaluation::query()
        ->where('evaluation_period_id', EvaluationPeriod::where('name', 'FY 2019 Annual Review')->value('id'))
        ->with('scores')
        ->limit(5)
        ->get();

    expect($history)->toHaveCount(5);

    foreach ($history as $appraisal) {
        $rescored = app(PerformanceScorer::class)->score($appraisal->scores, $appraisal->bandList());

        expect($appraisal->scores)->not->toBeEmpty()
            ->and((float) $appraisal->overall_percent)->toBe((float) $rescored->percent);
    }

    // 9. A risk assessment every March and September, September 2019 to
    //    September 2025, each holding the record as it stood.
    expect(AttritionRiskRun::count())->toBe(13)
        ->and(AttritionRiskRun::oldest('created_at')->first()->scores()->first()->features)
        ->toHaveKeys(['tenure_years', 'monthly_salary', 'years_since_promotion', 'absences_90d', 'overtime_hours_90d']);

    // 10. The staff login has something to do in Performance (ADRs 0072, 0073):
    //     last year's appraisal shared and waiting for them, a self-review and a
    //     colleague's review to write, and goals of their own. The open cycle's
    //     submitted results are held by an open calibration session.
    $self = $staff->employee;
    $shared = PerformanceEvaluation::where('employee_id', $self->id)->where('status', 'submitted')->sole();

    expect($shared->isShared())->toBeTrue()
        ->and($shared->acknowledged_at)->toBeNull()
        ->and(AppraisalReview::where('reviewer_id', $self->id)->where('status', 'pending')->pluck('relationship')->all())
        ->toHaveCount(2)->toContain('self')
        ->and(PerformanceGoal::where('employee_id', $self->id)->count())->toBe(3);

    $session = CalibrationSession::where('status', 'open')->sole();

    expect($session->adjustments()->count())->toBe(1)
        ->and($session->evaluations()->where('status', 'submitted')->whereNull('shared_at')->count())->toBeGreaterThan(0);
});

test('the seeded workspace renders for the account it was seeded for', function () {
    $this->actingAs(User::where('email', DatabaseSeeder::ACCOUNT_EMAIL)->sole());
    Http::fake(['*/health' => Http::response(['status' => 'ok', 'service' => 'synapse-ml-inference', 'models' => []])]);

    // The surfaces the seed is the sole source of data for. Every other page is
    // walked against factory data by PageSmokeTest.
    $pages = [
        'recruitment.index' => 'recruitment/index',
        'setup.recruitment-pipelines.index' => 'setup/recruitment-pipelines',
        'employees.index' => 'employees/index',
        'performance.index' => 'performance/index',
        'system.users.index' => 'system/users/index',
        // Their graduation panels count the seeded history.
        'analytics.promotion-readiness.index' => 'analytics/promotion-readiness',
        'analytics.performance-forecast.index' => 'analytics/performance-forecast',
        'analytics.attrition.index' => 'analytics/attrition',
    ];

    foreach ($pages as $name => $component) {
        $this->get(route($name))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    $session = CalibrationSession::where('status', 'open')->sole();

    $this->get(route('performance.goals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('performance/goals'));
    $this->get(route('performance.calibration.show', $session))->assertOk()->assertInertia(fn (Assert $page) => $page->component('performance/calibration-session'));

    // The staff login's own side of Performance.
    $this->actingAs(User::where('email', DatabaseSeeder::MOBILE_EMPLOYEE_EMAIL)->sole());

    $this->get(route('performance.me'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('performance/me')->where('nav.reviews', 2));
    $this->get(route('performance.me.goals'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('performance/my-goals')->has('goals', 3));
});
