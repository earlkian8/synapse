<?php

use App\Models\ActivityLog;
use App\Models\AttendancePunch;
use App\Models\AttendanceQrScan;
use App\Models\AttendanceQrStation;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\QrCodes;
use App\Support\Attendance\QrScans;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| ADR 0103 — QR stations: a screen at a site shows a code that changes every few
| seconds; a phone scanning it from inside the site, with a real and precise
| location, that has not clocked in somebody else, buys a ticket the punch then
| spends. Every scan is written down, and the suspicious refusals logged.
|
| "Today" is Friday 2026-09-18, 08:05 in Manila. The office sits at 14.5547 N,
| 121.0244 E with a 100 m fence.
*/

const QR_DEVICE = 'device-0123456789abcdef';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:05:03', 'Asia/Manila'));
    testOrganization();
});

function qrOffice(array $attributes = []): WorkLocation
{
    return WorkLocation::create([
        'name' => 'Makati Office',
        'latitude' => 14.5547,
        'longitude' => 121.0244,
        'radius_meters' => 100,
        ...$attributes,
    ]);
}

/** A point this many metres due north of the office, as a phone sends it. */
function qrAt(float $meters, float $accuracy = 8): array
{
    return ['latitude' => 14.5547 + $meters / 111_195, 'longitude' => 121.0244, 'accuracy' => $accuracy];
}

function qrStation(array $attributes = []): AttendanceQrStation
{
    return AttendanceQrStation::create([
        'name' => 'Front desk',
        'work_location_id' => ($attributes['work_location_id'] ?? null) ?? qrOffice()->id,
        'rotate_seconds' => 15,
        ...$attributes,
    ]);
}

/** The code the station's screen is showing now: the first of the batch it was given. */
function qrShown(AttendanceQrStation $station): string
{
    return QrCodes::issue($station)['codes'][0]['code'];
}

/** A worker signed in on the app, on the day shift, judged by these capture rules. */
function qrWorker(array $capture = []): array
{
    $user = User::factory()->create();
    $user->roles()->attach(makeRole('qr-staff', ['attendance.clock']));
    $employee = dayShiftWorker($user, $capture === [] ? [] : ['capture' => [...AttendancePolicySettings::fallback()->toArray()['capture'], ...$capture]]);
    Sanctum::actingAs($user);

    return [$user, $employee];
}

function qrScan(string $code, array $position, ?string $device = QR_DEVICE, bool $mocked = false)
{
    return test()->postJson('/api/attendance/qr/scan', [
        'code' => $code,
        ...$position,
        'mocked' => $mocked,
        'device_id' => $device,
    ]);
}

// ── The happy path ───────────────────────────────────────────────────────────

test('a scan of the code on the screen from inside the site buys a ticket, and the punch spends it', function () {
    [, $employee] = qrWorker();
    $station = qrStation();

    $scan = qrScan(qrShown($station), qrAt(20))
        ->assertOk()
        ->assertJsonPath('station.name', 'Front desk')
        ->assertJsonPath('day.next_expected', 'clock_in')
        ->assertJsonPath('day.qr_allowed', true);

    expect($scan->headers->get('Cache-Control'))->toContain('no-store');

    $this->postJson('/api/attendance/qr/punch', ['ticket' => $scan->json('ticket'), 'type' => 'clock_in'])
        ->assertOk()
        ->assertJsonPath('next_expected', 'clock_out');

    $punch = AttendancePunch::sole();
    $logged = AttendanceQrScan::sole();

    expect($punch->source)->toBe('qr')
        ->and($punch->employee_id)->toBe($employee->id)
        ->and($punch->attendance_qr_station_id)->toBe($station->id)
        ->and($punch->device_hash)->toBe(QrScans::deviceHash(QR_DEVICE))
        ->and($punch->within_geofence)->toBeTrue()
        ->and($logged->outcome)->toBe('accepted')
        ->and($logged->attendance_punch_id)->toBe($punch->id)
        ->and($logged->punch_type)->toBe('clock_in');

    // Spent: the same ticket does not punch again.
    $this->postJson('/api/attendance/qr/punch', ['ticket' => $scan->json('ticket'), 'type' => 'break_start'])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'expired');
});

test('the code also opens as the link the screen shows it as', function () {
    qrWorker();
    $station = qrStation();

    qrScan(QrCodes::SCHEME.qrShown($station), qrAt(0))->assertOk();
});

test('a ticket is good only for the person who scanned', function () {
    qrWorker();
    $station = qrStation();
    $ticket = qrScan(qrShown($station), qrAt(10))->json('ticket');

    qrWorker();

    $this->postJson('/api/attendance/qr/punch', ['ticket' => $ticket, 'type' => 'clock_in'])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'expired');

    expect(AttendancePunch::count())->toBe(0);
});

