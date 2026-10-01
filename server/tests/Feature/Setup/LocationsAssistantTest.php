<?php

use App\Models\ActivityLog;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Services\Assistant\Modules\LocationsModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Tenancy;

/*
| The work-locations capability of the assistant: the sites, who is based
| where, and each site's defaults — changed by the Locations screen's own rules,
| and never a fence the model placed. Gemini is never called.
*/

function locationsAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(LocationsModule::class)->run($user, $tool, $args);
}

function site(string $name, array $overrides = []): WorkLocation
{
    return WorkLocation::create(['name' => $name, 'latitude' => 14.5547, 'longitude' => 121.0244, 'radius_meters' => 150, ...$overrides]);
}

function basedPerson(string $first, string $last): Employee
{
    return Employee::factory()->create(['first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix' => null]);
}

// ── Permissions ──────────────────────────────────────────────────────────────

test('a viewer reads; a manager changes; no tool places a site', function () {
    $tools = fn (User $user): array => array_column(app(LocationsModule::class)->tools($user), 'name');

    expect($tools(actingAsUserWith(['setup.locations.view'])))->toEqualCanonicalizing(['find_locations', 'get_location']);

    $manager = actingAsUserWith(['setup.locations.view', 'setup.locations.manage']);

    expect($tools($manager))->toContain('update_location', 'base_at_location', 'archive_location')
        ->not->toContain('create_location', 'delete_location')
        ->and(json_encode(app(LocationsModule::class)->tools($manager)))->not->toContain('latitude')->not->toContain('longitude');

    $module = app(LocationsModule::class);

    expect($module->requiresConfirmation('update_location'))->toBeTrue()
        ->and($module->requiresConfirmation('archive_location'))->toBeTrue()
        ->and($module->requiresConfirmation('base_at_location'))->toBeFalse();
});

test('where somebody is based needs the directory, and a made-up name gets the same answer', function () {
    actingAsSuperAdmin();
    basedPerson('Maria', 'Santos');

    $viewer = actingAsUserWith(['setup.locations.view']);

    expect(locationsAgent($viewer, 'find_locations', ['employee' => 'Maria Santos'])->detail)
        ->toBe(locationsAgent($viewer, 'find_locations', ['employee' => 'Nobody Atall'])->detail)
        ->toContain('permission');
});

// ── Reading ──────────────────────────────────────────────────────────────────

test('a site read-out has its fence, its people, its defaults and its punches', function () {
    $user = actingAsSuperAdmin();
    $worker = dayShiftWorker();
    $makati = site('Makati Office', ['default_work_schedule_id' => WorkSchedule::query()->value('id')]);
    $maria = basedPerson('Maria', 'Santos');
    $makati->employees()->attach([$maria->id => ['is_primary' => true], $worker->id => ['is_primary' => false]]);

    $this->travelTo(now()->subDay()->setTime(8, 0));
    app(AttendanceClock::class)->punch($worker, 'clock_in', ['source' => 'web', 'latitude' => 14.5547, 'longitude' => 121.0244, 'accuracy' => 5]);
    $this->travelBack();

    $meta = implode(' | ', locationsAgent($user, 'get_location', ['location' => 'makati'])->cards[0]['meta']);

    expect($meta)->toContain('Fence: 150 m around')
        ->toContain('2 people based here (1 as their primary site): Maria Santos (primary)')
        ->toContain('Default schedule: Day Shift')
        ->toContain('1 punch placed nearest this site, 0 of them outside the fence');

    expect(locationsAgent($user, 'find_locations', ['employee' => 'Maria Santos'])->cards[0]['badge'])->toBe('Primary site');
});

test('a question about sites warns when a policy checks a fence nobody drew', function () {
    $user = actingAsSuperAdmin();
    AttendancePolicy::create(['name' => 'On site', 'settings' => AttendancePolicySettings::fromArray(['capture' => ['geofence' => 'block']])->toArray(), 'settings_version' => 1]);

    $prompt = app(Retriever::class)->retrieve($user, 'what sites do we have?')?->toPrompt();

    expect($prompt)->toContain('No work locations have been drawn')
        ->toContain('On site (refuses punches off site)')
        ->toContain('there is no active site');
});

// ── Doing ────────────────────────────────────────────────────────────────────

