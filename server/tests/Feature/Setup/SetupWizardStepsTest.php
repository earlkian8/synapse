<?php

use App\Models\AttendanceDevice;
use App\Models\AwardType;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\OffboardingProgram;
use App\Models\OnboardingProgram;
use App\Models\Organization;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Support\Setup\CompanySetup;
use App\Support\Setup\SetupBlueprints;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The wizard as a route through all of Company Setup: every screen is a step
 * that carries that screen whole, the view lives in the URL so the screen's own
 * editors can post and come back to it, and the steps new to the wizard —
 * schedules & holidays, locations, devices, the roster, onboarding, awards and
 * offboarding — each start from something where something sensible exists.
 */

/** The acting tenant as registration leaves it: nothing answered, not finished. */
function tenantOwingSetup(): Organization
{
    $organization = testOrganization();
    $organization->forceFill(['setup_completed_at' => null, 'setup_steps' => null])->save();

    return $organization;
}

/** The props an Inertia page was rendered with. */
function renderedProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/** A registered device — its key issued the way the Devices screen issues it. */
function registeredDevice(): AttendanceDevice
{
    $device = new AttendanceDevice(['name' => 'Front door', 'type' => 'kiosk', 'is_active' => true]);
    $device->issueKey();
    $device->save();

    return $device;
}

/** Each step, and the Company Setup screen it carries. */
dataset('step screens', [
    'company' => [CompanySetup::COMPANY, 'setup.company.edit'],
    'departments' => [CompanySetup::DEPARTMENTS, 'setup.departments.index'],
    'attendance' => [CompanySetup::ATTENDANCE, 'setup.attendance-policies.index'],
    'schedule' => [CompanySetup::SCHEDULE, 'setup.schedule.index'],
    'leave' => [CompanySetup::LEAVE_TYPES, 'setup.leave-types.index'],
    'locations' => [CompanySetup::LOCATIONS, 'setup.locations.index'],
    'devices' => [CompanySetup::DEVICES, 'setup.devices.index'],
    'roster' => [CompanySetup::ROSTER, 'setup.roster.index'],
    'hiring' => [CompanySetup::RECRUITMENT, 'setup.recruitment-pipelines.index'],
    'onboarding' => [CompanySetup::ONBOARDING, 'setup.onboarding.index'],
    'appraisals' => [CompanySetup::PERFORMANCE, 'setup.kpi.index'],
    'awards' => [CompanySetup::AWARDS, 'setup.award-types.index'],
    'offboarding' => [CompanySetup::OFFBOARDING, 'setup.offboarding.index'],
]);

// ── Every Company Setup screen is a step ─────────────────────────────────────

test('the wizard has a step for every Company Setup screen, each gated by that screen’s own ability', function () {
    expect(CompanySetup::STEPS)->toHaveCount(13)
        ->and(array_keys(CompanySetup::ABILITIES))->toBe(CompanySetup::STEPS)
        ->and(array_keys(CompanySetup::SCREENS))->toBe(CompanySetup::STEPS);
});

test('a step carries exactly what its Company Setup screen shows', function (string $step, string $screenRoute) {
    actingAsSuperAdmin();

    // The factory's company has finished setup, so both are reachable.
    $page = renderedProps($this->get(route($screenRoute))->assertOk());
    $wizard = renderedProps($this->get(route('setup.wizard.show', $step))->assertOk());

    // What every page is sent (auth, notifications and the like) is whatever
    // the wizard was sent beyond its own props.
    $wizardOwn = ['view', 'company', 'progress', 'configured', 'screen', 'blueprints', 'existing', 'can', 'canInvite'];
    $shared = array_diff(array_keys($wizard), $wizardOwn);

    expect($wizard['view'])->toBe($step)
        ->and(array_keys($wizard['screen']))
        ->toEqualCanonicalizing(array_values(array_diff(array_keys($page), $shared)));
})->with('step screens');