test('a ticket runs out after two minutes', function () {
    qrWorker();
    $ticket = qrScan(qrShown(qrStation()), qrAt(10))->json('ticket');

    $this->travel(QrScans::TICKET_SECONDS + 1)->seconds();

    $this->postJson('/api/attendance/qr/punch', ['ticket' => $ticket, 'type' => 'clock_in'])
        ->assertJsonPath('reason', 'expired');
});

// ── The code ─────────────────────────────────────────────────────────────────

test('a code is good for its own window and the next, and no longer', function () {
    qrWorker();
    $station = qrStation();
    $code = qrShown($station);

    $this->travel(15)->seconds();
    QrCodes::issue($station);
    qrScan($code, qrAt(5))->assertOk();

    $this->travel(15)->seconds();
    QrCodes::issue($station);
    qrScan($code, qrAt(5))->assertUnprocessable()->assertJsonPath('reason', 'expired');
});

test('a correctly signed code the screen never showed is refused', function () {
    qrWorker();
    $station = qrStation();
    $unshown = QrCodes::code($station, intdiv(now()->getTimestamp(), 15));

    qrScan($unshown, qrAt(5))->assertUnprocessable()->assertJsonPath('reason', 'not_showing');

    expect(AttendanceQrScan::sole()->reason)->toBe('not_showing')
        ->and(ActivityLog::query()->where('description', 'like', 'Refused a QR scan%')->count())->toBe(1);
});

test('a forged signature is refused', function () {
    qrWorker();
    $station = qrStation();
    $code = qrShown($station);
    $forged = substr($code, 0, -4).'AAAA';

    qrScan($forged, qrAt(5))->assertUnprocessable()->assertJsonPath('reason', 'invalid');
});

test('resetting the codes ends every code shown before', function () {
    qrWorker();
    $station = qrStation();
    $code = qrShown($station);

    $station->secret = AttendanceQrStation::newSecret();
    $station->save();

    qrScan($code, qrAt(5))->assertJsonPath('reason', 'invalid');
});

test('another company’s station is nobody’s here, and leaves nothing behind', function () {
    $tenancy = app(Tenancy::class);
    $theirs = Organization::factory()->create();
    $mine = $tenancy->organization();

    $tenancy->set($theirs);
    $station = qrStation();
    $code = qrShown($station);
    $tenancy->set($mine);

    qrWorker();

    qrScan($code, qrAt(5))->assertUnprocessable()->assertJsonPath('reason', 'invalid');

    expect(AttendanceQrScan::withoutGlobalScopes()->count())->toBe(0);
});

test('text that is not a code of ours is refused', function () {
    qrWorker();

    qrScan('https://example.com/free-attendance', qrAt(5))->assertUnprocessable()->assertJsonPath('reason', 'invalid');
});

// ── The phone ────────────────────────────────────────────────────────────────

test('a phone away from the station is refused, and how far is written down', function () {
    qrWorker();
    $station = qrStation();

    qrScan(qrShown($station), qrAt(2500))
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'away')
        ->assertJsonPath('message', fn (string $message) => str_contains($message, '2.5 km from Makati Office'));

    $logged = AttendanceQrScan::sole();

    expect($logged->outcome)->toBe('refused')
        ->and($logged->distance_meters)->toBe(2500)
        ->and(AttendancePunch::count())->toBe(0);
});

test('a phone at another of the company’s sites is still away from this station', function () {
    qrWorker();
    $station = qrStation();
    qrOffice(['name' => 'Warehouse', 'latitude' => 14.6000]);

    qrScan(qrShown($station), ['latitude' => 14.6000, 'longitude' => 121.0244, 'accuracy' => 5])
        ->assertJsonPath('reason', 'away');
});

test('a simulated location is refused and logged', function () {
    qrWorker();
    $station = qrStation();

    qrScan(qrShown($station), qrAt(0), mocked: true)->assertUnprocessable()->assertJsonPath('reason', 'mocked');

    expect(AttendanceQrScan::sole()->mocked)->toBeTrue()
        ->and(ActivityLog::query()->where('description', 'like', '%simulated location%')->exists())->toBeTrue();
});

test('a scan needs a location, a precise one', function () {
    qrWorker();
    $station = qrStation();
    $code = qrShown($station);

    qrScan($code, [])->assertJsonPath('reason', 'no_location');
    qrScan($code, qrAt(0, accuracy: 450))->assertJsonPath('reason', 'imprecise');
});

test('a scan needs the app to say which phone it is', function () {
    qrWorker();

    qrScan(qrShown(qrStation()), qrAt(0), device: null)->assertJsonPath('reason', 'no_device');
});

