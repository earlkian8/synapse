<?php

use App\Models\ActivityLog;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Support\Attendance\DeviceCsvImport;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| ADR 0040 — devices: a biometric scanner's punches are recorded as it sent
| them and flagged when they don't add up, never refused; a device is a key,
| shown once; a kiosk is a person punching at a shared tablet.
|
| "Today" is Friday 2026-09-18, 18:00 in Manila.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 18:00', 'Asia/Manila'));
    // The new screens are not in a built manifest; their props are the point.
    $this->withoutVite();
    testOrganization();
});

/** A registered device and its key. */
function registerDevice(string $type = 'biometric', array $attributes = []): array
{
    testOrganization();

    $device = new AttendanceDevice(['name' => ucfirst($type).' at the door', 'type' => $type, 'is_active' => true, ...$attributes]);
    $key = $device->issueKey();
    $device->save();

    return [$device, $key];
}

/** A day-shift worker with a known employee number. */
function scannedWorker(string $number = 'EMP-00042', array $attributes = []): Employee
{
    $employee = requestWorker();
    $employee->forceFill(['employee_no' => $number, ...$attributes])->save();

    return $employee;
}

function sendPunches(string $key, array $punches, array $extra = [])
{
    return test()->postJson('/api/devices/punches', ['punches' => $punches, ...$extra], ['Authorization' => "Bearer {$key}"]);
}

// ── Keys ─────────────────────────────────────────────────────────────────────

test('only a device’s own active key is accepted, and it binds that device’s company', function () {
    [$device, $key] = registerDevice();
    scannedWorker();

    sendPunches('sdk_nope', [])->assertUnauthorized();
    sendPunches('', [])->assertUnauthorized();

    $this->getJson('/api/devices/me', ['X-Device-Key' => $key])
        ->assertOk()
        ->assertJsonPath('data.name', $device->name)
        ->assertJsonPath('data.timezone', 'Asia/Manila');

    expect($device->refresh()->last_seen_at)->not->toBeNull()
        ->and($device->api_key_hash)->toBe(hash('sha256', $key))
        ->and($device->api_key_hint)->toBe(substr($key, -4));

    $device->update(['is_active' => false]);
    $this->getJson('/api/devices/me', ['X-Device-Key' => $key])->assertUnauthorized();
});

test('a scanner’s key cannot drive the kiosk', function () {
    [, $key] = registerDevice('biometric');
    scannedWorker();

    $this->postJson('/api/devices/kiosk/lookup', ['employee_ref' => 'EMP-00042'], ['X-Device-Key' => $key])->assertForbidden();
});

// ── Ingestion ────────────────────────────────────────────────────────────────

test('a device’s punches are recorded, and a resend is recognised rather than doubled', function () {
    [$device, $key] = registerDevice('biometric', ['work_location_id' => WorkLocation::create(['name' => 'Plant', 'latitude' => 14.5, 'longitude' => 121.0, 'radius_meters' => 100])->id]);
    $employee = scannedWorker();

    $batch = [
        ['external_id' => 'A1', 'employee_ref' => 'EMP-00042', 'punched_at' => '2026-09-18 07:58:10', 'type' => 'clock_in'],
        ['external_id' => 'A2', 'employee_ref' => 'emp-00042', 'punched_at' => '2026-09-18 17:04:00', 'type' => 'clock_out'],
    ];

    sendPunches($key, $batch)->assertOk()->assertJsonPath('accepted', 2)->assertJsonPath('duplicates', 0);
    sendPunches($key, $batch)->assertOk()->assertJsonPath('accepted', 0)->assertJsonPath('duplicates', 2)
        ->assertJsonPath('results.0.status', 'duplicate');

    $record = AttendanceRecord::sole();
    $punches = $record->punches()->get();

    expect($punches)->toHaveCount(2)
        ->and($punches->pluck('source')->unique()->all())->toBe(['biometric'])
        ->and($punches->first()->attendance_device_id)->toBe($device->id)
        ->and($punches->first()->location->name)->toBe('Plant')
        ->and($punches->first()->within_geofence)->toBeNull()
        // No offset: read on the company's clock.
        ->and($punches->first()->punched_at->setTimezone('Asia/Manila')->format('H:i'))->toBe('07:58')
        ->and($record->status)->toBe('present')
        ->and($record->employee_id)->toBe($employee->id)
        ->and(ActivityLog::where('description', 'like', "{$device->name} sent punches%")->count())->toBe(2);
});

