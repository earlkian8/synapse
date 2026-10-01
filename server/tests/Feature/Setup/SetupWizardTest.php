<?php

use App\Models\Department;
use App\Models\KpiCriterion;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\RatingScale;
use App\Models\RecruitmentPipeline;
use App\Models\ReviewTemplate;
use App\Models\User;
use App\Support\OrganizationProvisioner;
use App\Support\Performance\RatingModel;
use App\Support\Setup\CompanySetup;
use App\Support\Setup\SetupBlueprints;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Guided company setup: the wizard a brand-new tenant is taken through before its
 * dashboard, and the redirect that takes it there.
 */

/** Put the acting tenant back where registration leaves it: empty and unset-up. */
function unfinishedTenant(): Organization
{
    $organization = testOrganization();
    $organization->forceFill(['setup_completed_at' => null, 'setup_steps' => null])->save();

    return $organization;
}

// ── The redirect ─────────────────────────────────────────────────────────────

test('registration leaves the new company owing its setup', function () {
    $this->post(route('register.store'), [
        'organization_name' => 'Brand New Co',
        'first_name' => 'Owner',
        'last_name' => 'Person',
        'email' => 'owner@brandnew.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $organization = Organization::where('name', 'Brand New Co')->firstOrFail();

    expect($organization->setup_completed_at)->toBeNull()
        ->and($organization->hasFinishedSetup())->toBeFalse();
});

test('an owner whose company has never been set up lands on the wizard', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->get(route('dashboard'))->assertRedirect(route('setup.wizard.show'));
    $this->get(route('employees.index'))->assertRedirect(route('setup.wizard.show'));
});

test('a company that has finished setup is left alone', function () {
    actingAsSuperAdmin();

    // The factory stands for a company already in use.
    expect(testOrganization()->hasFinishedSetup())->toBeTrue();

    $this->get(route('dashboard'))->assertOk();
});

test('somebody who cannot configure the company is never trapped in the wizard', function () {
    actingAsUserWith(['employees.view']);
    unfinishedTenant();

    $this->get(route('employees.index'))->assertOk();
});

test('the wizard itself, the way out, and account settings stay reachable', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->get(route('setup.wizard.show'))->assertOk();
    $this->get(route('profile.edit'))->assertOk();
    $this->get(route('appearance.edit'))->assertOk();
    $this->post(route('logout'))->assertRedirect();
});

test('an outstanding setup does not interrupt anything but a page view', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    // A JSON endpoint answers rather than being bounced into an HTML redirect.
    $this->getJson(route('assistant.conversations.index'))->assertOk();

    // Neither does a mutation — RequireCompanySetup only ever acts on GET/HEAD.
    $this->post(route('setup.leave-types.store'), [
        'name' => 'Study Leave', 'code' => 'STL', 'color' => '#0ABFBF', 'default_days' => 5,
    ])->assertSessionHasNoErrors();
});

// ── The page ─────────────────────────────────────────────────────────────────

test('the wizard renders with its blueprints, progress and permissions', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->get(route('setup.wizard.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('setup/wizard')
            ->has('company')
            ->has('blueprints.departments', count(SetupBlueprints::departments()))
            ->has('blueprints.leaveTypes', count(SetupBlueprints::leaveTypes()))
            ->has('blueprints.pipelines', count(SetupBlueprints::pipelines()))
            ->has('blueprints.frameworks', count(SetupBlueprints::frameworks()))
            ->has('blueprints.holidays', count(SetupBlueprints::holidays(now())))
            ->has('blueprints.awardTypes', count(SetupBlueprints::awardTypes()))
            ->has('blueprints.onboardingPrograms', count(SetupBlueprints::onboardingPrograms()))
            ->has('blueprints.offboardingPrograms', count(SetupBlueprints::offboardingPrograms()))
            ->where('view', CompanySetup::INTRO)
            ->has('progress.steps', count(CompanySetup::STEPS))
            ->where('progress.resume', CompanySetup::COMPANY)
            ->where('progress.completed', false)
            ->where('progress.steps.company', CompanySetup::PENDING)
            ->where('can.company', true)
            ->has('existing.departments'));
});

