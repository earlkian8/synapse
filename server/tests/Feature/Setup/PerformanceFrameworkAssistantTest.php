<?php

use App\Http\Requests\Setup\ReviewTemplateRequest;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\KpiCriterion;
use App\Models\Organization;
use App\Models\Position;
use App\Models\RatingScale;
use App\Models\ReviewTemplate;
use App\Models\User;
use App\Services\Assistant\Modules\PerformanceFrameworkModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Performance\RatingModel;
use App\Support\Setup\PerformanceFrameworkWorkflow;
use App\Support\Tenancy;

/*
| The performance-framework capability of the assistant: frameworks edited a
| line at a time but rebuilt and validated whole, by the editor's own request;
| the catalogue, scales and cycles beside them. Gemini is never called.
*/

function frameworkAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(PerformanceFrameworkModule::class)->run($user, $tool, $args);
}

/**
 * "Staff": Core (60%) — Teamwork 20, Quality 30; Goals (40%) — a one-off
 * target. Everyone, the default, on a preferred 1–5 scale. Customer focus waits
 * in the catalogue.
 */
function staffFramework(): ReviewTemplate
{
    $scale = RatingScale::create(['name' => 'Five point', 'type' => 'numeric', 'min' => 1, 'max' => 5, 'step' => 1, 'is_default' => true]);
    $criterion = fn (string $name, int $weight): KpiCriterion => KpiCriterion::create(['name' => $name, 'weight' => $weight, 'rating_scale_id' => $scale->id, 'is_active' => true]);
    $teamwork = $criterion('Teamwork', 20);
    $quality = $criterion('Quality', 30);
    $criterion('Customer focus', 10);

    return app(PerformanceFrameworkWorkflow::class)->saveFramework(null, ReviewTemplateRequest::normalise([
        'name' => 'Staff',
        'rating_scale_id' => $scale->id,
        'result_display' => 'band',
        'applies_to' => 'all',
        'is_default' => true,
        'sections' => [['name' => 'Core', 'weight' => 60], ['name' => 'Goals', 'weight' => 40]],
        'bands' => RatingModel::defaultBands(),
        'items' => [
            ['kpi_criterion_id' => $teamwork->id, 'section_key' => 'core', 'name' => '', 'weight' => 20],
            ['kpi_criterion_id' => $quality->id, 'section_key' => 'core', 'name' => '', 'weight' => 30],
            ['section_key' => 'goals', 'name' => 'Hit the quarterly target', 'weight' => 40],
        ],
    ]));
}

// ── Permissions ──────────────────────────────────────────────────────────────

test('a viewer reads; cycles are listed here only for those without the performance module', function () {
    $tools = fn (User $user): array => array_column(app(PerformanceFrameworkModule::class)->tools($user), 'name');

    expect($tools(actingAsUserWith(['setup.kpi.view'])))->toEqualCanonicalizing(['find_frameworks', 'get_framework', 'find_kpi_criteria', 'find_rating_scales', 'find_review_cycles'])
        ->and($tools(actingAsUserWith(['setup.kpi.view', 'performance.view'])))->not->toContain('find_review_cycles');

    $manager = $tools(actingAsUserWith(['setup.kpi.view', 'setup.kpi.manage']));

    expect($manager)->toContain('set_framework_item', 'create_rating_scale', 'update_review_cycle')
        ->not->toContain('delete_framework', 'update_rating_scale', 'archive_rating_scale');

    $module = app(PerformanceFrameworkModule::class);

    foreach (['update_framework', 'set_framework_item', 'remove_framework_item', 'set_framework_section', 'set_default_framework', 'archive_framework', 'update_kpi_criterion', 'archive_kpi_criterion', 'update_review_cycle'] as $tool) {
        expect($module->requiresConfirmation($tool))->toBeTrue();
    }

    expect($module->requiresConfirmation('create_framework'))->toBeFalse()
        ->and($module->requiresConfirmation('add_kpi_criterion'))->toBeFalse();
});

// ── Reading ──────────────────────────────────────────────────────────────────

test('a framework reads out section by section, with its scale and who it covers today', function () {
    $user = actingAsSuperAdmin();
    staffFramework();
    Employee::factory()->count(2)->create();

    $meta = implode(' | ', frameworkAgent($user, 'get_framework', ['framework' => 'staff'])->cards[0]['meta']);

    expect($meta)->toContain('Core (60%): Teamwork (20, 1–5), Quality (30, 1–5)')
        ->toContain('Goals (40%): Hit the quarterly target (40, 1–5)')
        ->toContain('Bands: Outstanding from 90%')
        ->toContain('Covers 2 people today');

    $prompt = app(Retriever::class)->retrieve($user, 'what does our appraisal framework measure?')?->toPrompt();

    expect($prompt)->toContain('Staff (everyone; 3 items; default)');
});

// ── Framework lines ──────────────────────────────────────────────────────────