test('an untyped punch is read from the day: in, then out', function () {
    [, $key] = registerDevice();
    scannedWorker();

    sendPunches($key, [
        ['external_id' => 'B1', 'employee_ref' => 'EMP-00042', 'punched_at' => '2026-09-18T07:59:00+08:00'],
        ['external_id' => 'B2', 'employee_ref' => 'EMP-00042', 'punched_at' => '2026-09-18T17:02:00+08:00'],
    ])->assertOk()->assertJsonPath('accepted', 2);

    expect(AttendancePunch::orderBy('punched_at')->pluck('type')->all())->toBe(['clock_in', 'clock_out'])
        ->and(AttendanceRecord::sole()->status)->toBe('present');
});

test('punches out of order are stored and flagged for sign-off, never refused', function () {
    [, $key] = registerDevice();
    scannedWorker();

    sendPunches($key, [
        ['external_id' => 'C1', 'employee_ref' => 'EMP-00042', 'punched_at' => '2026-09-18 08:00', 'type' => 'clock_in'],
        // Scanned twice at the door.
        ['external_id' => 'C2', 'employee_ref' => 'EMP-00042', 'punched_at' => '2026-09-18 08:01', 'type' => 'clock_in'],
        ['external_id' => 'C3', 'employee_ref' => 'EMP-00042', 'punched_at' => '2026-09-18 17:00', 'type' => 'clock_out'],
    ])->assertOk()->assertJsonPath('accepted', 3)->assertJsonPath('rejected', 0);

    $record = AttendanceRecord::sole();

    expect(AttendancePunch::count())->toBe(3)
        ->and($record->flags)->toContain('device_sequence_anomaly')
        ->and($record->approval_status)->toBe('pending');
});

test('each row is answered: an unknown person or an unreadable row is reported, the rest recorded', function () {
    [, $key] = registerDevice();
    scannedWorker('EMP-00042', ['device_enrollment_id' => 'FP-7']);

    sendPunches($key, [
        ['external_id' => 'D1', 'employee_ref' => 'FP-7', 'punched_at' => '2026-09-18 08:00', 'type' => 'clock_in'],
        ['external_id' => 'D2', 'employee_ref' => 'EMP-99999', 'punched_at' => '2026-09-18 08:00'],
        ['external_id' => 'D3', 'employee_ref' => 'EMP-00042', 'punched_at' => 'yesterday-ish'],
        ['employee_ref' => 'EMP-00042', 'punched_at' => '2026-09-18 09:00'],
        ['external_id' => 'D5', 'employee_ref' => 'EMP-00042', 'punched_at' => '2026-09-18 12:00', 'type' => 'lunch'],
    ])
        ->assertOk()
        ->assertJsonPath('accepted', 1)
        ->assertJsonPath('rejected', 4)
        ->assertJsonPath('results.1.status', 'unknown_employee')
        ->assertJsonPath('results.1.message', 'No employee has the number or enrolment id "EMP-99999".')
        ->assertJsonPath('results.2.status', 'invalid')
        ->assertJsonPath('results.3.status', 'invalid')
        ->assertJsonPath('results.4.status', 'invalid');

    expect(AttendancePunch::sole()->external_id)->toBe('D1');
});

test('a device’s punch in a source the policy does not allow is recorded and flagged', function () {
    [, $key] = registerDevice();
    $employee = requestWorker(null, ['capture' => ['allowed_sources' => ['mobile'], 'selfie_required' => false, 'geofence' => 'off', 'web_ip_allowlist' => []]]);
    $employee->forceFill(['employee_no' => 'EMP-00042'])->save();

    sendPunches($key, [['external_id' => 'E1', 'employee_ref' => 'EMP-00042', 'punched_at' => '2026-09-18 08:00', 'type' => 'clock_in']])
        ->assertJsonPath('accepted', 1);

    expect(AttendanceRecord::sole()->flags)->toContain('source_not_allowed');
});

test('a batch that is not a batch is refused as a whole', function () {
    [, $key] = registerDevice();

    $this->postJson('/api/devices/punches', ['punches' => 'nope'], ['Authorization' => "Bearer {$key}"])->assertStatus(422);
    $this->postJson('/api/devices/punches', ['punches' => array_fill(0, 501, [])], ['Authorization' => "Bearer {$key}"])->assertStatus(422);
});

// ── The kiosk ────────────────────────────────────────────────────────────────