test('a phone that clocked somebody in can’t clock in anybody else that day', function () {
    qrWorker();
    $station = qrStation();
    $ticket = qrScan(qrShown($station), qrAt(0))->json('ticket');
    $this->postJson('/api/attendance/qr/punch', ['ticket' => $ticket, 'type' => 'clock_in'])->assertOk();

    // A colleague signs in on the same phone.
    qrWorker();

    qrScan(qrShown($station), qrAt(0))->assertUnprocessable()->assertJsonPath('reason', 'shared_device');

    // Twelve hours on, the phone is free again.
    $this->travel(QrScans::DEVICE_HOURS * 60 + 1)->minutes();
    qrScan(qrShown($station), qrAt(0))->assertOk();
});

test('a phone used for a plain app punch counts towards one phone one person', function () {
    [$first] = qrWorker();
    qrOffice();

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in', ...qrAt(0), 'device_id' => QR_DEVICE])->assertOk();

    qrWorker();
    $station = qrStation(['work_location_id' => WorkLocation::sole()->id]);

    qrScan(qrShown($station), qrAt(0))->assertJsonPath('reason', 'shared_device');
});

// ── The station and the policy ───────────────────────────────────────────────

test('a switched-off station clocks nobody in', function () {
    qrWorker();
    $station = qrStation();
    $code = qrShown($station);
    $station->update(['is_active' => false]);

    qrScan($code, qrAt(0))->assertJsonPath('reason', 'inactive');
});

test('a station whose site was archived clocks nobody in', function () {
    qrWorker();
    $station = qrStation();
    $code = qrShown($station);
    $station->location->delete();

    qrScan($code, qrAt(0))->assertJsonPath('reason', 'no_site');
});

test('a policy that doesn’t take QR punches says so at the scan', function () {
    qrWorker(['allowed_sources' => ['mobile']]);

    qrScan(qrShown(qrStation()), qrAt(0))
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'punch_refused');

    expect(AttendanceQrScan::sole()->outcome)->toBe('refused');
});

test('a QR-only policy turns the plain clock button away, and takes the scan', function () {
    qrWorker(['allowed_sources' => ['qr']]);
    qrOffice();

    $this->getJson('/api/attendance/today')
        ->assertJsonPath('capture.mobile', false)
        ->assertJsonPath('capture.qr', true);

    $this->postJson('/api/attendance/punch', ['type' => 'clock_in', ...qrAt(0)])->assertUnprocessable();

    $station = qrStation(['work_location_id' => WorkLocation::sole()->id]);
    $ticket = qrScan(qrShown($station), qrAt(0))->json('ticket');

    $this->postJson('/api/attendance/qr/punch', ['ticket' => $ticket, 'type' => 'clock_in'])->assertOk();
});