test('each step renders its own screen, and only its own', function () {
    actingAsSuperAdmin();
    tenantOwingSetup();
    Department::create(['name' => 'Human Resources', 'code' => 'HR']);

    $this->get(route('setup.wizard.show', CompanySetup::DEPARTMENTS))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('setup/wizard')
            ->where('view', CompanySetup::DEPARTMENTS)
            ->has('screen.departments', 1)
            ->has('screen.options.schedules')
            ->where('screen.can.manage', true)
            ->missing('screen.policies')
            ->etc());
});

test('the welcome and the send-off carry no screen', function (string $view) {
    actingAsSuperAdmin();
    tenantOwingSetup();

    $this->get(route('setup.wizard.show', $view))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('view', $view)->where('screen', null)->etc());
})->with([CompanySetup::INTRO, CompanySetup::FINISH]);

test('a view that is not one of the wizard’s is not found', function () {
    actingAsSuperAdmin();

    $this->get('/setup/wizard/payroll')->assertNotFound();
});

test('a step the person may not configure is shown without its screen', function () {
    actingAsUserWith(['setup.company.manage']);
    tenantOwingSetup();

    $this->get(route('setup.wizard.show', CompanySetup::LOCATIONS))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', CompanySetup::LOCATIONS)
            ->where('can.locations', false)
            ->where('screen', null)
            ->etc());
});