test('a framework blueprint arrives with its criteria resolved, not just counted', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->get(route('setup.wizard.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blueprints.frameworks.0.items.0.name', 'Goal attainment')
            ->where('blueprints.frameworks.0.items.0.scale', 'Goal attainment (%)')
            ->etc());
});

test('the wizard is closed to a user who cannot manage the company profile', function () {
    actingAsUserWith(['setup.company.view']);

    $this->get(route('setup.wizard.show'))->assertForbidden();
});

// ── Step 1: company profile ──────────────────────────────────────────────────

test('the company step saves the profile and records the step', function () {
    actingAsSuperAdmin();
    $organization = unfinishedTenant();

    $this->post(route('setup.wizard.company'), [
        'name' => 'Acme Manufacturing',
        'legal_name' => 'Acme Manufacturing Corporation',
        'email' => 'hr@acme.test',
        'timezone' => 'Asia/Singapore',
        'tin' => '123-456-789-000',
    ])->assertSessionHasNoErrors();

    $organization->refresh();

    expect($organization->name)->toBe('Acme Manufacturing')
        ->and($organization->legal_name)->toBe('Acme Manufacturing Corporation')
        ->and($organization->timezone)->toBe('Asia/Singapore')
        ->and(CompanySetup::statuses($organization)['company'])->toBe(CompanySetup::DONE)
        // Saving a step is not finishing setup.
        ->and($organization->hasFinishedSetup())->toBeFalse();
});

// ── Step 2: departments ──────────────────────────────────────────────────────

test('the departments step creates the picked suggestions and the typed ones', function () {
    actingAsSuperAdmin();
    $organization = unfinishedTenant();

    $this->post(route('setup.wizard.departments'), [
        'codes' => ['HR', 'FIN'],
        'custom' => [['name' => 'Quality Assurance', 'code' => 'qa']],
    ])->assertSessionHasNoErrors();

    expect(Department::pluck('code')->sort()->values()->all())->toBe(['FIN', 'HR', 'QA'])
        ->and(Department::where('code', 'HR')->value('name'))->toBe('Human Resources')
        ->and(CompanySetup::statuses($organization->refresh())['departments'])->toBe(CompanySetup::DONE);
});

test('a typed department with no code gets one from its name', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.departments'), [
        'codes' => [],
        'custom' => [['name' => 'Research', 'code' => '']],
    ])->assertSessionHasNoErrors();

    expect(Department::where('name', 'Research')->value('code'))->toBe('RESEARCH');
});

test('the departments step refuses a step that would create nothing', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.departments'), ['codes' => [], 'custom' => []])
        ->assertSessionHasErrors('codes');

    expect(Department::count())->toBe(0);
});

test('the departments step refuses a code the company already has, and one repeated in the list', function () {
    actingAsSuperAdmin();
    unfinishedTenant();
    Department::create(['name' => 'People Ops', 'code' => 'HR']);

    $this->post(route('setup.wizard.departments'), [
        'codes' => [],
        'custom' => [['name' => 'Human Resources', 'code' => 'HR']],
    ])->assertSessionHasErrors('custom.0.code');

    $this->post(route('setup.wizard.departments'), [
        'codes' => ['FIN'],
        'custom' => [['name' => 'Finance Team', 'code' => 'FIN']],
    ])->assertSessionHasErrors('custom.0.code');
});

test('a suggested department the company already has is passed over rather than duplicated', function () {
    actingAsSuperAdmin();
    unfinishedTenant();
    Department::create(['name' => 'Human Resources', 'code' => 'HR']);

    $this->post(route('setup.wizard.departments'), ['codes' => ['HR', 'FIN'], 'custom' => []])
        ->assertSessionHasNoErrors();

    expect(Department::where('code', 'HR')->count())->toBe(1)
        ->and(Department::count())->toBe(2);
});

test('a customised suggestion is created in the company own words', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    // The wizard "customises" a suggestion by moving it out of the ticked list
    // and into the typed one, pre-filled — so this is what the server sees.
    $this->post(route('setup.wizard.departments'), [
        'codes' => [],
        'custom' => [[
            'name' => 'People & Culture',
            'code' => 'HR',
            'description' => 'Hiring, records and everything that keeps people here.',
        ]],
    ])->assertSessionHasNoErrors();

    $department = Department::firstOrFail();

    expect($department->name)->toBe('People & Culture')
        ->and($department->code)->toBe('HR')
        ->and($department->description)->toBe('Hiring, records and everything that keeps people here.');
});

