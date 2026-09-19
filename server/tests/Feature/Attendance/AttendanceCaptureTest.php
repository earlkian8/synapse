<?php

use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\AttendancePunchException;
use App\Support\Attendance\AttendanceRequestApprover;
use App\Support\Attendance\GeofenceCheck;
use App\Support\Attendance\PolicyResolver;
use App\Support\Attendance\SchedulePatternWriter;
use App\Support\Attendance\ShiftResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| ADR 0040 — punch capture: a person is held to the day's policy (where they may
| punch from, from which address, with a selfie, inside the fence); a phone's
| offline punch is judged at the time it was made, within a window; and every
| punch keeps where it was made.
|
| "Today" is Friday 2026-09-18, 08:05 in Manila. The office sits at 14.5547 N,
| 121.0244 E with a 100 m fence.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:05', 'Asia/Manila'));
    testOrganization();
});

/** The office, a 100 m fence. */
function office(array $attributes = []): WorkLocation
{
    return WorkLocation::create([
        'name' => 'Makati Office',
        'latitude' => 14.5547,
        'longitude' => 121.0244,
        'radius_meters' => 100,
        ...$attributes,
    ]);
}

/** A point this many metres due north of the office. */
function northOf(float $meters): array
{
    return ['latitude' => 14.5547 + $meters / 111_195, 'longitude' => 121.0244];
}

/** A worker on the day shift judged by a policy with these capture settings. */
function captureWorker(array $capture = [], ?User $user = null): Employee
{
    return requestWorker($user, ['capture' => [...AttendancePolicySettings::fallback()->toArray()['capture'], ...$capture]]);
}

// ── The fence ────────────────────────────────────────────────────────────────

dataset('fence edges', [
    'at the centre' => [0, null, true],
    'inside the fence' => [80, 5, true],
    'just outside, sure of it' => [130, 10, false],
    'outside, but the doubt reaches in' => [130, 40, true],
    'far outside, however unsure' => [900, 300, false],
]);

test('a position is inside when it, give or take its accuracy, touches the fence', function (float $meters, ?float $accuracy, bool $inside) {
    $verdict = GeofenceCheck::check(...[...array_values(northOf($meters)), $accuracy, collect([office()])]);

    expect($verdict->inside)->toBe($inside)
        ->and($verdict->distanceMeters)->toBe((int) round($meters))
        ->and($verdict->location->name)->toBe('Makati Office');
})->with('fence edges');

test('the haversine distance is exact on a meridian and on the equator', function () {
    // One degree of arc on a sphere of the Earth's mean radius: 111,195 m.
    expect(GeofenceCheck::distanceMeters(0, 0, 0, 1))->toEqualWithDelta(111_195.08, 0.5)
        ->and(GeofenceCheck::distanceMeters(14, 121, 15, 121))->toEqualWithDelta(111_195.08, 0.5)
        ->and(GeofenceCheck::distanceMeters(14.5547, 121.0244, 14.5547, 121.0244))->toBe(0.0);
});

test('the verdict names the site the punch was inside, or else the nearest', function () {
    office();
    $warehouse = office(['name' => 'Warehouse', 'latitude' => 14.5600, 'longitude' => 121.0244, 'radius_meters' => 50]);

    $inside = GeofenceCheck::check(14.5600, 121.0244, 5, WorkLocation::all());
    // 200 m from the office, 390 m from the warehouse: inside neither.
    $between = GeofenceCheck::check(...[...array_values(northOf(200)), 5, WorkLocation::all()]);

    expect($inside->location->is($warehouse))->toBeTrue()
        ->and($inside->inside)->toBeTrue()
        ->and($between->inside)->toBeFalse()
        ->and($between->location->name)->toBe('Makati Office');
});

// ── Off, flag, block ─────────────────────────────────────────────────────────

test('with the fence off, a punch keeps where it was and nothing is flagged', function () {
    $user = actingAsUserWith(['attendance.clock']);
    $employee = captureWorker(['geofence' => 'off'], $user);
    office();
    Sanctum::actingAs($user);

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in', ...northOf(400), 'accuracy' => 10])->assertOk();

    $punch = AttendancePunch::sole();
    $record = AttendanceRecord::sole();

    expect($punch->within_geofence)->toBeFalse()
        ->and($punch->distance_meters)->toBe(400)
        ->and($punch->location->name)->toBe('Makati Office')
        ->and($record->flags)->not->toContain('outside_geofence')
        ->and($record->approval_status)->toBeNull();
});