test('the roster step reads its week from the query, as the roster screen does', function () {
    actingAsSuperAdmin();
    tenantOwingSetup();

    $this->get(route('setup.wizard.show', ['view' => CompanySetup::ROSTER, 'date' => '2026-03-11']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('screen.filters.date', '2026-03-11')
            ->has('screen.roster.days', 7)
            ->has('screen.roster.rows')
            ->etc());
});

test('the company step carries the join code, as Employees → Access does', function () {
    actingAsSuperAdmin();
    $organization = tenantOwingSetup();

    $this->get(route('setup.wizard.show', CompanySetup::COMPANY))
        ->assertInertia(fn (Assert $page) => $page
            ->where('screen.joinCode.code', $organization->join_code)
            ->where('screen.joinCode.enabled', (bool) $organization->join_code_enabled)
            ->has('screen.timezones')
            ->etc());
});

test('the join code is a credential — the profile never shows it to a view-only visitor', function () {
    actingAsUserWith(['setup.company.view']);

    $this->get(route('setup.company.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('joinCode', null)->where('can.manage', false)->etc());
});

test('an editor on a step saves and comes back to that step', function () {
    actingAsSuperAdmin();
    tenantOwingSetup();

    // The Company Setup editors post to their own routes and answer `back()`.
    $this->from(route('setup.wizard.show', CompanySetup::LOCATIONS))
        ->post(route('setup.locations.store'), [
            'name' => 'Head Office',
            'latitude' => 14.5547,
            'longitude' => 121.0244,
            'radius_meters' => 150,
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('setup.wizard.show', CompanySetup::LOCATIONS));

    expect(WorkLocation::where('name', 'Head Office')->exists())->toBeTrue();
});

// ── Where the wizard opens ───────────────────────────────────────────────────

test('with no view named, the wizard opens where the company left off', function () {
    actingAsSuperAdmin();
    $organization = tenantOwingSetup();

    expect(CompanySetup::initialView($organization))->toBe(CompanySetup::INTRO);

    CompanySetup::markStep($organization, CompanySetup::COMPANY, CompanySetup::DONE);
    CompanySetup::markStep($organization, CompanySetup::DEPARTMENTS, CompanySetup::SKIPPED);

    expect(CompanySetup::initialView($organization->refresh()))->toBe(CompanySetup::ATTENDANCE);

    $this->get(route('setup.wizard.show'))
        ->assertInertia(fn (Assert $page) => $page->where('view', CompanySetup::ATTENDANCE)->etc());

    foreach (CompanySetup::STEPS as $step) {
        CompanySetup::markStep($organization, $step, CompanySetup::SKIPPED);
    }

    expect(CompanySetup::initialView($organization->refresh()))->toBe(CompanySetup::FINISH);
});

test('a company that finished setup opens on the send-off', function () {
    actingAsSuperAdmin();

    expect(CompanySetup::initialView(testOrganization()))->toBe(CompanySetup::FINISH);

    $this->get(route('setup.wizard.show'))
        ->assertInertia(fn (Assert $page) => $page->where('view', CompanySetup::FINISH)->etc());
});

// ── Continuing from a step done with its own editors ─────────────────────────

test('continuing records a step done once its module holds something', function () {
    actingAsSuperAdmin();
    $organization = tenantOwingSetup();

    $this->post(route('setup.wizard.continue'), ['step' => CompanySetup::LOCATIONS])
        ->assertSessionHasErrors('step');

    expect(CompanySetup::statuses($organization->refresh())['locations'])->toBe(CompanySetup::PENDING);

    WorkLocation::create([
        'name' => 'Warehouse', 'latitude' => 14.6, 'longitude' => 121.0, 'radius_meters' => 200, 'is_active' => true,
    ]);

    $this->post(route('setup.wizard.continue'), ['step' => CompanySetup::LOCATIONS])
        ->assertSessionHasNoErrors();

    expect(CompanySetup::statuses($organization->refresh())['locations'])->toBe(CompanySetup::DONE);
});

test('the wizard says which steps are configured, however they were configured', function () {
    actingAsSuperAdmin();
    tenantOwingSetup();

    registeredDevice();

    $this->get(route('setup.wizard.show', CompanySetup::DEVICES))
        ->assertInertia(fn (Assert $page) => $page
            ->where('configured.devices', true)
            ->where('configured.locations', false)
            ->where('configured.company', true)
            ->etc());
});

test('the roster counts as configured once there is a default schedule to be rostered on', function () {
    actingAsSuperAdmin();
    $organization = tenantOwingSetup();

    expect(CompanySetup::configured($organization)['roster'])->toBeFalse();

    $schedule = WorkSchedule::create(['name' => 'Office Hours', 'type' => 'fixed', 'cycle_length_days' => 7, 'grace_minutes' => 0]);
    $organization->forceFill(['default_work_schedule_id' => $schedule->id])->save();

    expect(CompanySetup::configured($organization->refresh())['roster'])->toBeTrue();
});

test('continuing is held to the step module’s own permission', function () {
    actingAsUserWith(['setup.company.manage']);
    tenantOwingSetup();
    registeredDevice();

    $this->post(route('setup.wizard.continue'), ['step' => CompanySetup::DEVICES])->assertForbidden();

    actingAsUserWith(['setup.company.manage', 'setup.devices.manage']);
    tenantOwingSetup();

    $this->post(route('setup.wizard.continue'), ['step' => CompanySetup::DEVICES])->assertSessionHasNoErrors();
});

test('continuing refuses a step that is not one of the wizard’s', function () {
    actingAsSuperAdmin();
    tenantOwingSetup();

    $this->post(route('setup.wizard.continue'), ['step' => 'payroll'])->assertSessionHasErrors('step');
});

// ── Schedules & holidays ─────────────────────────────────────────────────────

test('the holiday calendar puts every movable holiday on its next date', function () {
    $before = collect(SetupBlueprints::holidays(CarbonImmutable::parse('2026-01-10')))->keyBy('key');
    $after = collect(SetupBlueprints::holidays(CarbonImmutable::parse('2026-09-25')))->keyBy('key');

    // Easter 2026 is 5 April; 2027 is 28 March. The last Monday of August 2026
    // is the 31st; of 2027, the 30th.
    expect($before['maundy-thursday']['date'])->toBe('2026-04-02')
        ->and($before['good-friday']['date'])->toBe('2026-04-03')
        ->and($before['black-saturday']['date'])->toBe('2026-04-04')
        ->and($before['national-heroes-day']['date'])->toBe('2026-08-31')
        ->and($after['maundy-thursday']['date'])->toBe('2027-03-25')
        ->and($after['national-heroes-day']['date'])->toBe('2027-08-30')
        ->and($after['maundy-thursday']['is_recurring'])->toBeFalse()
        ->and($after['christmas-day'])->toMatchArray(['date' => '2026-12-25', 'type' => 'regular', 'is_recurring' => true])
        ->and($after['ninoy-aquino-day']['type'])->toBe('special_non_working');
});

test('a holiday on today is still its next date', function () {
    $holidays = collect(SetupBlueprints::holidays(CarbonImmutable::parse('2026-08-31 15:00')))->keyBy('key');

    expect($holidays['national-heroes-day']['date'])->toBe('2026-08-31');
});

test('the holidays step adds the ticked holidays, on the dates the server resolves', function () {
    actingAsSuperAdmin();
    $organization = tenantOwingSetup();

    $this->post(route('setup.wizard.holidays'), [
        'keys' => ['christmas-day', 'good-friday'],
        // A date is never the client's to say.
        'date' => '1999-01-01',
    ])->assertSessionHasNoErrors();

    $offered = collect(SetupBlueprints::holidays(now()))->keyBy('key');

    expect(Holiday::pluck('name')->sort()->values()->all())->toBe(['Christmas Day', 'Good Friday'])
        ->and(Holiday::where('name', 'Good Friday')->first()->date->toDateString())->toBe($offered['good-friday']['date'])
        ->and(Holiday::where('name', 'Christmas Day')->value('is_recurring'))->toBeTrue()
        ->and(CompanySetup::statuses($organization->refresh())['schedule'])->toBe(CompanySetup::DONE);
});

test('the holidays step passes over a holiday the company already has', function () {
    actingAsSuperAdmin();
    tenantOwingSetup();
    Holiday::create(['name' => 'Christmas Day', 'date' => '2026-12-25', 'type' => 'regular', 'is_recurring' => true]);

    $this->post(route('setup.wizard.holidays'), ['keys' => ['christmas-day', 'rizal-day']])
        ->assertSessionHasNoErrors();

    expect(Holiday::where('name', 'Christmas Day')->count())->toBe(1)
        ->and(Holiday::count())->toBe(2);
});

test('the holidays step refuses nothing ticked and a holiday not on offer', function () {
    actingAsSuperAdmin();
    tenantOwingSetup();

    $this->post(route('setup.wizard.holidays'), ['keys' => []])->assertSessionHasErrors('keys');
    $this->post(route('setup.wizard.holidays'), ['keys' => ['founders-day']])->assertSessionHasErrors('keys.0');

    expect(Holiday::count())->toBe(0);
});

// ── Awards ───────────────────────────────────────────────────────────────────

test('the awards step adds the ticked award types, once each', function () {
    actingAsSuperAdmin();
    $organization = tenantOwingSetup();
    AwardType::create(['name' => 'Spot Award', 'is_active' => true]);

    $this->post(route('setup.wizard.award-types'), ['keys' => ['employee-of-the-month', 'spot-award']])
        ->assertSessionHasNoErrors();

    expect(AwardType::pluck('name')->sort()->values()->all())->toBe(['Employee of the Month', 'Spot Award'])
        ->and(AwardType::where('name', 'Employee of the Month')->value('color'))->toBe('#f59e0b')
        ->and(CompanySetup::statuses($organization->refresh())['awards'])->toBe(CompanySetup::DONE);
});

test('the awards step refuses an award not on offer', function () {
    actingAsSuperAdmin();
    tenantOwingSetup();

    $this->post(route('setup.wizard.award-types'), ['keys' => ['best-dressed']])->assertSessionHasErrors('keys.0');
});

// ── Onboarding & offboarding ─────────────────────────────────────────────────

test('the onboarding step adopts the standard checklist as the default', function () {
    actingAsSuperAdmin();
    $organization = tenantOwingSetup();

    $this->post(route('setup.wizard.onboarding'), ['blueprint' => 'standard'])->assertSessionHasNoErrors();

    $program = OnboardingProgram::with('tasks')->sole();
    $blueprint = SetupBlueprints::onboardingPrograms()[0];

    expect($program->name)->toBe('Standard Onboarding')
        ->and($program->is_default)->toBeTrue()
        ->and($program->tasks)->toHaveCount(count($blueprint['tasks']))
        ->and($program->tasks->sortBy('sort_order')->first()->title)->toBe($blueprint['tasks'][0]['title'])
        ->and(CompanySetup::statuses($organization->refresh())['onboarding'])->toBe(CompanySetup::DONE);
});

test('an adopted checklist can go by the company’s own name, and is never adopted twice', function () {
    actingAsSuperAdmin();
    tenantOwingSetup();

    $this->post(route('setup.wizard.onboarding'), ['blueprint' => 'standard', 'name' => 'First Month'])
        ->assertSessionHasNoErrors();
    $this->post(route('setup.wizard.onboarding'), ['blueprint' => 'standard', 'name' => 'First Month'])
        ->assertSessionHasNoErrors();

    expect(OnboardingProgram::pluck('name')->all())->toBe(['First Month']);
});

test('the offboarding step routes each clearance item to the department carrying its code', function () {
    actingAsSuperAdmin();
    tenantOwingSetup();
    $it = Department::create(['name' => 'Information Technology', 'code' => 'IT']);
    $hr = Department::create(['name' => 'People', 'code' => 'HR']);

    $this->post(route('setup.wizard.offboarding'), ['blueprint' => 'standard'])->assertSessionHasNoErrors();

    $program = OffboardingProgram::with('items')->sole();
    $items = $program->items->keyBy('item');

    expect($program->is_default)->toBeTrue()
        ->and($items['Return laptop, peripherals & assigned devices']->department_id)->toBe($it->id)
        ->and($items['Conduct exit interview']->department_id)->toBe($hr->id)
        // No Finance department yet, so its items wait unrouted.
        ->and($items['Process final pay & last salary release']->department_id)->toBeNull()
        ->and($items['Knowledge transfer & turnover of responsibilities']->use_employee_department)->toBeTrue();
});

test('a checklist step refuses a checklist not on offer', function (string $route) {
    actingAsSuperAdmin();
    tenantOwingSetup();

    $this->post(route($route), ['blueprint' => 'bespoke'])->assertSessionHasErrors('blueprint');
})->with(['setup.wizard.onboarding', 'setup.wizard.offboarding']);

// ── Authorization & isolation ────────────────────────────────────────────────

test('each new step is gated by the permission of the module it configures', function (string $route, array $payload, string $ability) {
    actingAsUserWith(['setup.company.manage']);
    tenantOwingSetup();

    $this->post(route($route), $payload)->assertForbidden();

    actingAsUserWith(['setup.company.manage', $ability]);
    tenantOwingSetup();

    $this->post(route($route), $payload)->assertSessionHasNoErrors();
})->with([
    ['setup.wizard.holidays', ['keys' => ['labor-day']], 'setup.schedule.manage'],
    ['setup.wizard.award-types', ['keys' => ['spot-award']], 'setup.award-types.manage'],
    ['setup.wizard.onboarding', ['blueprint' => 'standard'], 'onboarding.manage-programs'],
    ['setup.wizard.offboarding', ['blueprint' => 'standard'], 'offboarding.manage-programs'],
]);

test('what the new steps create lands in the acting tenant and nowhere else', function () {
    actingAsSuperAdmin();
    $mine = tenantOwingSetup();
    $other = Organization::factory()->create();

    $this->post(route('setup.wizard.holidays'), ['keys' => ['labor-day']])->assertSessionHasNoErrors();
    $this->post(route('setup.wizard.award-types'), ['keys' => ['spot-award']])->assertSessionHasNoErrors();
    $this->post(route('setup.wizard.onboarding'), ['blueprint' => 'standard'])->assertSessionHasNoErrors();

    expect(Holiday::where('organization_id', $mine->id)->count())->toBe(1);

    app(Tenancy::class)->runFor($other, function (): void {
        expect(Holiday::count())->toBe(0)
            ->and(AwardType::count())->toBe(0)
            ->and(OnboardingProgram::count())->toBe(0);
    });
});

// ── The send-off ─────────────────────────────────────────────────────────────

test('finishing can hand straight over to bringing people in', function () {
    actingAsSuperAdmin();
    $organization = tenantOwingSetup();

    $this->post(route('setup.wizard.finish'), ['next' => 'people'])
        ->assertRedirect(route('employees.access'));

    expect($organization->refresh()->hasFinishedSetup())->toBeTrue();
});

test('somebody who cannot invite people is taken to the dashboard instead', function () {
    actingAsUserWith(['setup.company.manage']);
    tenantOwingSetup();

    $this->post(route('setup.wizard.finish'), ['next' => 'people'])
        ->assertRedirect(route('dashboard'));
});