// ── Step 3: leave types ──────────────────────────────────────────────────────

test('the leave step creates the ticked types at the days given', function () {
    actingAsSuperAdmin();
    $organization = unfinishedTenant();

    $this->post(route('setup.wizard.leave-types'), [
        'codes' => ['VL', 'SL'],
        'days' => ['VL' => 20, 'SL' => 15, 'ML' => 105],
    ])->assertSessionHasNoErrors();

    expect(LeaveType::pluck('code')->sort()->values()->all())->toBe(['SL', 'VL'])
        ->and((float) LeaveType::where('code', 'VL')->value('default_days'))->toBe(20.0)
        ->and(LeaveType::where('code', 'VL')->value('is_paid'))->toBeTrue()
        ->and(CompanySetup::statuses($organization->refresh())['leave-types'])->toBe(CompanySetup::DONE);
});

test('a ticked type with no days falls back to what the blueprint carries', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.leave-types'), ['codes' => ['ML'], 'days' => []])
        ->assertSessionHasNoErrors();

    expect((float) LeaveType::where('code', 'ML')->value('default_days'))->toBe(105.0);
});

test('the leave step refuses an empty selection and an unknown code', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.leave-types'), ['codes' => [], 'days' => []])
        ->assertSessionHasErrors('codes');

    $this->post(route('setup.wizard.leave-types'), ['codes' => ['NOPE'], 'days' => []])
        ->assertSessionHasErrors('codes.0');
});

test('the leave step does not duplicate a code the company already uses', function () {
    actingAsSuperAdmin();
    unfinishedTenant();
    LeaveType::factory()->create(['code' => 'VL', 'name' => 'Vacation Leave']);

    $this->post(route('setup.wizard.leave-types'), ['codes' => ['VL'], 'days' => []])
        ->assertSessionHasNoErrors();

    expect(LeaveType::where('code', 'VL')->count())->toBe(1);
});

test('the leave step creates a kind of leave the company wrote itself', function () {
    actingAsSuperAdmin();
    $organization = unfinishedTenant();

    $this->post(route('setup.wizard.leave-types'), [
        'codes' => [],
        'days' => [],
        'custom' => [[
            'name' => 'Typhoon Leave',
            'code' => 'tl',
            'description' => 'Days the office is closed by a storm signal.',
            'color' => '#0EA5E9',
            'default_days' => 4,
            'is_paid' => true,
            'allow_half_day' => false,
            'requires_approval' => false,
        ]],
    ])->assertSessionHasNoErrors();

    $type = LeaveType::firstOrFail();

    expect($type->name)->toBe('Typhoon Leave')
        ->and($type->code)->toBe('TL')
        ->and((float) $type->default_days)->toBe(4.0)
        ->and($type->allow_half_day)->toBeFalse()
        ->and($type->requires_approval)->toBeFalse()
        ->and($type->is_active)->toBeTrue()
        ->and(CompanySetup::statuses($organization->refresh())['leave-types'])->toBe(CompanySetup::DONE);
});

test('a customised statutory leave keeps the company own wording and days', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.leave-types'), [
        'codes' => ['SL'],
        'days' => ['SL' => 12],
        'custom' => [[
            'name' => 'Vacation Leave (probationary)',
            'code' => 'VLP',
            'color' => '#0ABFBF',
            'default_days' => 5,
            'is_paid' => true,
        ]],
    ])->assertSessionHasNoErrors();

    expect(LeaveType::pluck('code')->sort()->values()->all())->toBe(['SL', 'VLP'])
        ->and((float) LeaveType::where('code', 'VLP')->value('default_days'))->toBe(5.0)
        // A ticked suggestion still carries the policy the blueprint sets.
        ->and(LeaveType::where('code', 'SL')->value('name'))->toBe('Sick Leave');
});

test('a leave type left without a code gets one in the shape codes take', function (string $name, string $code) {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.leave-types'), [
        'codes' => [],
        'days' => [],
        'custom' => [['name' => $name, 'code' => '', 'color' => '#0ABFBF', 'default_days' => 3]],
    ])->assertSessionHasNoErrors();

    expect(LeaveType::where('name', $name)->value('code'))->toBe($code);
})->with([
    'initials, for a name with several words' => ['Typhoon Leave', 'TL'],
    'and three letters for one without' => ['Sabbatical', 'SAB'],
]);