test('flag mode accepts a punch off site and puts the day in front of a manager', function () {
    $user = actingAsUserWith(['attendance.clock']);
    captureWorker(['geofence' => 'flag'], $user);
    office();
    Sanctum::actingAs($user);

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in', ...northOf(400), 'accuracy' => 10])->assertOk();

    $record = AttendanceRecord::sole();

    expect($record->flags)->toContain('outside_geofence')
        ->and($record->approval_status)->toBe('pending');
});

test('flag mode counts a punch with no position as not shown to be on site', function () {
    $user = actingAsUserWith(['attendance.clock']);
    captureWorker(['geofence' => 'flag'], $user);
    office();
    Sanctum::actingAs($user);

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in'])->assertOk();

    expect(AttendancePunch::sole()->within_geofence)->toBeFalse()
        ->and(AttendanceRecord::sole()->flags)->toContain('outside_geofence');
});

test('block mode refuses a punch off site, naming the site and how far, and writes nothing', function () {
    $user = actingAsUserWith(['attendance.clock']);
    captureWorker(['geofence' => 'block'], $user);
    office();
    Sanctum::actingAs($user);

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in', ...northOf(1500), 'accuracy' => 10])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You are 1.5 km from Makati Office. Punches have to be made on site.');

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your attendance policy needs to know you are on site. Turn on location and try again.');

    expect(AttendanceRecord::count())->toBe(0)->and(AttendancePunch::count())->toBe(0);

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in', ...northOf(60), 'accuracy' => 10])->assertOk();

    expect(AttendancePunch::sole()->within_geofence)->toBeTrue()
        ->and(AttendanceRecord::sole()->flags)->not->toContain('outside_geofence');
});

test('somebody based at a site is checked against it, not every site', function () {
    $user = actingAsUserWith(['attendance.clock']);
    $employee = captureWorker(['geofence' => 'block'], $user);
    office();
    $warehouse = office(['name' => 'Warehouse', 'latitude' => 14.6000, 'longitude' => 121.0244]);
    $warehouse->employees()->attach($employee->id, ['is_primary' => true]);
    Sanctum::actingAs($user);

    // At the office, which is not theirs.
    $this->postJson('/api/attendance/punch', ['type' => 'clock_in', ...northOf(0), 'accuracy' => 10])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'from Warehouse'));
});

test('a company with no locations has nothing to check against, so nothing is', function () {
    $user = actingAsUserWith(['attendance.clock']);
    captureWorker(['geofence' => 'block'], $user);
    Sanctum::actingAs($user);

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in', ...northOf(5000)])->assertOk();

    expect(AttendancePunch::sole()->within_geofence)->toBeNull();
});