test('the kiosk greets somebody by the digits of their number and records their punch', function () {
    Storage::fake('public');
    $site = WorkLocation::create(['name' => 'Lobby', 'latitude' => 14.5, 'longitude' => 121.0, 'radius_meters' => 100]);
    [$device, $key] = registerDevice('kiosk', ['work_location_id' => $site->id]);
    $employee = scannedWorker('EMP-00042');

    $this->postJson('/api/devices/kiosk/lookup', ['employee_ref' => '42'], ['X-Device-Key' => $key])
        ->assertOk()
        ->assertJsonPath('data.full_name', $employee->full_name)
        ->assertJsonPath('data.next_expected', 'clock_in');

    $this->postJson('/api/devices/kiosk/lookup', ['employee_ref' => '43'], ['X-Device-Key' => $key])->assertNotFound();

    $this->post('/api/devices/kiosk/punch', [
        'employee_ref' => '42', 'type' => 'clock_in', 'photo' => UploadedFile::fake()->image('face.jpg'),
    ], ['X-Device-Key' => $key, 'Accept' => 'application/json'])->assertOk()->assertJsonPath('data.type', 'clock_in');

    $punch = AttendancePunch::sole();

    expect($punch->source)->toBe('kiosk')
        ->and($punch->attendance_device_id)->toBe($device->id)
        ->and($punch->work_location_id)->toBe($site->id)
        ->and($punch->photo)->not->toBeNull();

    // A person at the kiosk is held to the day's order.
    $this->postJson('/api/devices/kiosk/punch', ['employee_ref' => '42', 'type' => 'clock_in'], ['X-Device-Key' => $key])
        ->assertStatus(422)
        ->assertJsonPath('message', "You're already clocked in.");
});

test('somebody who has left cannot punch at the kiosk', function () {
    [, $key] = registerDevice('kiosk');
    scannedWorker('EMP-00042', ['employment_status' => 'resigned']);

    $this->postJson('/api/devices/kiosk/lookup', ['employee_ref' => 'EMP-00042'], ['X-Device-Key' => $key])->assertNotFound();
});

test('the kiosk page is public and needs no signed-in user', function () {
    $this->get('/kiosk')->assertOk()->assertInertia(fn (Assert $page) => $page->component('kiosk'));
});

// ── CSV import ───────────────────────────────────────────────────────────────

test('a device’s CSV export is imported through the same ingestion, and importing it again adds nothing', function () {
    actingAsUserWith(['setup.devices.manage']);
    [$device] = registerDevice();
    scannedWorker();

    $csv = "\u{FEFF}No.;Date;Time;State\nEMP-00042;2026-09-18;07:55;C/In\nEMP-00042;2026-09-18;17:10;C/Out\nEMP-77777;2026-09-18;08:00;C/In\n";
    $file = fn () => UploadedFile::fake()->createWithContent('export.csv', $csv);
    $mapping = ['employee_ref' => 'No.', 'date' => 'Date', 'time' => 'Time', 'type' => 'State'];

    $this->post(route('setup.devices.import', $device), ['file' => $file(), 'mapping' => $mapping])->assertSessionHasNoErrors();
    assertToast('warning', '2 punches recorded, 1 not recorded');

    $this->post(route('setup.devices.import', $device), ['file' => $file(), 'mapping' => $mapping]);
    assertToast('warning', '0 punches recorded, 2 already imported, 1 not recorded');

    expect(AttendancePunch::orderBy('punched_at')->pluck('type')->all())->toBe(['clock_in', 'clock_out'])
        ->and($device->refresh()->csv_mapping)->toMatchArray($mapping)
        ->and(session('inertia.flash_data.device_import.issues.0'))->toBe(['line' => 4, 'message' => 'No employee has the number or enrolment id "EMP-77777".']);
});

test('an import names the time column, or a date and a time', function () {
    actingAsUserWith(['setup.devices.manage']);
    [$device] = registerDevice();

    $this->post(route('setup.devices.import', $device), [
        'file' => UploadedFile::fake()->createWithContent('export.csv', "id,who\n1,EMP-00042\n"),
        'mapping' => ['employee_ref' => 'who'],
    ])->assertSessionHasErrors('mapping.punched_at');
});

test('vendor words and state codes are read as punches', function (string $word, ?string $type) {
    expect(DeviceCsvImport::type($word))->toBe($type);
})->with([
    ['Check In', 'clock_in'],
    ['C/Out', 'clock_out'],
    ['0', 'clock_in'],
    ['1', 'clock_out'],
    ['2', 'break_start'],
    ['3', 'break_end'],
    ['Overtime Out', 'clock_out'],
    ['whatever', null],
    ['', null],
]);

// ── Setup screens ────────────────────────────────────────────────────────────

