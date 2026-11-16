<?php

use App\Http\Controllers\Attendance\QrWebScanController;
use App\Models\ActivityLog;
use App\Models\AttendancePunch;
use App\Models\AttendanceQrScan;
use App\Models\AttendanceQrStation;
use App\Models\Employee;
use App\Models\EmployeeKioskPin;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\KioskPins;
use App\Support\Attendance\QrCodes;
use App\Support\Attendance\QrScans;
use App\Support\Files\Uploads;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| ADR 0103 — a QR station that takes any phone: the camera opens the code's web
| address, the page claims the live code for that browser, and the person says
| who they are with their employee number and kiosk PIN, from inside the site.
| Then the same ticket and punch as the app's, written down as a browser scan.
|
| "Today" is Friday 2026-09-18, 08:05 in Manila. The office sits at 14.5547 N,
| 121.0244 E with a 100 m fence.
*/

const QB_PIN = '583920';

const QB_BROWSER = 'browser-0123456789abcdef0123456789abcdef';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:05:03', 'Asia/Manila'));
    testOrganization();
});

function qbStation(array $attributes = []): AttendanceQrStation
{
    $site = WorkLocation::firstOrCreate(['name' => 'Makati Office'], [
        'latitude' => 14.5547, 'longitude' => 121.0244, 'radius_meters' => 100,
    ]);

    return AttendanceQrStation::create([
        'name' => 'Front desk',
        'work_location_id' => $site->id,
        'rotate_seconds' => 15,
        'scan_mode' => 'any',
        ...$attributes,
    ]);
}

/** A worker on the day shift with a PIN — one of their own, unless `$chosen` is false. */
function qbWorker(string $number = 'EMP-00042', bool $chosen = true, array $policy = []): Employee
{
    $employee = dayShiftWorker(null, $policy);
    $employee->forceFill(['employee_no' => $number, 'middle_name' => null, 'suffix' => null])->save();

    KioskPins::issue($employee, User::factory()->create());

    if ($chosen) {
        KioskPins::change($employee->kioskPin()->firstOrFail(), QB_PIN);
    }

    return $employee->refresh();
}

/** A point this many metres due north of the office, as a browser sends it. */
function qbAt(float $meters, float $accuracy = 12): array
{
    return ['latitude' => 14.5547 + $meters / 111_195, 'longitude' => 121.0244, 'accuracy' => $accuracy];
}

/** A request from a phone's browser: nobody signed in, no tenant bound, its own cookie. */
function qbBrowser(string $browser = QB_BROWSER, string $ip = '203.0.113.7'): TestCase
{
    app('auth')->forgetGuards();
    app(Tenancy::class)->forget();

    return test()->withCredentials()
        ->withCookie(QrWebScanController::DEVICE_COOKIE, $browser)
        ->withServerVariables(['REMOTE_ADDR' => $ip]);
}

/** Open the code's address, as the camera does, and return the claim the page holds. */
function qbOpen(AttendanceQrStation $station, string $browser = QB_BROWSER, string $ip = '203.0.113.7'): string
{
    $code = QrCodes::issue($station)['codes'][0]['code'];
    $page = qbBrowser($browser, $ip)->get('/q/'.$code)->assertOk();

    return (string) $page->viewData('page')['props']['claim'];
}

function qbIdentify(string $claim, array $position, string $number = '42', string $pin = QB_PIN, string $browser = QB_BROWSER, string $ip = '203.0.113.7'): TestResponse
{
    return qbBrowser($browser, $ip)->postJson('/qr-scan/identify', [
        'claim' => $claim,
        'number' => $number,
        'pin' => $pin,
        ...$position,
    ]);
}

// ── The happy path ───────────────────────────────────────────────────────────