test('the leave step refuses a code claimed twice in one submission', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.leave-types'), [
        'codes' => ['VL'],
        'days' => [],
        'custom' => [['name' => 'Vacation', 'code' => 'VL', 'color' => '#0ABFBF', 'default_days' => 10]],
    ])->assertSessionHasErrors('custom.0.code');

    expect(LeaveType::count())->toBe(0);
});

test('the leave step refuses a code the company already uses', function () {
    actingAsSuperAdmin();
    unfinishedTenant();
    LeaveType::factory()->create(['code' => 'TL', 'name' => 'Training Leave']);

    $this->post(route('setup.wizard.leave-types'), [
        'codes' => [],
        'days' => [],
        'custom' => [['name' => 'Typhoon Leave', 'code' => 'TL', 'color' => '#0ABFBF', 'default_days' => 4]],
    ])->assertSessionHasErrors('custom.0.code');
});

// ── Step 4: recruitment ──────────────────────────────────────────────────────

test('the hiring step creates the blueprint pipeline as the default', function () {
    actingAsSuperAdmin();
    $organization = unfinishedTenant();

    $this->post(route('setup.wizard.recruitment'), ['blueprint' => 'standard'])
        ->assertSessionHasNoErrors();

    $pipeline = RecruitmentPipeline::with('stages')->firstOrFail();

    expect($pipeline->name)->toBe('Standard Hiring')
        ->and($pipeline->is_default)->toBeTrue()
        ->and($pipeline->stages->pluck('name')->all())
        ->toBe(['Applied', 'Screening', 'Interview', 'Offer', 'Hired', 'Rejected'])
        ->and($pipeline->wonStage()->name)->toBe('Hired')
        ->and($pipeline->entryStage()->name)->toBe('Applied')
        ->and(CompanySetup::statuses($organization->refresh())['recruitment'])->toBe(CompanySetup::DONE);
});

test('the hiring step honours a name of the company own', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.recruitment'), [
        'blueprint' => 'fast-track',
        'name' => 'Store Crew Hiring',
    ])->assertSessionHasNoErrors();

    expect(RecruitmentPipeline::value('name'))->toBe('Store Crew Hiring')
        ->and(RecruitmentPipeline::first()->stages)->toHaveCount(4);
});

test('the hiring step refuses a blueprint that is not on offer', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.recruitment'), ['blueprint' => 'whatever-i-like'])
        ->assertSessionHasErrors('blueprint');

    expect(RecruitmentPipeline::count())->toBe(0);
});

test('a second pipeline does not steal the default from the first', function () {
    actingAsSuperAdmin();
    unfinishedTenant();
    seedDefaultPipeline();

    $this->post(route('setup.wizard.recruitment'), ['blueprint' => 'executive'])
        ->assertSessionHasNoErrors();

    expect(RecruitmentPipeline::where('is_default', true)->count())->toBe(1)
        ->and(RecruitmentPipeline::where('name', 'Executive Search')->value('is_default'))->toBeFalse();
});

test('the hiring step creates a process the company drew itself', function () {
    actingAsSuperAdmin();
    $organization = unfinishedTenant();

    $this->post(route('setup.wizard.recruitment'), [
        'source' => 'custom',
        'name' => 'Barista Hiring',
        'stages' => [
            ['name' => 'Walk-in', 'kind' => 'open'],
            ['name' => 'Trial shift', 'kind' => 'open'],
            ['name' => 'On the crew', 'kind' => 'won'],
            ['name' => 'Not this time', 'kind' => 'lost'],
        ],
    ])->assertSessionHasNoErrors();

    $pipeline = RecruitmentPipeline::with('stages')->firstOrFail();

    expect($pipeline->name)->toBe('Barista Hiring')
        ->and($pipeline->is_default)->toBeTrue()
        ->and($pipeline->stages->pluck('name')->all())
        ->toBe(['Walk-in', 'Trial shift', 'On the crew', 'Not this time'])
        ->and($pipeline->wonStage()->name)->toBe('On the crew')
        ->and($pipeline->entryStage()->name)->toBe('Walk-in')
        ->and(CompanySetup::statuses($organization->refresh())['recruitment'])->toBe(CompanySetup::DONE);
});