test('registering a device shows its key once and keeps only a hash', function () {
    actingAsUserWith(['setup.devices.manage']);

    $this->post(route('setup.devices.store'), ['name' => 'Front door', 'type' => 'biometric'])->assertSessionHasNoErrors();

    $device = AttendanceDevice::sole();
    $flashed = session('inertia.flash_data.device_key');

    expect($flashed['key'])->toStartWith('sdk_')
        ->and($device->api_key_hash)->toBe(hash('sha256', $flashed['key']))
        ->and(json_encode($device->toArray()))->not->toContain($flashed['key'])
        ->and(ActivityLog::where('description', 'Registered biometric "Front door"')->exists())->toBeTrue();

    $this->get(route('setup.devices.index'))->assertInertia(fn (Assert $page) => $page
        ->component('setup/devices')
        ->where('devices.0.key_hint', substr($flashed['key'], -4))
        ->missing('devices.0.api_key_hash'));
});

test('replacing a key stops the old one at once', function () {
    actingAsUserWith(['setup.devices.manage']);
    [$device, $old] = registerDevice();

    $this->post(route('setup.devices.rotate-key', $device));
    $new = session('inertia.flash_data.device_key.key');

    $this->getJson('/api/devices/me', ['X-Device-Key' => $old])->assertUnauthorized();
    $this->getJson('/api/devices/me', ['X-Device-Key' => $new])->assertOk();
});

test('a device’s kind cannot change once registered', function () {
    actingAsUserWith(['setup.devices.manage']);
    [$device] = registerDevice('biometric');

    $this->post(route('setup.devices.update', $device), ['name' => 'Renamed', 'type' => 'kiosk'])->assertSessionHasErrors('type');
});

test('the device screens need setup.devices.manage', function () {
    actingAsUserWith(['setup.locations.view']);

    $this->get(route('setup.devices.index'))->assertForbidden();
    $this->post(route('setup.devices.store'), ['name' => 'X', 'type' => 'kiosk'])->assertForbidden();
});

test('locations are drawn, staffed with a primary site, archived and kept while punches name them', function () {
    actingAsUserWith(['setup.locations.view', 'setup.locations.manage']);
    $ana = Employee::factory()->create();
    $ben = Employee::factory()->create();

    $this->post(route('setup.locations.store'), [
        'name' => 'Main Office', 'latitude' => 14.5547, 'longitude' => 121.0244, 'radius_meters' => 120,
    ])->assertSessionHasNoErrors();

    $location = WorkLocation::sole();
    $annex = WorkLocation::create(['name' => 'Annex', 'latitude' => 14.56, 'longitude' => 121.03, 'radius_meters' => 80]);
    $annex->employees()->attach($ana->id, ['is_primary' => true]);

    $this->put(route('setup.locations.people', $location), ['employee_ids' => [$ana->id, $ben->id], 'primary_ids' => [$ana->id]])
        ->assertSessionHasNoErrors();

    expect($location->employees()->wherePivot('is_primary', true)->pluck('employees.id')->all())->toBe([$ana->id])
        // Primary here means primary nowhere else.
        ->and($annex->employees()->wherePivot('is_primary', true)->count())->toBe(0);

    $this->put(route('setup.locations.people', $location), ['employee_ids' => [$ben->id], 'primary_ids' => [$ana->id]])
        ->assertSessionHasErrors('primary_ids');

    $this->post(route('setup.locations.store'), ['name' => 'Tiny', 'latitude' => 1, 'longitude' => 1, 'radius_meters' => 5])
        ->assertSessionHasErrors('radius_meters');

    $record = workedDay(requestWorker(), '2026-09-17', ['time_in' => '08:00', 'time_out' => '17:00']);
    $record->punches()->first()->forceFill(['work_location_id' => $location->id])->save();

    $this->delete(route('setup.locations.destroy', $location));
    $this->delete(route('setup.locations.force-delete', $location->hashid));
    assertToast('warning', 'Punches were made at this location');

    expect(WorkLocation::withTrashed()->find($location->id))->not->toBeNull();

    $this->get(route('setup.locations.index'))->assertInertia(fn (Assert $page) => $page
        ->component('setup/locations')
        ->has('locations', 1)
        ->has('archivedLocations', 1));
});

test('the location screens are gated', function () {
    actingAsUserWith(['setup.locations.view']);

    $this->get(route('setup.locations.index'))->assertOk();
    $this->post(route('setup.locations.store'), ['name' => 'X', 'latitude' => 1, 'longitude' => 1, 'radius_meters' => 100])->assertForbidden();
});