test('a phone’s camera opens the page, the number and PIN from inside the site buy a ticket, and the punch lands', function () {
    $employee = qbWorker();
    $station = qbStation();

    $claim = qbOpen($station);

    expect($claim)->toHaveLength(48);

    $identified = qbIdentify($claim, qbAt(10))
        ->assertOk()
        ->assertJsonPath('first_name', $employee->first_name)
        ->assertJsonPath('must_change_pin', false)
        ->assertJsonPath('day.next_expected', 'clock_in');

    qbBrowser()->postJson('/qr-scan/punch', ['ticket' => $identified->json('ticket'), 'type' => 'clock_in'])
        ->assertOk()
        ->assertJsonPath('type', 'clock_in')
        ->assertJsonPath('next_expected', 'clock_out');

    $punch = AttendancePunch::sole();
    $scan = AttendanceQrScan::sole();

    expect($punch->source)->toBe('qr')
        ->and($punch->employee_id)->toBe($employee->id)
        ->and($punch->attendance_qr_station_id)->toBe($station->id)
        ->and($punch->device_hash)->toBe(QrScans::deviceHash(QB_BROWSER))
        ->and($scan->client)->toBe('browser')
        ->and($scan->outcome)->toBe('accepted')
        ->and($scan->attendance_punch_id)->toBe($punch->id);
});

test('the page gives a browser without one an id of its own, in a cookie the page can’t read', function () {
    qbWorker();
    $station = qbStation();
    $code = QrCodes::issue($station)['codes'][0]['code'];

    app(Tenancy::class)->forget();
    $response = $this->get('/q/'.$code)->assertOk();
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === QrWebScanController::DEVICE_COOKIE);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

// ── The code ─────────────────────────────────────────────────────────────────

test('a station that takes the app only sends the browser to the app', function () {
    $station = qbStation(['scan_mode' => 'app']);
    $code = QrCodes::issue($station)['codes'][0]['code'];

    qbBrowser()->get('/q/'.$code)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('qr-scan')
            ->where('state', 'app_only')
            ->where('app_link', QrCodes::SCHEME.$code)
            ->missing('claim'));
});

test('an old link, a forged one, or text that isn’t ours opens no claim', function () {
    $station = qbStation();
    $old = QrCodes::issue($station)['codes'][0]['code'];

    $this->travel(31)->seconds();
    QrCodes::issue($station);

    qbBrowser()->get('/q/'.$old)->assertInertia(fn ($page) => $page->where('state', 'refused')->missing('claim'));
    qbBrowser()->get('/q/'.substr($old, 0, -4).'AAAA')->assertInertia(fn ($page) => $page->where('state', 'refused'));
    qbBrowser()->get('/q/not-a-code-at-all')->assertInertia(fn ($page) => $page->where('state', 'invalid'));

    // The forged signature looks like somebody getting round the station.
    expect(AttendanceQrScan::query()->where('reason', 'invalid')->count())->toBe(1)
        ->and(ActivityLog::query()->where('description', 'like', 'Refused a QR scan%')->count())->toBe(1);
});

test('a claim is this browser’s alone, lasts three minutes, and is spent by one person', function () {
    qbWorker();
    $other = qbWorker('EMP-00077');
    KioskPins::change($other->kioskPin()->firstOrFail(), '615273');
    $station = qbStation();

    $claim = qbOpen($station);

    // Another browser can't use it.
    qbIdentify($claim, qbAt(10), browser: 'browser-ffffffffffffffffffffffffffffffffff')
        ->assertUnprocessable()->assertJsonPath('reason', 'expired');

    qbIdentify($claim, qbAt(10))->assertOk();

    // Spent: a second person on the same page is told to scan again.
    qbIdentify($claim, qbAt(10), '77', '615273')->assertUnprocessable()->assertJsonPath('reason', 'expired');

    $late = qbOpen($station);
    $this->travel(QrScans::CLAIM_SECONDS + 1)->seconds();
    qbIdentify($late, qbAt(10))->assertUnprocessable()->assertJsonPath('reason', 'expired');
});

// ── Who and where ────────────────────────────────────────────────────────────

test('a wrong PIN names nobody, is written down, and five lock the PIN', function () {
    $employee = qbWorker();
    $station = qbStation();

    foreach (range(1, EmployeeKioskPin::MAX_ATTEMPTS) as $try) {
        qbIdentify(qbOpen($station), qbAt(10), pin: '111222')
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'wrong_pin');
    }

    expect(AttendanceQrScan::query()->where('reason', 'wrong_pin')->whereNull('employee_id')->count())->toBe(EmployeeKioskPin::MAX_ATTEMPTS)
        ->and($employee->kioskPin()->first()->isLocked())->toBeTrue();

    qbIdentify(qbOpen($station), qbAt(10))
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'wrong_pin')
        ->assertJsonFragment(['message' => 'This PIN is locked after too many wrong tries. Try again in 15 minutes, or ask HR for a new one.']);
});