test('a hand-drawn process still needs somewhere to be hired and somewhere not to be', function (array $stages) {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.recruitment'), [
        'source' => 'custom',
        'name' => 'Half a process',
        'stages' => $stages,
    ])->assertSessionHasErrors('stages');

    expect(RecruitmentPipeline::count())->toBe(0);
})->with([
    'no hired stage' => [[['name' => 'Applied', 'kind' => 'open'], ['name' => 'Rejected', 'kind' => 'lost']]],
    'two hired stages' => [[['name' => 'Hired', 'kind' => 'won'], ['name' => 'Signed', 'kind' => 'won'], ['name' => 'No', 'kind' => 'lost']]],
    'nowhere to be rejected' => [[['name' => 'Applied', 'kind' => 'open'], ['name' => 'Hired', 'kind' => 'won']]],
]);

test('a hand-drawn process has to be called something, and its blank stages are dropped', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.recruitment'), [
        'source' => 'custom',
        'name' => '',
        'stages' => [['name' => 'Applied', 'kind' => 'open'], ['name' => 'Hired', 'kind' => 'won'], ['name' => 'No', 'kind' => 'lost']],
    ])->assertSessionHasErrors('name');

    $this->post(route('setup.wizard.recruitment'), [
        'source' => 'custom',
        'name' => 'Crew Hiring',
        'stages' => [
            ['name' => 'Applied', 'kind' => 'open'],
            ['name' => '   ', 'kind' => 'open'],
            ['name' => 'Hired', 'kind' => 'won'],
            ['name' => 'No', 'kind' => 'lost'],
        ],
    ])->assertSessionHasNoErrors();

    expect(RecruitmentPipeline::first()->stages)->toHaveCount(3);
});

test('a stage kind is resolved against what recruitment understands', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.recruitment'), [
        'source' => 'custom',
        'name' => 'Nonsense',
        'stages' => [['name' => 'Applied', 'kind' => 'maybe'], ['name' => 'Hired', 'kind' => 'won'], ['name' => 'No', 'kind' => 'lost']],
    ])->assertSessionHasErrors('stages.0.kind');
});

// ── Step 5: performance framework ────────────────────────────────────────────

test('the appraisal step builds the framework, its criteria and the scales behind it', function () {
    actingAsSuperAdmin();
    $organization = unfinishedTenant();

    $this->post(route('setup.wizard.performance'), ['blueprint' => 'balanced'])
        ->assertSessionHasNoErrors();

    $template = ReviewTemplate::with('items')->firstOrFail();

    expect($template->name)->toBe('Balanced Appraisal')
        ->and($template->is_default)->toBeTrue()
        ->and($template->applies_to)->toBe('all')
        ->and(collect($template->sections)->pluck('key')->all())->toBe(['goals', 'competencies', 'conduct'])
        ->and($template->items)->toHaveCount(7)
        // Every line names a catalogue criterion — none is written into the
        // framework alone — and follows that criterion's own scale.
        ->and($template->items->whereNull('kpi_criterion_id'))->toHaveCount(0)
        ->and($template->items->whereNotNull('rating_scale_id'))->toHaveCount(0)
        ->and(KpiCriterion::count())->toBe(7)
        ->and(RatingScale::where('is_default', true)->value('name'))->toBe('5-point rating')
        ->and(CompanySetup::statuses($organization->refresh())['performance'])->toBe(CompanySetup::DONE);
});

test('a framework only creates the instruments it actually measures on', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.performance'), ['blueprint' => 'results'])
        ->assertSessionHasNoErrors();

    expect(RatingScale::pluck('name')->sort()->values()->all())
        ->toBe(['Expectation rating', 'Goal attainment (%)'])
        ->and(KpiCriterion::count())->toBe(3);
});