test('an item is added or re-weighted, and the whole framework is saved by the editor’s rules', function () {
    $user = actingAsSuperAdmin();
    $staff = staffFramework();

    $added = frameworkAgent($user, 'set_framework_item', ['framework' => 'Staff', 'section' => 'core', 'criterion' => 'customer focus', 'weight' => 15]);
    $reweighted = frameworkAgent($user, 'set_framework_item', ['framework' => 'Staff', 'criterion' => 'Teamwork', 'weight' => 25]);
    $unplaced = frameworkAgent($user, 'set_framework_item', ['framework' => 'Staff', 'item' => 'Mentoring', 'weight' => 5]);
    $nowhere = frameworkAgent($user, 'set_framework_item', ['framework' => 'Staff', 'section' => 'Values', 'item' => 'Mentoring', 'weight' => 5]);
    $twice = frameworkAgent($user, 'set_framework_item', ['framework' => 'Staff', 'section' => 'Goals', 'item' => 'Customer focus', 'weight' => 5]);

    $items = $staff->fresh()->items->keyBy('name');

    expect($added->failed())->toBeFalse()
        ->and($added->detail)->toBe('Core, weight 15.')
        ->and((float) $items['Customer focus']->weight)->toBe(5.0)
        ->and($items['Customer focus']->section_key)->toBe('goals')
        ->and((float) $items['Teamwork']->weight)->toBe(25.0)
        ->and($items['Teamwork']->section_key)->toBe('core')
        ->and($unplaced->detail)->toBe('Say which section: Core, Goals.')
        ->and($nowhere->detail)->toContain('No section called “Values”')
        // A line is named once: an item named like one moves that line.
        ->and($twice->failed())->toBeFalse()
        ->and($staff->fresh()->items()->count())->toBe(4)
        ->and($staff->fresh()->is_default)->toBeTrue()
        ->and(ActivityLog::query()->where('description', 'Updated appraisal framework "Staff" via assistant')->count())->toBe(3);
});

test('an item is removed, but never the last one', function () {
    $user = actingAsSuperAdmin();
    staffFramework();

    $removed = frameworkAgent($user, 'remove_framework_item', ['framework' => 'Staff', 'item' => 'quarterly']);

    frameworkAgent($user, 'create_framework', ['name' => 'Interns', 'criteria' => ['Teamwork']]);
    $last = frameworkAgent($user, 'remove_framework_item', ['framework' => 'Interns', 'item' => 'Teamwork']);

    expect($removed->failed())->toBeFalse()
        ->and(ReviewTemplate::query()->where('name', 'Staff')->first()->items()->pluck('name')->all())->toBe(['Teamwork', 'Quality'])
        ->and($last->detail)->toBe('A framework needs at least one thing to measure.');
});

test('a section is re-weighted, or added with a weight', function () {
    $user = actingAsSuperAdmin();
    $staff = staffFramework();

    $weighted = frameworkAgent($user, 'set_framework_section', ['framework' => 'Staff', 'section' => 'goals', 'weight' => 50]);
    $noWeight = frameworkAgent($user, 'set_framework_section', ['framework' => 'Staff', 'section' => 'Values']);
    $added = frameworkAgent($user, 'set_framework_section', ['framework' => 'Staff', 'section' => 'Values', 'weight' => 10]);

    expect($weighted->detail)->toBe('Sections now weigh 110% in all.')
        ->and($noWeight->detail)->toContain('To add one, give it a weight')
        ->and($added->failed())->toBeFalse()
        ->and(array_column($staff->fresh()->sectionList(), 'name'))->toBe(['Core', 'Goals', 'Values'])
        ->and($staff->fresh()->items()->count())->toBe(3);
});

// ── Frameworks ───────────────────────────────────────────────────────────────

test('a framework is made from a copy or from criteria, for everyone and not the default', function () {
    $user = actingAsSuperAdmin();
    staffFramework();
    Employee::factory()->create();

    $neither = frameworkAgent($user, 'create_framework', ['name' => 'Empty']);
    $both = frameworkAgent($user, 'create_framework', ['name' => 'Both', 'copy_from' => 'Staff', 'criteria' => ['Quality']]);
    $copy = frameworkAgent($user, 'create_framework', ['name' => 'Sales', 'copy_from' => 'staff']);
    $fresh = frameworkAgent($user, 'create_framework', ['name' => 'Interns', 'criteria' => ['Teamwork', 'Customer focus']]);
    $duplicate = frameworkAgent($user, 'create_framework', ['name' => 'sales', 'criteria' => ['Quality']]);

    $sales = ReviewTemplate::query()->where('name', 'Sales')->firstOrFail();
    $interns = ReviewTemplate::query()->where('name', 'Interns')->firstOrFail();

    expect($neither->failed())->toBeTrue()
        ->and($both->failed())->toBeTrue()
        ->and($copy->detail)->toContain('covers 0 people no other framework does')
        ->and($sales->items()->count())->toBe(3)
        ->and($sales->applies_to)->toBe('all')
        ->and($sales->is_default)->toBeFalse()
        ->and(array_column($interns->sectionList(), 'name'))->toBe(['Performance criteria'])
        ->and($interns->items()->pluck('weight')->map(fn ($w): float => (float) $w)->all())->toBe([20.0, 10.0])
        ->and($duplicate->detail)->toContain('already a framework called');
});

