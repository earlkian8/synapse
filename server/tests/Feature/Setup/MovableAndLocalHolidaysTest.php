<?php

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Queries\AttendanceRecordsIndexQuery;
use App\Support\HolidayCalendar;
use App\Support\LeaveCalculator;
use App\Support\MovableDates;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| ADR 0078 — holidays that move (Holy Week from Easter, National Heroes Day on
| the last Monday of August) and holidays kept at some sites only.
*/

function localSite(string $name = 'Cebu Office'): WorkLocation
{
    return WorkLocation::create(['name' => $name, 'latitude' => 10.3157, 'longitude' => 123.8854, 'radius_meters' => 150]);
}

/** A Mon–Fri day worker, based at a site when one is given. */
function siteWorker(?WorkLocation $site = null): Employee
{
    $schedule = WorkSchedule::factory()->create(['start_time' => '08:00', 'end_time' => '17:00', 'work_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], 'grace_minutes' => 0, 'required_hours' => 8]);
    $employee = Employee::factory()->create(['work_schedule_id' => $schedule->id, 'employment_status' => 'active']);

    if ($site !== null) {
        $site->employees()->attach($employee->id, ['is_primary' => true]);
    }

    return $employee;
}

test('Easter and the n-th weekday of a month are worked out for any year', function () {
    expect(MovableDates::easter(2026)->toDateString())->toBe('2026-04-05')
        ->and(MovableDates::easter(2027)->toDateString())->toBe('2027-03-28')
        ->and(MovableDates::easter(2038)->toDateString())->toBe('2038-04-25')
        ->and(MovableDates::fromEaster(2026, -3)->toDateString())->toBe('2026-04-02')
        ->and(MovableDates::nthWeekday(2026, 8, 1, -1)->toDateString())->toBe('2026-08-31')
        ->and(MovableDates::nthWeekday(2027, 8, 1, -1)->toDateString())->toBe('2027-08-30')
        ->and(MovableDates::nthWeekday(2026, 11, 4, 4)->toDateString())->toBe('2026-11-26');
});

test('a holiday falls where its rule puts it, every year', function () {
    testOrganization();
    $goodFriday = Holiday::create(['name' => 'Good Friday', 'type' => 'regular', 'recurrence' => 'easter', 'rule' => ['offset' => -2]]);
    $heroes = Holiday::create(['name' => 'National Heroes Day', 'type' => 'regular', 'recurrence' => 'weekday', 'rule' => ['month' => 8, 'weekday' => 1, 'week' => -1]]);
    $christmas = Holiday::create(['name' => 'Christmas Day', 'date' => '2026-12-25', 'type' => 'regular', 'is_recurring' => true]);

    expect($goodFriday->is_recurring)->toBeTrue()
        ->and($goodFriday->date)->not->toBeNull()
        ->and($goodFriday->occurrenceIn(2027)->toDateString())->toBe('2027-03-26')
        ->and($goodFriday->repeatsLabel())->toBe('Every year, 2 days before Easter')
        ->and($heroes->repeatsLabel())->toBe('Every year on the last Monday of August')
        ->and($christmas->recurrence)->toBe('yearly')
        ->and($christmas->fallsOn(CarbonImmutable::parse('2031-12-25')))->toBeTrue();

    $dates = HolidayCalendar::datesInRange(CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31'));

    expect($dates)->toContain('2027-03-26', '2027-08-30', '2027-12-25');
});

test('the screen saves a rule without a date, and a site list makes it local', function () {
    actingAsSuperAdmin();
    $site = localSite();

    $this->post(route('setup.schedule.holidays.store'), [
        'name' => 'Maundy Thursday', 'type' => 'regular', 'recurrence' => 'easter', 'rule' => ['offset' => -3],
    ])->assertSessionHasNoErrors();

    $this->post(route('setup.schedule.holidays.store'), [
        'name' => 'Sinulog', 'type' => 'special_non_working', 'recurrence' => 'weekday',
        'rule' => ['month' => 1, 'weekday' => 7, 'week' => 3], 'location_ids' => [$site->id],
    ])->assertSessionHasNoErrors();

    $this->post(route('setup.schedule.holidays.store'), ['name' => 'Broken', 'type' => 'regular', 'recurrence' => 'weekday'])
        ->assertSessionHasErrors(['rule.month', 'rule.weekday', 'rule.week']);

    $sinulog = Holiday::query()->where('name', 'Sinulog')->firstOrFail();

    expect(Holiday::query()->where('name', 'Maundy Thursday')->value('recurrence'))->toBe('easter')
        ->and($sinulog->locations->pluck('id')->all())->toBe([$site->id])
        ->and($sinulog->occurrenceIn(2027)->toDateString())->toBe('2027-01-17');

    $this->get(route('setup.schedule.index'))->assertInertia(fn ($page) => $page
        ->has('locations', 1)
        ->where('holidays', fn ($holidays) => collect($holidays)->firstWhere('name', 'Sinulog')['locations'][0]['name'] === 'Cebu Office'));
});

test('Add Holy Week puts the movable holidays on as rules, turning a dated one into its rule', function () {
    actingAsSuperAdmin();
    Holiday::create(['name' => 'Good Friday', 'date' => '2026-04-03', 'type' => 'regular', 'is_recurring' => false]);

    $this->post(route('setup.schedule.holidays.movable'))->assertRedirect();
    assertToast('success');

    expect(Holiday::query()->where('name', 'Good Friday')->count())->toBe(1)
        ->and(Holiday::query()->where('name', 'Good Friday')->value('recurrence'))->toBe('easter')
        ->and(Holiday::query()->whereIn('recurrence', ['easter', 'weekday'])->count())->toBe(4);

    $this->post(route('setup.schedule.holidays.movable'))->assertRedirect();
    assertToast('info', 'already worked out every year');
});

test('a local holiday is a day off only for the people based at its site', function () {
    testOrganization();
    $cebu = localSite();
    $local = siteWorker($cebu);
    $elsewhere = siteWorker();

    $holiday = Holiday::create(['name' => 'Cebu Charter Day', 'date' => '2026-02-24', 'type' => 'special_non_working', 'is_recurring' => true]);
    $holiday->locations()->sync([$cebu->id]);

    $from = CarbonImmutable::parse('2026-02-23');
    $to = CarbonImmutable::parse('2026-02-25');

    // Leave: charged for the person elsewhere, not for the one at the site.
    expect(LeaveCalculator::chargeableDays($from, $to, false, HolidayCalendar::datesInRange($from, $to, HolidayCalendar::siteOf($local))))->toEqual(2)
        ->and(LeaveCalculator::chargeableDays($from, $to, false, HolidayCalendar::datesInRange($from, $to, HolidayCalendar::siteOf($elsewhere))))->toEqual(3);

    // Attendance: the board reads it as a holiday for one, an absence for the other.
    $this->travelTo(CarbonImmutable::parse('2026-02-26 10:00', 'Asia/Manila'));
    $roster = app(AttendanceRecordsIndexQuery::class)->roster('2026-02-24');

    expect($roster->firstWhere('employee_id', $local->id)->status)->toBe('holiday')
        ->and($roster->firstWhere('employee_id', $elsewhere->id)->status)->toBe('absent');

    $map = HolidayCalendar::forEmployees(collect([$local, $elsewhere]), $from, $to);

    expect($map->on('2026-02-24', $local->id)?->name)->toBe('Cebu Charter Day')
        ->and($map->on('2026-02-24', $elsewhere->id))->toBeNull()
        ->and($map->hasLocal())->toBeTrue();
});

test('the app lists the holidays a person keeps over the coming year', function () {
    $user = actingAsUserWith(['leave.request']);
    $cebu = localSite();
    $employee = siteWorker($cebu);
    $employee->update(['user_id' => $user->id]);
    $other = localSite('Davao Office');

    Holiday::create(['name' => 'Good Friday', 'type' => 'regular', 'recurrence' => 'easter', 'rule' => ['offset' => -2]]);
    Holiday::create(['name' => 'Cebu Charter Day', 'date' => '2026-02-24', 'type' => 'special_non_working', 'is_recurring' => true])->locations()->sync([$cebu->id]);
    Holiday::create(['name' => 'Kadayawan', 'date' => '2026-08-21', 'type' => 'special_non_working', 'is_recurring' => true])->locations()->sync([$other->id]);

    Sanctum::actingAs($user);
    $holidays = collect($this->getJson('/api/holidays')->assertOk()->json('data'));

    expect($holidays->pluck('name')->all())->toContain('Good Friday', 'Cebu Charter Day')
        ->and($holidays->pluck('name')->all())->not->toContain('Kadayawan')
        ->and($holidays->firstWhere('name', 'Cebu Charter Day')['site'])->toBe('Cebu Office')
        ->and($holidays->pluck('date')->all())->toBe($holidays->pluck('date')->sort()->values()->all());
});