test('adopting a framework reuses a scale and a criterion the company already has', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $scale = RatingScale::create([
        'name' => '5-point rating', 'description' => 'Ours', 'type' => 'numeric',
        'min' => 1, 'max' => 5, 'step' => 1, 'levels' => null, 'is_default' => true,
    ]);
    KpiCriterion::create([
        'name' => 'Quality of work', 'description' => 'Our own wording.',
        'weight' => 40, 'rating_scale_id' => $scale->id, 'is_active' => true, 'sort_order' => 1,
    ]);

    $this->post(route('setup.wizard.performance'), ['blueprint' => 'balanced'])
        ->assertSessionHasNoErrors();

    expect(RatingScale::where('name', '5-point rating')->count())->toBe(1)
        ->and(KpiCriterion::where('name', 'Quality of work')->count())->toBe(1)
        // The catalogue's wording wins over the blueprint's, as everywhere else.
        ->and(KpiCriterion::where('name', 'Quality of work')->value('description'))->toBe('Our own wording.');
});

test('the appraisal step refuses a blueprint that is not on offer', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.performance'), ['blueprint' => 'my-own'])
        ->assertSessionHasErrors('blueprint');

    expect(ReviewTemplate::count())->toBe(0);
});

test('the appraisal step builds a framework the company designed itself', function () {
    actingAsSuperAdmin();
    $organization = unfinishedTenant();

    $this->post(route('setup.wizard.performance'), [
        'source' => 'custom',
        'name' => 'Crew Review',
        'description' => 'What we ask of everyone on shift.',
        'scale' => 'Expectation rating',
        'result_display' => 'percent',
        'sections' => [
            ['key' => 'floor', 'name' => 'On the floor', 'description' => 'The shift itself.', 'weight' => 70],
            ['key' => 'team', 'name' => 'With the team', 'description' => null, 'weight' => 30],
        ],
        'items' => [
            // One drawn from the catalogue, one the company wrote.
            ['section' => 'floor', 'weight' => 60, 'criterion' => 'quality_of_work'],
            ['section' => 'floor', 'weight' => 40, 'name' => 'Shift handover', 'description' => 'The next shift starts where this one left off.', 'scale' => 'Met / not met'],
            ['section' => 'team', 'weight' => 100, 'criterion' => 'teamwork'],
        ],
    ])->assertSessionHasNoErrors();

    $template = ReviewTemplate::with('items')->firstOrFail();

    expect($template->name)->toBe('Crew Review')
        ->and($template->description)->toBe('What we ask of everyone on shift.')
        ->and($template->result_display)->toBe('percent')
        ->and($template->is_default)->toBeTrue()
        ->and(collect($template->sections)->pluck('key')->all())->toBe(['floor', 'team'])
        ->and(collect($template->sections)->pluck('weight')->map(floatval(...))->all())->toBe([70.0, 30.0])
        ->and($template->items)->toHaveCount(3)
        // Every line is catalogue-backed, the company's own included — writing a
        // criterion in the wizard is how the catalogue gets built.
        ->and($template->items->whereNull('kpi_criterion_id'))->toHaveCount(0)
        ->and($template->items->pluck('name')->all())
        ->toBe(['Quality of work', 'Shift handover', 'Teamwork & collaboration'])
        // A catalogue line takes the catalogue's wording, not the client's.
        ->and($template->items->firstWhere('name', 'Quality of work')->description)
        ->toBe('Accuracy, thoroughness and how much rework the output needs.')
        ->and(KpiCriterion::where('name', 'Shift handover')->value('description'))
        ->toBe('The next shift starts where this one left off.')
        // Only the instruments this framework measures on.
        ->and(RatingScale::pluck('name')->sort()->values()->all())
        ->toBe(['5-point rating', 'Expectation rating', 'Met / not met'])
        ->and(RatingScale::where('is_default', true)->value('name'))->toBe('Expectation rating')
        ->and(CompanySetup::statuses($organization->refresh())['performance'])->toBe(CompanySetup::DONE);
});

test('a framework left alone about its rating model gets the standard ladder', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.performance'), [
        'source' => 'custom',
        'name' => 'Two-liner',
        'scale' => '5-point rating',
        'result_display' => 'band',
        'sections' => [['key' => 's1', 'name' => 'Everything', 'weight' => 100]],
        'items' => [['section' => 's1', 'weight' => 100, 'criterion' => 'goal_attainment']],
    ])->assertSessionHasNoErrors();

    expect(collect(ReviewTemplate::firstOrFail()->bands)->pluck('label')->all())
        ->toBe(collect(RatingModel::defaultBands())->pluck('label')->all());
});

