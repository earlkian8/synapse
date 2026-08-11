<?php

use App\Models\Department;
use App\Models\KpiCriterion;
use App\Models\Organization;
use App\Models\RatingScale;
use App\Models\ReviewTemplate;
use App\Models\ReviewTemplateItem;
use App\Support\Performance\RatingModel;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Company Setup → Performance framework: the rating scales, criteria catalogue
 * and appraisal frameworks the Performance module conducts reviews against.
 */

/**
 * A well-formed framework payload — one section, one item, the standard bands.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function frameworkPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Individual Contributor Review',
        'description' => 'The review most of the company runs on.',
        'result_display' => 'band',
        'applies_to' => 'all',
        'is_default' => true,
        'is_active' => true,
        'sections' => [
            ['key' => 'goals', 'name' => 'Goals', 'description' => null, 'weight' => 60],
            ['key' => 'values', 'name' => 'How we work', 'description' => null, 'weight' => 40],
        ],
        'bands' => RatingModel::defaultBands(),
        'items' => [
            ['section_key' => 'goals', 'name' => 'Goal attainment', 'weight' => 100],
            ['section_key' => 'values', 'name' => 'Teamwork', 'weight' => 100],
        ],
    ], $overrides);
}

// ── The surface ──────────────────────────────────────────────────────────────

test('the performance framework page renders every list it configures', function () {
    actingAsSuperAdmin();
    RatingScale::factory()->count(2)->create();
    KpiCriterion::factory()->count(3)->create();
    ReviewTemplate::factory()->create();

    $this->get(route('setup.kpi.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('setup/kpi')
            ->has('templates', 1)
            ->has('scales', 2)
            ->has('criteria', 3)
            ->has('periods')
            ->has('audiences.department')
            ->has('tones')
            ->has('defaultBands', 5)
            ->has('can'));
});

// ── Rating scales ────────────────────────────────────────────────────────────

test('it creates a numeric rating scale', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.kpi.scales.store'), [
        'name' => '4-point rating',
        'type' => 'numeric',
        'min' => 1,
        'max' => 4,
        'step' => 1,
    ])->assertSessionHasNoErrors();

    $scale = RatingScale::firstOrFail();

    expect($scale->name)->toBe('4-point rating')
        ->and((float) $scale->max)->toBe(4.0);
});

test('a level scale derives its bounds from its own levels', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.kpi.scales.store'), [
        'name' => 'Competency level',
        'type' => 'levels',
        'min' => 99,
        'max' => 999,
        'step' => 7,
        'levels' => [
            ['value' => 1, 'label' => 'Learning', 'description' => 'Needs direction.'],
            ['value' => 2, 'label' => 'Proficient', 'description' => null],
            ['value' => 3, 'label' => 'Expert', 'description' => null],
        ],
    ])->assertSessionHasNoErrors();

    $scale = RatingScale::firstOrFail();

    expect((float) $scale->min)->toBe(1.0)
        ->and((float) $scale->max)->toBe(3.0)
        ->and($scale->levels)->toHaveCount(3);
});

test('a level scale needs at least two levels', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.kpi.scales.store'), [
        'name' => 'Half a scale',
        'type' => 'levels',
        'levels' => [['value' => 1, 'label' => 'Only one']],
    ])->assertSessionHasErrors('levels');
});

test('promoting a scale to the default demotes the previous one', function () {
    actingAsSuperAdmin();
    $first = RatingScale::factory()->create(['is_default' => true]);

    $this->post(route('setup.kpi.scales.store'), [
        'name' => 'New default', 'type' => 'numeric', 'min' => 1, 'max' => 5, 'step' => 1, 'is_default' => true,
    ]);

    expect($first->refresh()->is_default)->toBeFalse()
        ->and(RatingScale::where('is_default', true)->count())->toBe(1);
});

test('a scale still in use cannot be permanently deleted', function () {
    actingAsSuperAdmin();
    $scale = RatingScale::factory()->create();
    KpiCriterion::factory()->create(['rating_scale_id' => $scale->id]);
    $scale->delete();

    $this->delete(route('setup.kpi.scales.force-delete', $scale->hashid));

    assertToast('warning', 'still in use');
    expect(RatingScale::withTrashed()->count())->toBe(1);
});

// ── Frameworks ───────────────────────────────────────────────────────────────

test('it creates a framework with its sections, items and rating model', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.kpi.frameworks.store'), frameworkPayload())
        ->assertSessionHasNoErrors();

    $template = ReviewTemplate::with('items')->firstOrFail();

    expect($template->sections)->toHaveCount(2)
        ->and($template->bands)->toHaveCount(5)
        ->and($template->items)->toHaveCount(2)
        ->and($template->items->pluck('section_key')->all())->toBe(['goals', 'values'])
        ->and($template->items->pluck('sort_order')->all())->toBe([0, 1]);
});

test('an item has to sit in a section the framework declares', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.kpi.frameworks.store'), frameworkPayload([
        'items' => [['section_key' => 'nowhere', 'name' => 'Orphan', 'weight' => 100]],
    ]))->assertSessionHasErrors('items.0.section_key');
});

test('the lowest band has to start at zero so nothing comes back unrated', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.kpi.frameworks.store'), frameworkPayload([
        'bands' => [
            ['label' => 'Good', 'min_percent' => 70, 'tone' => 'good'],
            ['label' => 'Poor', 'min_percent' => 30, 'tone' => 'critical'],
        ],
    ]))->assertSessionHasErrors('bands');
});

test('a rating model needs at least two bands', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.kpi.frameworks.store'), frameworkPayload([
        'bands' => [['label' => 'Fine', 'min_percent' => 0, 'tone' => 'neutral']],
    ]))->assertSessionHasErrors('bands');
});

test('a framework scoped to a population needs its values', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.kpi.frameworks.store'), frameworkPayload([
        'applies_to' => 'department',
    ]))->assertSessionHasErrors('applies_to_values');
});

test('a framework can be scoped to departments', function () {
    actingAsSuperAdmin();
    $department = Department::factory()->create();

    $this->post(route('setup.kpi.frameworks.store'), frameworkPayload([
        'applies_to' => 'department',
        'applies_to_values' => [(string) $department->id],
    ]))->assertSessionHasNoErrors();

    expect(ReviewTemplate::firstOrFail()->applies_to_values)->toBe([(string) $department->id]);
});

test('saving a framework replaces its items rather than duplicating them', function () {
    actingAsSuperAdmin();
    $this->post(route('setup.kpi.frameworks.store'), frameworkPayload());
    $template = ReviewTemplate::firstOrFail();

    $this->post(route('setup.kpi.frameworks.update', $template->hashid), frameworkPayload([
        'items' => [['section_key' => 'goals', 'name' => 'Only this now', 'weight' => 100]],
    ]))->assertSessionHasNoErrors();

    expect(ReviewTemplateItem::count())->toBe(1)
        ->and(ReviewTemplateItem::firstOrFail()->name)->toBe('Only this now');
});

test('promoting a framework to the default demotes the previous one', function () {
    actingAsSuperAdmin();
    $first = ReviewTemplate::factory()->create(['is_default' => true]);

    $this->post(route('setup.kpi.frameworks.store'), frameworkPayload(['name' => 'The new default']));

    expect($first->refresh()->is_default)->toBeFalse()
        ->and(ReviewTemplate::where('is_default', true)->count())->toBe(1);
});

test('archiving a framework keeps it out of the pickers but not out of history', function () {
    actingAsSuperAdmin();
    $template = ReviewTemplate::factory()->create();

    $this->delete(route('setup.kpi.frameworks.destroy', $template->hashid));

    expect(ReviewTemplate::count())->toBe(0)
        ->and(ReviewTemplate::withTrashed()->count())->toBe(1);

    $this->patch(route('setup.kpi.frameworks.restore', $template->hashid));

    expect(ReviewTemplate::count())->toBe(1);
});

test('managing the framework needs the setup.kpi.manage permission', function () {
    actingAsUserWith(['setup.kpi.view']);

    $this->post(route('setup.kpi.frameworks.store'), frameworkPayload())->assertForbidden();
    $this->post(route('setup.kpi.scales.store'), [
        'name' => 'x', 'type' => 'numeric', 'min' => 1, 'max' => 5, 'step' => 1,
    ])->assertForbidden();
});

// ── Criteria ─────────────────────────────────────────────────────────────────

test('a criterion names the scale it is measured on', function () {
    actingAsSuperAdmin();
    $scale = RatingScale::factory()->create();

    $this->post(route('setup.kpi.criteria.store'), [
        'name' => 'Quality of work',
        'weight' => 25,
        'rating_scale_id' => $scale->id,
    ])->assertSessionHasNoErrors();

    expect(KpiCriterion::firstOrFail()->rating_scale_id)->toBe($scale->id);
});

test('a criterion cannot borrow another tenant’s rating scale', function () {
    actingAsSuperAdmin();

    $tenancy = app(Tenancy::class);
    $mine = $tenancy->organization();
    $tenancy->set(Organization::factory()->create());
    $foreign = RatingScale::factory()->create();
    $tenancy->set($mine);

    $this->post(route('setup.kpi.criteria.store'), [
        'name' => 'Borrowed', 'weight' => 10, 'rating_scale_id' => $foreign->id,
    ])->assertSessionHasErrors('rating_scale_id');
});

test('a criterion used by a framework cannot be permanently deleted', function () {
    actingAsSuperAdmin();
    $criterion = KpiCriterion::factory()->create();
    ReviewTemplate::factory()->create()->items()->create([
        'kpi_criterion_id' => $criterion->id,
        'section_key' => 'overall',
        'name' => $criterion->name,
        'weight' => 100,
    ]);
    $criterion->delete();

    $this->delete(route('setup.kpi.criteria.force-delete', $criterion->hashid));

    assertToast('warning', 'cannot be permanently deleted');
    expect(KpiCriterion::withTrashed()->count())->toBe(1);
});
