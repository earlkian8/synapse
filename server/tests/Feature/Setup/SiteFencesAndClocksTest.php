<?php

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\FenceGeometry;
use App\Support\Attendance\GeofenceCheck;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;

/*
| ADR 0079 — a fence drawn as a shape, and a site that keeps its own clock.
*/

/** A rectangle of about 220 × 110 m around Ayala Avenue, Makati. */
function makatiShape(): array
{
    return [[14.5560, 121.0230], [14.5560, 121.0250], [14.5550, 121.0250], [14.5550, 121.0230]];
}

test('a point is inside a shape, on its edge, or some metres off it', function () {
    $shape = makatiShape();

    expect(FenceGeometry::contains(14.5555, 121.0240, $shape))->toBeTrue()
        ->and(FenceGeometry::distanceMeters(14.5555, 121.0240, $shape))->toBe(0.0)
        ->and(FenceGeometry::contains(14.5560, 121.0240, $shape))->toBeTrue()
        ->and(FenceGeometry::contains(14.5570, 121.0240, $shape))->toBeFalse()
        ->and(round(FenceGeometry::distanceMeters(14.5570, 121.0240, $shape)))->toEqual(111.0)
        ->and(FenceGeometry::selfIntersects($shape))->toBeFalse()
        ->and(FenceGeometry::selfIntersects([[14.5560, 121.0230], [14.5550, 121.0250], [14.5560, 121.0250], [14.5550, 121.0230]]))->toBeTrue();
});

test('a shape fence gives the doubt of the phone\'s accuracy, like a circle', function () {
    testOrganization();
    $site = WorkLocation::create(['name' => 'Ayala', 'latitude' => 14.5555, 'longitude' => 121.024, 'radius_meters' => 130, 'fence_type' => 'polygon', 'polygon' => makatiShape()]);

    $inside = GeofenceCheck::check(14.5555, 121.0240, 5, collect([$site]));
    $near = GeofenceCheck::check(14.5570, 121.0240, 120, collect([$site]));
    $far = GeofenceCheck::check(14.5570, 121.0240, 20, collect([$site]));

    expect($inside->inside)->toBeTrue()
        ->and($inside->distanceMeters)->toBe(0)
        ->and($near->inside)->toBeTrue()
        ->and($far->inside)->toBeFalse()
        ->and($far->distanceMeters)->toBe(111);
});

test('the screen saves a shape with its centre and reach worked out, and refuses a crossed one', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.locations.store'), ['name' => 'Ayala', 'fence_type' => 'polygon', 'polygon' => makatiShape(), 'timezone' => 'Asia/Singapore'])
        ->assertSessionHasNoErrors();

    $site = WorkLocation::query()->firstOrFail();

    expect($site->fence_type)->toBe('polygon')
        ->and(round((float) $site->latitude, 4))->toEqual(14.5555)
        ->and(round((float) $site->longitude, 4))->toEqual(121.024)
        ->and($site->radius_meters)->toBeGreaterThan(100)->toBeLessThan(140)
        ->and($site->timezone)->toBe('Asia/Singapore')
        ->and($site->fenceLabel())->toStartWith('Shape · 4 corners');

    $this->post(route('setup.locations.store'), ['name' => 'Bow tie', 'fence_type' => 'polygon', 'polygon' => [[14.5560, 121.0230], [14.5550, 121.0250], [14.5560, 121.0250], [14.5550, 121.0230]]])
        ->assertSessionHasErrors('polygon');
    $this->post(route('setup.locations.store'), ['name' => 'Line', 'fence_type' => 'polygon', 'polygon' => [[14.5, 121.0], [14.6, 121.1]]])
        ->assertSessionHasErrors('polygon');
    $this->post(route('setup.locations.store'), ['name' => 'Nowhere', 'latitude' => 14.5, 'longitude' => 121.0, 'radius_meters' => 150, 'timezone' => 'Mars/Olympus'])
        ->assertSessionHasErrors('timezone');

    // Back to a circle: the shape goes.
    $this->post(route('setup.locations.update', $site->hashid), ['name' => 'Ayala', 'fence_type' => 'circle', 'latitude' => 14.5555, 'longitude' => 121.024, 'radius_meters' => 200])
        ->assertSessionHasNoErrors();

    expect($site->refresh()->polygon)->toBeNull()->and($site->radius_meters)->toBe(200);
});

test('somebody based at a site that keeps its own clock is judged on it', function () {
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();
    $schedule = WorkSchedule::factory()->create(['start_time' => '09:00', 'end_time' => '18:00', 'work_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], 'grace_minutes' => 0, 'required_hours' => 8]);
    $tokyo = WorkLocation::create(['name' => 'Tokyo', 'latitude' => 35.68, 'longitude' => 139.76, 'radius_meters' => 200, 'timezone' => 'Asia/Tokyo']);
    $remote = Employee::factory()->create(['work_schedule_id' => $schedule->id]);
    $tokyo->employees()->attach($remote->id, ['is_primary' => true]);
    $home = Employee::factory()->create(['work_schedule_id' => $schedule->id]);

    // 09:20 in Tokyo is 08:20 in Manila.
    $this->travelTo(CarbonImmutable::parse('2026-09-14 09:20', 'Asia/Tokyo'));
    $clock = app(AttendanceClock::class);
    $tokyoDay = $clock->punch($remote, 'clock_in', ['source' => 'web']);
    $manilaDay = $clock->punch($home, 'clock_in', ['source' => 'web']);

    expect($tokyoDay->late_minutes)->toBe(20)
        ->and($tokyoDay->rules['timezone'])->toBe('Asia/Tokyo')
        ->and($tokyoDay->scheduled_start_at->toIso8601String())->toBe(OrganizationClock::at('2026-09-14', '09:00', 'Asia/Tokyo')->toIso8601String())
        ->and($manilaDay->late_minutes)->toBe(0)
        ->and($manilaDay->rules['timezone'])->toBeNull();

    // A clock-in just after midnight in Tokyo is already the next day there.
    $this->travelTo(CarbonImmutable::parse('2026-09-15 23:30', 'Asia/Manila'));
    expect($clock->workDateFor($remote, CarbonImmutable::now(), 'clock_out'))->toBe('2026-09-16');

    // The board shows the day's times on its own clock.
    actingAsUserWith(['attendance.view']);
    $this->get(route('attendance.index', ['date' => '2026-09-14']))->assertInertia(fn ($page) => $page
        ->where('records', fn ($records) => collect($records)->firstWhere('hashid', AttendanceRecord::find($tokyoDay->id)->hashid)['timezone'] === 'Asia/Tokyo'));
});