test('a framework can report in the company own words, highest band first', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.performance'), [
        'source' => 'custom',
        'name' => 'Ours',
        'scale' => '5-point rating',
        'result_display' => 'band',
        'sections' => [['key' => 's1', 'name' => 'Everything', 'weight' => 100]],
        'items' => [['section' => 's1', 'weight' => 100, 'criterion' => 'goal_attainment']],
        'bands' => [
            ['label' => 'Getting there', 'min_percent' => 0, 'tone' => 'caution', 'description' => null],
            ['label' => 'One of the best', 'min_percent' => 85, 'tone' => 'positive', 'description' => 'The reference point.'],
            ['label' => 'Solid', 'min_percent' => 50, 'tone' => 'neutral', 'description' => null],
        ],
    ])->assertSessionHasNoErrors();

    $bands = collect(ReviewTemplate::firstOrFail()->bands);

    expect($bands->pluck('label')->all())->toBe(['One of the best', 'Solid', 'Getting there'])
        ->and($bands->pluck('key')->all())->toBe(['one_of_the_best', 'solid', 'getting_there']);
});

test('a designed framework is held to the shape the framework editor enforces', function (array $payload, string $field) {
    actingAsSuperAdmin();
    unfinishedTenant();

    $base = [
        'source' => 'custom',
        'name' => 'Ours',
        'scale' => '5-point rating',
        'result_display' => 'band',
        'sections' => [['key' => 's1', 'name' => 'Everything', 'weight' => 100]],
        'items' => [['section' => 's1', 'weight' => 100, 'criterion' => 'goal_attainment']],
    ];

    $this->post(route('setup.wizard.performance'), array_replace($base, $payload))
        ->assertSessionHasErrors($field);

    expect(ReviewTemplate::count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'an instrument that is not on offer' => [['scale' => 'Vibes (1-10)'], 'scale'],
    'a result display that means nothing' => [['result_display' => 'stars'], 'result_display'],
    'nothing to measure' => [['items' => []], 'items'],
    'no sections' => [['sections' => []], 'sections'],
    'a line in a section that does not exist' => [
        ['items' => [['section' => 'nowhere', 'weight' => 100, 'criterion' => 'goal_attainment']]],
        'items',
    ],
    'the same criterion twice' => [
        ['items' => [
            ['section' => 's1', 'weight' => 50, 'criterion' => 'goal_attainment'],
            ['section' => 's1', 'weight' => 50, 'criterion' => 'goal_attainment'],
        ]],
        'items.1.criterion',
    ],
    'a criterion that is not in the catalogue' => [
        ['items' => [['section' => 's1', 'weight' => 100, 'criterion' => 'vibes']]],
        'items.0.criterion',
    ],
    'a rating model nothing can fall into' => [
        ['bands' => [
            ['label' => 'Good', 'min_percent' => 60, 'tone' => 'good'],
            ['label' => 'Great', 'min_percent' => 90, 'tone' => 'positive'],
        ]],
        'bands',
    ],
]);

test('a designed framework reuses a criterion the company already has', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $scale = RatingScale::create([
        'name' => '5-point rating', 'description' => 'Ours', 'type' => 'numeric',
        'min' => 1, 'max' => 5, 'step' => 1, 'levels' => null, 'is_default' => true,
    ]);
    KpiCriterion::create([
        'name' => 'Shift handover', 'description' => 'Our own wording.',
        'weight' => 40, 'rating_scale_id' => $scale->id, 'is_active' => true, 'sort_order' => 1,
    ]);

    $this->post(route('setup.wizard.performance'), [
        'source' => 'custom',
        'name' => 'Crew Review',
        'scale' => '5-point rating',
        'result_display' => 'band',
        'sections' => [['key' => 's1', 'name' => 'Everything', 'weight' => 100]],
        'items' => [['section' => 's1', 'weight' => 100, 'name' => 'Shift handover', 'description' => 'Different words.']],
    ])->assertSessionHasNoErrors();

    expect(KpiCriterion::where('name', 'Shift handover')->count())->toBe(1)
        ->and(KpiCriterion::where('name', 'Shift handover')->value('description'))->toBe('Our own wording.');
});

// ── Skipping and finishing ───────────────────────────────────────────────────