test('the browser must be inside the station’s site, with a location precise enough', function () {
    qbWorker();
    $station = qbStation();

    qbIdentify(qbOpen($station), ['latitude' => null, 'longitude' => null, 'accuracy' => null])
        ->assertUnprocessable()->assertJsonPath('reason', 'no_location');
    qbIdentify(qbOpen($station), qbAt(10, 400))
        ->assertUnprocessable()->assertJsonPath('reason', 'imprecise');
    qbIdentify(qbOpen($station), qbAt(2500))
        ->assertUnprocessable()->assertJsonPath('reason', 'away');

    expect(AttendanceQrScan::query()->where('reason', 'away')->sole()->client)->toBe('browser')
        ->and(AttendancePunch::count())->toBe(0);
});

test('one browser clocks in one person', function () {
    qbWorker();
    $other = qbWorker('EMP-00077');
    KioskPins::change($other->kioskPin()->firstOrFail(), '615273');
    $station = qbStation();

    $ticket = qbIdentify(qbOpen($station), qbAt(10))->json('ticket');
    qbBrowser()->postJson('/qr-scan/punch', ['ticket' => $ticket, 'type' => 'clock_in'])->assertOk();

    qbIdentify(qbOpen($station), qbAt(10), '77', '615273')
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'shared_device');
});

test('the PIN HR issued is changed before the first punch', function () {
    $employee = qbWorker(chosen: false);
    $issued = KioskPins::issue($employee, User::factory()->create());
    $station = qbStation();

    $ticket = qbIdentify(qbOpen($station), qbAt(10), pin: $issued)
        ->assertOk()
        ->assertJsonPath('must_change_pin', true)
        ->json('ticket');

    qbBrowser()->postJson('/qr-scan/punch', ['ticket' => $ticket, 'type' => 'clock_in'])
        ->assertUnprocessable()->assertJsonPath('reason', 'must_change_pin');

    qbBrowser()->postJson('/qr-scan/pin', ['ticket' => $ticket, 'pin' => $issued])
        ->assertUnprocessable();
    qbBrowser()->postJson('/qr-scan/pin', ['ticket' => $ticket, 'pin' => '111111'])
        ->assertUnprocessable();
    qbBrowser()->postJson('/qr-scan/pin', ['ticket' => $ticket, 'pin' => QB_PIN])->assertOk();

    qbBrowser()->postJson('/qr-scan/punch', ['ticket' => $ticket, 'type' => 'clock_in'])->assertOk();

    expect($employee->kioskPin()->first()->must_change)->toBeFalse();
});

test('a ticket is good only in the browser that bought it', function () {
    qbWorker();
    $station = qbStation();
    $ticket = qbIdentify(qbOpen($station), qbAt(10))->json('ticket');

    qbBrowser('browser-ffffffffffffffffffffffffffffffffff')
        ->postJson('/qr-scan/punch', ['ticket' => $ticket, 'type' => 'clock_in'])
        ->assertUnprocessable()->assertJsonPath('reason', 'expired');

    expect(AttendancePunch::count())->toBe(0);
});

test('a policy that wants a photo refuses a browser punch without one, and takes one with it', function () {
    Storage::fake(Uploads::DISK);
    qbWorker(policy: ['capture' => [...AttendancePolicySettings::fallback()->toArray()['capture'], 'selfie_required' => true]]);
    $station = qbStation();

    $identified = qbIdentify(qbOpen($station), qbAt(10))
        ->assertOk()
        ->assertJsonPath('day.selfie_required', true);
    $ticket = $identified->json('ticket');

    qbBrowser()->postJson('/qr-scan/punch', ['ticket' => $ticket, 'type' => 'clock_in'])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'punch_refused');

    qbBrowser()->post('/qr-scan/punch', [
        'ticket' => $ticket,
        'type' => 'clock_in',
        'photo' => UploadedFile::fake()->image('selfie.jpg', 640, 480),
    ], ['Accept' => 'application/json'])->assertOk();

    expect(AttendancePunch::sole()->photo)->not->toBeNull();
});