test('who a framework applies to is set by name, and the card says whom it covers', function () {
    $user = actingAsSuperAdmin();
    staffFramework();
    frameworkAgent($user, 'create_framework', ['name' => 'Sales', 'copy_from' => 'Staff']);
    $sales = Department::factory()->create(['name' => 'Sales', 'code' => 'SAL']);
    $retail = Department::factory()->create(['name' => 'Retail', 'code' => 'RET']);
    Position::factory()->create(['title' => 'Sales Rep', 'department_id' => $sales->id]);
    $retailRep = Position::factory()->create(['title' => 'Sales Rep', 'department_id' => $retail->id]);
    Employee::factory()->create(['department_id' => $sales->id]);

    $unknown = frameworkAgent($user, 'update_framework', ['framework' => 'Sales', 'applies_to' => 'department', 'applies_to_values' => ['Marketing']]);
    $shared = frameworkAgent($user, 'update_framework', ['framework' => 'Sales', 'applies_to' => 'position', 'applies_to_values' => ['Sales Rep']]);
    $picked = frameworkAgent($user, 'update_framework', ['framework' => 'Sales', 'applies_to' => 'position', 'applies_to_values' => ['Sales Rep (Retail)']]);
    $types = frameworkAgent($user, 'update_framework', ['framework' => 'Sales', 'applies_to' => 'employment_type', 'applies_to_values' => ['Part-time']]);
    $department = frameworkAgent($user, 'update_framework', ['framework' => 'Sales', 'applies_to' => 'department', 'applies_to_values' => ['sal']]);

    expect($unknown->detail)->toBe('No department is called “Marketing”.')
        ->and($shared->detail)->toContain('More than one position is called “Sales Rep”')
        ->and($picked->failed())->toBeFalse()
        ->and($types->failed())->toBeFalse()
        ->and($department->failed())->toBeFalse()
        ->and(ReviewTemplate::query()->where('name', 'Sales')->first()->applies_to_values)->toBe([(string) $sales->id])
        ->and(app(PerformanceFrameworkModule::class)->consequence($user, 'update_framework', ['framework' => 'Sales']))->toContain('It covers 1 person today');

    expect($retailRep->exists)->toBeTrue();
});

// ── Catalogue, scales, cycles ────────────────────────────────────────────────

test('the criteria catalogue, the scales and the cycles are kept by the screen’s rules', function () {
    $user = actingAsSuperAdmin();
    staffFramework();

    $criterion = frameworkAgent($user, 'add_kpi_criterion', ['name' => 'Initiative', 'weight' => 10, 'rating_scale' => 'five']);
    $duplicate = frameworkAgent($user, 'add_kpi_criterion', ['name' => 'teamwork', 'weight' => 10]);
    $levels = frameworkAgent($user, 'create_rating_scale', ['name' => 'Behaviours', 'type' => 'levels', 'levels' => ['Rarely', 'Sometimes', 'Consistently']]);
    $backwards = frameworkAgent($user, 'create_rating_scale', ['name' => 'Broken', 'type' => 'numeric', 'min' => 5, 'max' => 1]);
    $cycle = frameworkAgent($user, 'create_review_cycle', ['name' => 'H2 2026', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31']);
    $inverted = frameworkAgent($user, 'create_review_cycle', ['name' => 'Oops', 'start_date' => '2026-12-31', 'end_date' => '2026-07-01']);
    $opened = frameworkAgent($user, 'update_review_cycle', ['cycle' => 'H2', 'status' => 'open']);

    $behaviours = RatingScale::query()->where('name', 'Behaviours')->firstOrFail();

    expect($criterion->failed())->toBeFalse()
        ->and(KpiCriterion::query()->where('name', 'Initiative')->first()->ratingScale?->name)->toBe('Five point')
        ->and($duplicate->detail)->toContain('already a criterion called')
        ->and($levels->detail)->toBe('3 levels.')
        ->and(array_column($behaviours->levels, 'value'))->toBe([1, 2, 3])
        ->and($backwards->detail)->toContain('its top value must be above its bottom value')
        ->and($cycle->detail)->toContain('It is a draft')
        ->and($inverted->failed())->toBeTrue()
        ->and($opened->failed())->toBeFalse()
        ->and(EvaluationPeriod::query()->where('name', 'H2 2026')->value('status'))->toBe('open')
        ->and(app(PerformanceFrameworkModule::class)->consequence($user, 'archive_kpi_criterion', ['criterion' => 'Teamwork']))->toContain('measured on 1 framework line')
        ->and(app(PerformanceFrameworkModule::class)->consequence($user, 'update_kpi_criterion', ['criterion' => 'Teamwork']))->toContain('take its new wording and scale');
});

test('another workspace’s framework is never found or edited', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => staffFramework());
    app(Tenancy::class)->set($mine);

    expect(frameworkAgent($user, 'get_framework', ['framework' => 'Staff'])->failed())->toBeTrue()
        ->and(frameworkAgent($user, 'set_framework_item', ['framework' => 'Staff', 'criterion' => 'Teamwork', 'weight' => 5])->failed())->toBeTrue()
        ->and(frameworkAgent($user, 'find_frameworks')->cards)->toBe([]);
});