test('a skipped step is remembered, and the wizard resumes past it', function () {
    actingAsSuperAdmin();
    $organization = unfinishedTenant();

    $this->post(route('setup.wizard.skip'), ['step' => CompanySetup::COMPANY])
        ->assertSessionHasNoErrors();

    $organization->refresh();

    expect(CompanySetup::statuses($organization)['company'])->toBe(CompanySetup::SKIPPED)
        ->and(CompanySetup::resumeStep($organization))->toBe(CompanySetup::DEPARTMENTS);

    $this->get(route('setup.wizard.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('progress.steps.company', CompanySetup::SKIPPED)
            ->where('progress.resume', CompanySetup::DEPARTMENTS)
            ->etc());
});

test('a step that is not one of the wizard’s is refused', function () {
    actingAsSuperAdmin();
    unfinishedTenant();

    $this->post(route('setup.wizard.skip'), ['step' => 'payroll'])
        ->assertSessionHasErrors('step');
});

test('finishing closes setup and stops the redirect', function () {
    actingAsSuperAdmin();
    $organization = unfinishedTenant();

    $this->post(route('setup.wizard.finish'))->assertRedirect(route('dashboard'));

    expect($organization->refresh()->hasFinishedSetup())->toBeTrue();

    $this->get(route('dashboard'))->assertOk();
});

test('finishing twice keeps the first completion date', function () {
    actingAsSuperAdmin();
    $organization = unfinishedTenant();

    $this->post(route('setup.wizard.finish'));
    $first = $organization->refresh()->setup_completed_at;

    $this->post(route('setup.wizard.finish'))->assertRedirect(route('dashboard'));

    expect($organization->refresh()->setup_completed_at->equalTo($first))->toBeTrue();
});

test('the wizard stays reachable once setup is done, so a skipped step can be picked up', function () {
    actingAsSuperAdmin();

    $this->get(route('setup.wizard.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('progress.completed', true)->etc());
});

// ── Authorization & isolation ────────────────────────────────────────────────

test('each step is gated by the permission of the module it configures', function (string $route, array $payload, string $ability) {
    actingAsUserWith(['setup.company.manage']);
    unfinishedTenant();

    // Holding only the company-profile ability opens the wizard but not the step.
    $this->get(route('setup.wizard.show'))->assertOk();
    $this->post(route($route), $payload)->assertForbidden();

    actingAsUserWith(['setup.company.manage', $ability]);
    unfinishedTenant();

    $this->post(route($route), $payload)->assertSessionHasNoErrors();
})->with([
    ['setup.wizard.departments', ['codes' => ['HR'], 'custom' => []], 'setup.departments.manage'],
    ['setup.wizard.leave-types', ['codes' => ['VL'], 'days' => []], 'setup.leave-types.manage'],
    ['setup.wizard.recruitment', ['blueprint' => 'standard'], 'recruitment.configure-pipelines'],
    ['setup.wizard.performance', ['blueprint' => 'balanced'], 'setup.kpi.manage'],
]);

test('what a step creates lands in the acting tenant and nowhere else', function () {
    actingAsSuperAdmin();
    $mine = unfinishedTenant();
    $other = Organization::factory()->create();

    $this->post(route('setup.wizard.leave-types'), ['codes' => ['VL'], 'days' => []])
        ->assertSessionHasNoErrors();

    expect(LeaveType::where('organization_id', $mine->id)->count())->toBe(1);

    app(Tenancy::class)->runFor($other, function (): void {
        expect(LeaveType::count())->toBe(0);
    });
});

test('setup progress is the company own, not the session or the user', function () {
    seedPermissions();

    [$organization, $ownerRole] = OrganizationProvisioner::create('Two Owners Ltd');

    app(Tenancy::class)->set($organization);

    $first = User::factory()->create();
    $second = User::factory()->create();

    foreach ([$first, $second] as $user) {
        OrganizationProvisioner::addMember($organization, $user, default: true);
        $user->roles()->attach($ownerRole->id);
    }

    $this->actingAs($first)->post(route('setup.wizard.skip'), ['step' => CompanySetup::RECRUITMENT]);

    // The second owner picks up exactly where the first left off.
    $this->actingAs($second)->get(route('setup.wizard.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('progress.steps.recruitment', CompanySetup::SKIPPED)
            ->etc());

    expect(CompanySetup::statuses($organization->refresh())['recruitment'])->toBe(CompanySetup::SKIPPED);
});
