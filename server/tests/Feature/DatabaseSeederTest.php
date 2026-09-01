<?php

use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Models\RecruitmentPipeline;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
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
 * Deliberately two tests, not a dataset: the seed is the expensive part, and
 * paying for it once per assertion would dominate the suite's runtime.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    app(Tenancy::class)->set(Organization::orderBy('id')->first());
});

test('the seed produces a coherent demo workspace', function () {
    // 1. One login, and it owns the place.
    expect(User::count())->toBe(1);

    $owner = User::sole();

    expect($owner->email)->toBe(DatabaseSeeder::ACCOUNT_EMAIL)
        ->and($owner->roles->pluck('name'))->toContain(Role::SUPER_ADMIN)
        // Both companies, so the workspace switcher is demoable (ADR 0023).
        ->and($owner->memberships()->count())->toBe(2)
        // Linked to a roster line, so the mobile self-service app resolves a self record.
        ->and($owner->employee()->exists())->toBeTrue();

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
});

test('the seeded workspace renders for the account it was seeded for', function () {
    $this->actingAs(User::sole());

    // The surfaces the seed is the sole source of data for. Every other page is
    // walked against factory data by PageSmokeTest.
    $pages = [
        'recruitment.index' => 'recruitment/index',
        'setup.recruitment-pipelines.index' => 'setup/recruitment-pipelines',
        'employees.index' => 'employees/index',
        'performance.index' => 'performance/index',
        'system.users.index' => 'system/users/index',
    ];

    foreach ($pages as $name => $component) {
        $this->get(route($name))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }
});