test('a policy that wants a photo refuses a QR punch without one, and keeps the ticket', function () {
    Storage::fake('uploads');
    qrWorker(['selfie_required' => true]);
    $ticket = qrScan(qrShown(qrStation()), qrAt(0))
        ->assertJsonPath('day.selfie_required', true)
        ->json('ticket');

    $this->postJson('/api/attendance/qr/punch', ['ticket' => $ticket, 'type' => 'clock_in'])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'punch_refused');

    $this->post('/api/attendance/qr/punch', [
        'ticket' => $ticket,
        'type' => 'clock_in',
        'photo' => UploadedFile::fake()->image('selfie.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();

    expect(AttendancePunch::sole()->photo)->not->toBeNull();
});

// ── HR's side ────────────────────────────────────────────────────────────────

test('HR adds, edits, switches off and removes a station, each logged', function () {
    actingAsUserWith(['attendance.qr.manage']);
    $site = qrOffice();

    $this->post('/attendance/qr', ['name' => 'Gate', 'work_location_id' => $site->id, 'rotate_seconds' => 30])->assertRedirect();
    $station = AttendanceQrStation::sole();

    expect($station->rotate_seconds)->toBe(30)
        ->and($station->secret)->toHaveLength(64);

    $this->post("/attendance/qr/{$station->hashid}", ['name' => 'Main gate', 'work_location_id' => $site->id, 'rotate_seconds' => 15])->assertRedirect();
    $this->patch("/attendance/qr/{$station->hashid}/toggle")->assertRedirect();
    expect($station->refresh()->is_active)->toBeFalse();

    $this->patch("/attendance/qr/{$station->hashid}/reset")->assertRedirect();
    $this->delete("/attendance/qr/{$station->hashid}")->assertRedirect();

    expect(AttendanceQrStation::count())->toBe(0)
        ->and(AttendanceQrStation::withTrashed()->count())->toBe(1)
        ->and(ActivityLog::query()->where('log_name', 'attendance')->where('description', 'like', '%QR station%')->count())->toBe(5);
});

test('a station needs a site and a rotation it offers', function () {
    actingAsUserWith(['attendance.qr.manage']);

    $this->post('/attendance/qr', ['name' => 'Gate', 'rotate_seconds' => 7])
        ->assertSessionHasErrors(['work_location_id', 'rotate_seconds']);
});

test('the screen is sent a few minutes of codes as grids to draw, never kept, and the secret never', function () {
    $hr = actingAsUserWith(['attendance.qr.display']);
    $station = qrStation();

    $this->get("/attendance/qr/{$station->hashid}/display")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('attendance/qr-display')->where('station.name', 'Front desk'));

    $answer = $this->getJson("/attendance/qr/{$station->hashid}/code")
        ->assertOk()
        ->assertJsonPath('state', 'live')
        ->assertJsonPath('refresh_in', QrCodes::REFRESH_SECONDS);

    $codes = $answer->json('codes');

    // 08:05:03 at 15 s: the window now and every one that starts within 180 s.
    expect($codes)->toHaveCount(13)
        ->and($codes[0]['matrix']['size'])->toBeGreaterThanOrEqual(21)
        ->and(strlen(base64_decode($codes[0]['matrix']['bits'])))->toBe((int) ceil($codes[0]['matrix']['size'] ** 2 / 8))
        ->and($answer->headers->get('Cache-Control'))->toContain('no-store')
        ->and($answer->getContent())->not->toContain($station->secret)
        ->and($answer->getContent())->not->toContain('SQ1.')
        ->and($station->refresh()->isShowing())->toBeTrue()
        ->and($station->last_shown_by)->toBe($hr->id);
});

test('a code the screen was given ahead counts in its own window; one past the batch never does', function () {
    qrWorker();
    $station = qrStation();
    $batch = QrCodes::issue($station)['codes'];
    $last = end($batch);

    // Three minutes on, with no ask since, the last code given is live and good.
    $this->travelTo(CarbonImmutable::parse($last['starts_at'])->addSecond());
    qrScan($last['code'], qrAt(5))->assertOk();

    // The window after it was never given to a screen.
    $this->travel(15)->seconds();
    $beyond = QrCodes::code($station, $last['window'] + 1);
    qrScan($beyond, qrAt(5))->assertUnprocessable()->assertJsonPath('reason', 'not_showing');
});

test('an app-only station shows the app’s link; one that takes any phone, a web address the app reads too', function () {
    qrWorker();
    $station = qrStation();

    expect(QrCodes::issue($station)['codes'][0]['payload'])->toStartWith(QrCodes::SCHEME);

    $station->update(['scan_mode' => 'any']);
    $payload = QrCodes::issue($station->refresh())['codes'][0]['payload'];

    expect($payload)->toStartWith(rtrim(config('app.url'), '/').'/q/SQ1.');

    qrScan($payload, qrAt(5))->assertOk();
});

test('somebody who may only show a code sees the stations to show, and none of the scans', function () {
    $station = qrStation();
    AttendanceQrScan::create(['attendance_qr_station_id' => $station->id, 'outcome' => 'refused', 'reason' => 'away']);
    actingAsUserWith(['attendance.qr.display']);

    $this->get('/attendance')->assertRedirect('/attendance/qr');

    $this->get('/attendance/qr')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('attendance/qr')
            ->has('stations', 1)
            ->has('scans', 0)
            ->where('can.manage', false));

    $this->post('/attendance/qr', ['name' => 'Gate'])->assertForbidden();
});

test('HR reads every scan, refusals and why included', function () {
    $station = qrStation();
    $employee = Employee::factory()->create();
    AttendanceQrScan::create(['attendance_qr_station_id' => $station->id, 'employee_id' => $employee->id, 'outcome' => 'refused', 'reason' => 'mocked', 'mocked' => true]);
    actingAsUserWith(['attendance.qr.manage']);

    $this->get('/attendance/qr')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('attendance/qr')
            ->where('scans.0.reason', 'mocked')
            ->where('scans.0.suspicious', true)
            ->where('stations.0.refused_today', 1)
            ->missing('scans.0.device_hash'));
});

test('neither screen is anybody else’s', function () {
    $station = qrStation();
    actingAsUserWith(['attendance.clock']);

    $this->get('/attendance/qr')->assertForbidden();
    $this->get("/attendance/qr/{$station->hashid}/display")->assertForbidden();
    $this->getJson("/attendance/qr/{$station->hashid}/code")->assertForbidden();
});