test('a site is edited by the screen’s rules, its defaults named, never placed', function () {
    $user = actingAsSuperAdmin();
    site('Makati Office');
    site('Cebu Plant', ['latitude' => 10.3157, 'longitude' => 123.8854]);
    AttendancePolicy::create(['name' => 'Plant rules', 'settings' => AttendancePolicySettings::fallback()->toArray(), 'settings_version' => 1]);

    $tooSmall = locationsAgent($user, 'update_location', ['location' => 'Makati Office', 'radius_meters' => 10]);
    $taken = locationsAgent($user, 'update_location', ['location' => 'Makati Office', 'new_name' => 'cebu plant']);
    $unknown = locationsAgent($user, 'update_location', ['location' => 'Cebu Plant', 'default_schedule' => 'Graveyard']);
    $edited = locationsAgent($user, 'update_location', ['location' => 'Cebu Plant', 'radius_meters' => 400, 'attendance_policy' => 'plant rules']);

    $cebu = WorkLocation::query()->where('name', 'Cebu Plant')->firstOrFail();

    expect($tooSmall->detail)->toContain('at least 25 m')
        ->and($taken->detail)->toBe('There is already a location with that name.')
        ->and($unknown->detail)->toContain('No schedule is called “Graveyard”')
        ->and($edited->failed())->toBeFalse()
        ->and($cebu->radius_meters)->toBe(400)
        ->and($cebu->policy?->name)->toBe('Plant rules')
        ->and((float) $cebu->latitude)->toBe(10.3157)
        ->and(ActivityLog::query()->where('description', 'Updated work location "Cebu Plant" via assistant')->exists())->toBeTrue();
});

test('people are based at a site, made primary there, and unbased — only those named', function () {
    $user = actingAsSuperAdmin();
    $makati = site('Makati Office');
    $annex = site('Annex');
    $maria = basedPerson('Maria', 'Santos');
    $ben = basedPerson('Ben', 'Cruz');
    $annex->employees()->attach($maria->id, ['is_primary' => true]);

    locationsAgent($user, 'base_at_location', ['location' => 'Makati Office', 'people' => ['Maria Santos', 'Ben Cruz'], 'primary' => true]);

    expect($makati->employees()->wherePivot('is_primary', true)->count())->toBe(2)
        ->and($annex->employees()->wherePivot('is_primary', true)->count())->toBe(0)
        ->and($annex->employees()->count())->toBe(1);

    $stranger = locationsAgent($user, 'unbase_from_location', ['location' => 'Annex', 'people' => ['Ben Cruz']]);
    $unbased = locationsAgent($user, 'unbase_from_location', ['location' => 'Makati Office', 'people' => ['Ben Cruz']]);

    expect($stranger->detail)->toContain('Ben Cruz is not based at Annex')
        ->and($unbased->failed())->toBeFalse()
        ->and($makati->employees()->pluck('employees.id')->all())->toBe([$maria->id])
        ->and(ActivityLog::query()->where('description', 'Based 2 people at "Makati Office" as their primary site via assistant')->exists())->toBeTrue();
});

test('the confirmation says who is based at the site', function () {
    $user = actingAsSuperAdmin();
    $makati = site('Makati Office');
    $makati->employees()->attach([basedPerson('Ana', 'Reyes')->id => ['is_primary' => true], basedPerson('Ben', 'Cruz')->id => ['is_primary' => false]]);

    expect(app(LocationsModule::class)->consequence($user, 'archive_location', ['location' => 'Makati Office']))
        ->toContain('2 people are based here (1 as their primary site)')
        ->toContain('stop being checked against it');
});

test('archiving and restoring go through the workflow, and another workspace’s sites are never found', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();
    site('Makati Office');

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => site('Their Site'));
    app(Tenancy::class)->set($mine);

    locationsAgent($user, 'archive_location', ['location' => 'Makati Office']);
    expect(WorkLocation::query()->count())->toBe(0);

    locationsAgent($user, 'restore_location', ['location' => 'Makati Office']);

    expect(WorkLocation::query()->count())->toBe(1)
        ->and(locationsAgent($user, 'get_location', ['location' => 'Their Site'])->failed())->toBeTrue()
        ->and(array_column(locationsAgent($user, 'find_locations')->cards, 'title'))->toBe(['Makati Office']);
});