test('remote work exempts the day from the geofence', function () {
    $user = actingAsUserWith(['attendance.clock']);
    $employee = captureWorker(['geofence' => 'block'], $user);
    office();
    AttendanceRequest::create([
        'employee_id' => $employee->id, 'type' => 'remote_work', 'start_date' => '2026-09-18', 'end_date' => '2026-09-18',
        'payload' => [], 'reason' => 'Working from home.', 'status' => 'approved',
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in', ...northOf(8000), 'accuracy' => 10])->assertOk();

    $record = AttendanceRecord::sole();

    expect(AttendancePunch::sole()->within_geofence)->toBeFalse()
        ->and($record->flags)->toContain('remote_work')
        ->and($record->flags)->not->toContain('outside_geofence')
        ->and($record->approval_status)->toBeNull();
});

test('approving remote work afterwards clears the day’s geofence flag', function () {
    $user = actingAsUserWith(['attendance.clock']);
    $employee = captureWorker(['geofence' => 'flag'], $user);
    office();
    Sanctum::actingAs($user);

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in', ...northOf(8000), 'accuracy' => 10])->assertOk();
    expect(AttendanceRecord::sole()->flags)->toContain('outside_geofence');

    $request = pendingRequest($employee, 'remote_work', '2026-09-18', []);
    $reviewer = actingAsUserWith(['attendance.requests.review']);
    app(AttendanceRequestApprover::class)->approve($request, $reviewer);

    expect(AttendanceRecord::sole()->flags)->not->toContain('outside_geofence');
});

// ── Capture rules ────────────────────────────────────────────────────────────

test('a punch from a source the policy does not allow is refused', function () {
    $user = actingAsUserWith(['attendance.clock']);
    captureWorker(['allowed_sources' => ['mobile', 'kiosk']], $user);

    $this->post(route('attendance.me.punch'), ['type' => 'clock_in']);

    assertToast('warning', 'does not allow punching from the web');
    expect(AttendanceRecord::count())->toBe(0);

    Sanctum::actingAs($user);
    $this->postJson('/api/attendance/punch', ['type' => 'clock_in'])->assertOk();
});

test('entry by HR is refused when the policy takes it away, and leaves no day behind', function () {
    $employee = captureWorker(['allowed_sources' => ['mobile']]);
    actingAsUserWith(['attendance.manage', 'attendance.view']);

    $this->post(route('attendance.store'), [
        'employee_id' => $employee->id, 'work_date' => '2026-09-17', 'time_in' => '08:00', 'time_out' => '17:00',
    ]);

    assertToast('warning', 'does not allow punching from entry by HR');
    expect(AttendanceRecord::count())->toBe(0);
});

test('a phone punch without a selfie is refused when the policy needs one', function () {
    Storage::fake('public');
    $user = actingAsUserWith(['attendance.clock']);
    captureWorker(['selfie_required' => true], $user);
    Sanctum::actingAs($user);

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your attendance policy needs a selfie with every punch from the app. Take one and try again.');

    $this->post('/api/attendance/punch', ['type' => 'clock_in', 'photo' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json'])
        ->assertOk();
});

test('a web punch is accepted only from an allowed address, read through a trusted proxy', function () {
    config(['trustedproxy.proxies' => '10.0.0.1']);
    $user = actingAsUserWith(['attendance.clock']);
    captureWorker(['web_ip_allowlist' => ['203.0.113.0/24']], $user);

    // Somebody at home, forging the header the office proxy would send.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.20'])
        ->post(route('attendance.me.punch'), ['type' => 'clock_in']);

    assertToast('warning', 'only accepted from the office network');
    expect(AttendanceRecord::count())->toBe(0);

    // Through the office's proxy, from inside the range.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.20'])
        ->post(route('attendance.me.punch'), ['type' => 'clock_in']);

    assertToast('success');
    expect(AttendancePunch::sole()->source)->toBe('web');
});

// ── Offline punches ──────────────────────────────────────────────────────────

test('a punch queued offline is judged at the time the phone gave it', function () {
    $user = actingAsUserWith(['attendance.clock']);
    captureWorker([], $user);
    Sanctum::actingAs($user);

    $this->travelTo(CarbonImmutable::parse('2026-09-18 11:00', 'Asia/Manila'));

    $this->postJson('/api/attendance/punch', [
        'type' => 'clock_in',
        'client_id' => 'phone-1',
        'punched_at' => CarbonImmutable::parse('2026-09-18 08:20', 'Asia/Manila')->toIso8601String(),
        'sent_at' => now()->toIso8601String(),
    ])->assertOk();

    $punch = AttendancePunch::sole();
    $record = AttendanceRecord::sole();

    expect($punch->punched_at->setTimezone('Asia/Manila')->format('H:i'))->toBe('08:20')
        ->and($punch->device_punched_at)->not->toBeNull()
        ->and($punch->received_at->setTimezone('Asia/Manila')->format('H:i'))->toBe('11:00')
        ->and($record->late_minutes)->toBe(20)
        ->and($record->flags)->not->toContain('clock_skew');
});

test('sending the same queued punch again records it once', function () {
    $user = actingAsUserWith(['attendance.clock']);
    captureWorker([], $user);
    Sanctum::actingAs($user);

    $payload = ['type' => 'clock_in', 'client_id' => 'phone-7', 'punched_at' => now()->subMinutes(5)->toIso8601String(), 'sent_at' => now()->toIso8601String()];

    $this->postJson('/api/attendance/punch', $payload)->assertOk()->assertJsonPath('duplicate', false);
    $this->postJson('/api/attendance/punch', $payload)->assertOk()->assertJsonPath('duplicate', true);

    expect(AttendancePunch::count())->toBe(1);
});

test('a queued punch older than the policy’s window is refused, to be corrected instead', function () {
    $user = actingAsUserWith(['attendance.clock']);
    captureWorker(['offline_window_hours' => 24], $user);
    Sanctum::actingAs($user);

    $this->postJson('/api/attendance/punch', [
        'type' => 'clock_in',
        'client_id' => 'phone-2',
        'punched_at' => now()->subHours(30)->toIso8601String(),
        'sent_at' => now()->toIso8601String(),
    ])->assertStatus(422)->assertJsonPath('message', fn (string $message) => str_contains($message, 'more than 24 hours ago'));

    expect(AttendancePunch::count())->toBe(0);
});

test('a phone whose clock is off has its punch accepted and flagged', function () {
    $user = actingAsUserWith(['attendance.clock']);
    captureWorker(['max_clock_skew_minutes' => 10], $user);
    Sanctum::actingAs($user);

    $this->postJson('/api/attendance/punch', [
        'type' => 'clock_in',
        'client_id' => 'phone-3',
        'punched_at' => now()->subMinutes(3)->toIso8601String(),
        // The phone thinks it is 25 minutes earlier than it is.
        'sent_at' => now()->subMinutes(25)->toIso8601String(),
    ])->assertOk();

    $record = AttendanceRecord::sole();

    expect(AttendancePunch::sole()->clock_skew_seconds)->toBe(-1500)
        ->and($record->flags)->toContain('clock_skew')
        ->and($record->approval_status)->toBe('pending');
});

test('a queued punch that would put a later one out of order is refused', function () {
    $user = actingAsUserWith(['attendance.clock']);
    $employee = captureWorker([], $user);
    Sanctum::actingAs($user);

    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00', 'Asia/Manila'));
    app(AttendanceClock::class)->punch($employee, 'clock_in', ['source' => 'mobile', 'punched_at' => CarbonImmutable::parse('2026-09-18 08:00', 'Asia/Manila')]);
    app(AttendanceClock::class)->punch($employee, 'break_start', ['source' => 'mobile', 'punched_at' => CarbonImmutable::parse('2026-09-18 11:00', 'Asia/Manila')]);

    // A clock-out at 10:00 would leave the 11:00 break outside the shift.
    $this->postJson('/api/attendance/punch', [
        'type' => 'clock_out', 'client_id' => 'phone-4',
        'punched_at' => CarbonImmutable::parse('2026-09-18 10:00', 'Asia/Manila')->toIso8601String(),
        'sent_at' => now()->toIso8601String(),
    ])->assertStatus(422)->assertJsonPath('message', fn (string $message) => str_contains($message, 'already recorded at 11:00 AM'));
});

// ── Where a site sits in the chains ──────────────────────────────────────────

test('a primary work location gives its people a schedule and a policy', function () {
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();

    $site = WorkSchedule::create(['name' => 'Site Hours', 'type' => 'fixed', 'grace_minutes' => 0, 'cycle_length_days' => 7]);
    app(SchedulePatternWriter::class)->write($site, array_map(fn (int $i): array => [
        'is_rest_day' => $i >= 6,
        'segments' => [['start' => '06:00', 'end' => '15:00']],
        'required_minutes' => 480,
    ], range(0, 6)));

    $policy = AttendancePolicy::create(['name' => 'Site Rules', 'settings' => AttendancePolicySettings::fallback()->toArray(), 'settings_version' => 1]);
    $location = office(['default_work_schedule_id' => $site->id, 'attendance_policy_id' => $policy->id]);

    $based = Employee::factory()->create(['work_schedule_id' => null]);
    $location->employees()->attach($based->id, ['is_primary' => true]);
    $elsewhere = Employee::factory()->create(['work_schedule_id' => null]);

    $shift = (new ShiftResolver)->for($based->refresh(), '2026-09-18');
    $resolved = (new PolicyResolver)->for($based, $shift);

    expect($shift->source)->toBe('location')
        ->and($shift->startTime())->toBe('06:00')
        ->and($resolved->source)->toBe('location')
        ->and($resolved->name)->toBe('Site Rules')
        ->and((new ShiftResolver)->for($elsewhere, '2026-09-18')->source)->toBe('fallback');
});

test('somebody based at two sites with neither primary defaults from neither', function () {
    $site = WorkSchedule::create(['name' => 'Site Hours', 'type' => 'fixed', 'grace_minutes' => 0, 'cycle_length_days' => 7]);
    $employee = Employee::factory()->create(['work_schedule_id' => null]);

    office(['default_work_schedule_id' => $site->id])->employees()->attach($employee->id, ['is_primary' => false]);
    office(['name' => 'Annex', 'default_work_schedule_id' => $site->id])->employees()->attach($employee->id, ['is_primary' => false]);

    expect((new ShiftResolver)->for($employee, '2026-09-18')->source)->toBe('fallback');
});

test('a person’s punch that breaks the order is still refused, whatever the source', function () {
    $employee = captureWorker();
    $clock = app(AttendanceClock::class);

    $clock->punch($employee, 'clock_in', ['source' => 'kiosk']);

    expect(fn () => $clock->punch($employee, 'clock_in', ['source' => 'kiosk']))
        ->toThrow(AttendancePunchException::class, "You're already clocked in.");
});