test('a browser signed in to another company still scans as the number and PIN say', function () {
    $employee = qbWorker();
    $station = qbStation();
    $code = QrCodes::issue($station)['codes'][0]['code'];
    $tenancy = app(Tenancy::class);
    $mine = $tenancy->organization();

    $tenancy->set(Organization::factory()->create());
    $outsider = User::factory()->create();
    $tenancy->set($mine);

    $browser = function () use ($outsider, $tenancy): TestCase {
        $tenancy->forget();

        return test()->actingAs($outsider)->withCredentials()->withCookie(QrWebScanController::DEVICE_COOKIE, QB_BROWSER);
    };

    $claim = (string) $browser()->get('/q/'.$code)->assertOk()->viewData('page')['props']['claim'];
    $ticket = $browser()->postJson('/qr-scan/identify', ['claim' => $claim, 'number' => '42', 'pin' => QB_PIN, ...qbAt(10)])
        ->assertOk()
        ->json('ticket');
    $browser()->postJson('/qr-scan/punch', ['ticket' => $ticket, 'type' => 'clock_in'])->assertOk();

    $punch = AttendancePunch::withoutGlobalScopes()->sole();

    expect($punch->organization_id)->toBe($mine->id)
        ->and($punch->employee_id)->toBe($employee->id);
});

// ── The office network ───────────────────────────────────────────────────────

test('a station on the office Wi-Fi only takes scans from the screen’s own connection', function () {
    qbWorker();
    $station = qbStation(['same_network' => true]);

    // The screen asks from the office's address.
    QrCodes::issue($station, null, '198.51.100.20');
    $code = QrCodes::code($station, intdiv(now()->getTimestamp(), 15));

    $claim = (string) qbBrowser(ip: '203.0.113.7')->get('/q/'.$code)->viewData('page')['props']['claim'];
    qbIdentify($claim, qbAt(10), ip: '203.0.113.7')
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'other_network');

    $claim = (string) qbBrowser(ip: '198.51.100.20')->get('/q/'.$code)->viewData('page')['props']['claim'];
    qbIdentify($claim, qbAt(10), ip: '198.51.100.20')->assertOk();
});

test('on IPv6, the same network is the same /64', function () {
    expect(QrScans::sameNetwork('2001:db8:1:2::10', '2001:db8:1:2:ffff::1'))->toBeTrue()
        ->and(QrScans::sameNetwork('2001:db8:1:3::10', '2001:db8:1:2::1'))->toBeFalse()
        ->and(QrScans::sameNetwork('198.51.100.20', '198.51.100.20'))->toBeTrue()
        ->and(QrScans::sameNetwork('198.51.100.21', '198.51.100.20'))->toBeFalse()
        ->and(QrScans::sameNetwork('198.51.100.20', null))->toBeFalse();
});

test('the app is held to the office network too', function () {
    $user = User::factory()->create();
    $user->roles()->attach(makeRole('qb-staff', ['attendance.clock']));
    dayShiftWorker($user);
    $station = qbStation(['same_network' => true, 'scan_mode' => 'app']);
    QrCodes::issue($station, null, '198.51.100.20');
    $code = QrCodes::code($station, intdiv(now()->getTimestamp(), 15));

    Sanctum::actingAs($user);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson('/api/attendance/qr/scan', ['code' => $code, ...qbAt(10), 'device_id' => 'device-0123456789abcdef'])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'other_network');

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->postJson('/api/attendance/qr/scan', ['code' => $code, ...qbAt(10), 'device_id' => 'device-0123456789abcdef'])
        ->assertOk();
});

// ── HR's side ────────────────────────────────────────────────────────────────

test('HR sets how a station is scanned, and sees how many people have a PIN', function () {
    actingAsUserWith(['attendance.qr.manage']);
    $site = WorkLocation::create(['name' => 'Gate', 'latitude' => 14.5, 'longitude' => 121.0, 'radius_meters' => 80]);
    qbWorker();

    $this->post('/attendance/qr', [
        'name' => 'Warehouse gate',
        'work_location_id' => $site->id,
        'rotate_seconds' => 15,
        'scan_mode' => 'any',
        'same_network' => true,
    ])->assertRedirect();

    $station = AttendanceQrStation::query()->where('name', 'Warehouse gate')->sole();

    expect($station->scan_mode)->toBe('any')->and($station->same_network)->toBeTrue();

    $this->post('/attendance/qr', ['name' => 'X', 'work_location_id' => $site->id, 'rotate_seconds' => 15, 'scan_mode' => 'fax'])
        ->assertSessionHasErrors('scan_mode');

    $this->get('/attendance/qr')->assertInertia(fn ($page) => $page
        ->where('stations.0.scan_mode', 'any')
        ->where('pins.issued', 1));
});
