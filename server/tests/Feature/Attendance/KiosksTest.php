<?php

use App\Http\Middleware\AuthenticateKiosk;
use App\Models\ActivityLog;
use App\Models\AttendanceKiosk;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeKioskPin;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\KioskPins;
use App\Support\Attendance\ScheduleAssigner;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
| ADR 0092 — kiosks for the people who have no phone. A device becomes a kiosk
| with a one-time pairing code and is known from then on by a secret in its
| cookie; a person is known by their employee number and a PIN, and named only
| once both match. Every punch goes through the engine, placed at the kiosk's
| site.
|
| "Today" is Friday 2026-09-18, 08:05 in Manila. Makati Office is a 100 m fence
| at 14.5547 N, 121.0244 E; Pasig Warehouse is about 6 km east.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:05', 'Asia/Manila'));
    testOrganization();
});

/** The PIN every kiosk worker here has chosen. */
const KIOSK_TEST_PIN = '482915';

function kioskOffice(): WorkLocation
{
    return WorkLocation::firstOrCreate(['name' => 'Makati Office'], [
        'latitude' => 14.5547, 'longitude' => 121.0244, 'radius_meters' => 100,
    ]);
}

function kioskWarehouse(): WorkLocation
{
    return WorkLocation::firstOrCreate(['name' => 'Pasig Warehouse'], [
        'latitude' => 14.5547, 'longitude' => 121.0800, 'radius_meters' => 100,
    ]);
}

/** A kiosk at a site, and the secret its device holds. */
function kioskDevice(?WorkLocation $site = null, string $name = 'Front gate'): array
{
    $kiosk = AttendanceKiosk::create(['name' => $name, 'work_location_id' => $site?->id]);

    return [$kiosk, $kiosk->pair('Chrome on Android')];
}

/**
 * A day-shift worker with no app, based at the office, holding a PIN they have
 * already made their own.
 */
function kioskWorker(string $number = 'EMP-00042', array $policy = [], bool $chosen = true): Employee
{
    $schedule = WorkSchedule::query()->where('name', 'Day Shift')->first();

    if ($schedule === null) {
        $employee = dayShiftWorker(null, $policy);
    } else {
        $employee = Employee::factory()->create(['work_schedule_id' => null]);
        app(ScheduleAssigner::class)->assign($employee, $schedule, '2026-09-01');
    }

    $employee->forceFill(['employee_no' => $number, 'middle_name' => null, 'suffix' => null])->save();
    $employee->workLocations()->sync([kioskOffice()->id => ['is_primary' => true]]);

    KioskPins::issue($employee, User::factory()->create());

    if ($chosen) {
        KioskPins::change($employee->kioskPin()->firstOrFail(), KIOSK_TEST_PIN);
    }

    return $employee->refresh();
}

/** Nobody signed in, and no tenant bound — as a request from a fresh browser is. */
function kioskGuest(): void
{
    app('auth')->forgetGuards();
    app(Tenancy::class)->forget();
}

/** A request from the kiosk device: nobody signed in, no tenant bound but the kiosk's. */
function fromKiosk(string $token): TestCase
{
    kioskGuest();

    // The JSON helpers send cookies only when asked to, as fetch() does with credentials.
    return test()->withCredentials()->withCookie(AuthenticateKiosk::COOKIE, $token);
}

function kioskSignIn(string $token, string $number = '42', string $pin = KIOSK_TEST_PIN): TestResponse
{
    return fromKiosk($token)->postJson('/kiosk/sign-in', ['number' => $number, 'pin' => $pin]);
}

function kioskCapture(array $capture): array
{
    return ['capture' => [...AttendancePolicySettings::fallback()->toArray()['capture'], ...$capture]];
}

// ── Pairing ──────────────────────────────────────────────────────────────────

test('HR gets a pairing code once, and the device that types it becomes the kiosk', function () {
    actingAsUserWith(['attendance.kiosks.manage']);
    $kiosk = AttendanceKiosk::create(['name' => 'Front gate', 'work_location_id' => kioskOffice()->id]);

    $code = $this->postJson("/attendance/kiosks/{$kiosk->hashid}/pairing-code")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json('code');

    expect($code)->toHaveLength(AttendanceKiosk::CODE_LENGTH)
        // Only a hash is kept.
        ->and($kiosk->refresh()->pairing_code_hash)->toBe(hash('sha256', $code))
        ->and($kiosk->status())->toBe('pairing');

    kioskGuest();

    // Typed as somebody would: lower case, with the dash it is shown with.
    $typed = strtolower(substr($code, 0, 4).'-'.substr($code, 4));
    $response = $this->postJson('/kiosk/pair', ['code' => $typed], ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/129.0 Safari/537.36'])
        ->assertOk()
        ->assertJsonPath('kiosk.name', 'Front gate')
        ->assertJsonPath('kiosk.site', 'Makati Office');

    $cookie = $response->getCookie(AuthenticateKiosk::COOKIE);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and($kiosk->refresh()->status())->toBe('paired')
        ->and($kiosk->device_label)->toBe('Chrome on Android')
        ->and($kiosk->device_token_hash)->toBe(hash('sha256', $cookie->getValue()))
        ->and($kiosk->pairing_code_hash)->toBeNull();

    // The code is spent.
    $this->postJson('/kiosk/pair', ['code' => $code])->assertStatus(422);

    fromKiosk($cookie->getValue())->getJson('/kiosk/state')->assertOk()->assertJsonPath('kiosk.name', 'Front gate');
});

test('a pairing code stops working after fifteen minutes', function () {
    $kiosk = AttendanceKiosk::create(['name' => 'Front gate']);
    $code = $kiosk->issuePairingCode();
    $kiosk->save();

    $this->travel(AttendanceKiosk::PAIRING_MINUTES + 1)->minutes();
    app(Tenancy::class)->forget();

    $this->postJson('/kiosk/pair', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That code doesn’t work. A code works once, for 15 minutes — ask HR for a new one.');
});

test('pairing again ends the device that was the kiosk before', function () {
    [$kiosk, $old] = kioskDevice(kioskOffice());

    $new = $kiosk->refresh()->pair('Safari on iPad');

    fromKiosk($old)->getJson('/kiosk/state')->assertStatus(401)->assertJsonPath('reason', 'unpaired');
    fromKiosk($new)->getJson('/kiosk/state')->assertOk();
});

test('a browser somebody is signed in on is not a kiosk', function () {
    [, $token] = kioskDevice(kioskOffice());
    actingAsUserWith(['attendance.clock']);

    $this->withCredentials()->withCookie(AuthenticateKiosk::COOKIE, $token)
        ->postJson('/kiosk/sign-in', ['number' => '42', 'pin' => KIOSK_TEST_PIN])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'signed_in');

    $this->withCookie(AuthenticateKiosk::COOKIE, $token)->get('/kiosk')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('kiosk')->where('state', 'signed_in')->where('kiosk', null));

    $this->postJson('/kiosk/pair', ['code' => 'ABCDEFGH'])->assertStatus(409);
});

test('the page says which screen a device should show', function () {
    [, $token] = kioskDevice(kioskOffice());
    kioskGuest();

    $this->get('/kiosk')->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'unpaired')->where('kiosk', null));

    $this->withCookie(AuthenticateKiosk::COOKIE, $token)->get('/kiosk')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'ready')
            ->where('kiosk.name', 'Front gate')
            ->where('kiosk.site', 'Makati Office')
            ->where('kiosk.timezone', 'Asia/Manila')
            ->where('kiosk.pin_length', 6));
});

test('a device with no secret, or a removed kiosk’s, is turned away', function () {
    [$kiosk, $token] = kioskDevice(kioskOffice());

    kioskGuest();
    $this->postJson('/kiosk/sign-in', ['number' => '42', 'pin' => KIOSK_TEST_PIN])->assertStatus(401);
    fromKiosk(str_repeat('x', 64))->getJson('/kiosk/state')->assertStatus(401);

    $kiosk->unpair();
    $kiosk->save();
    $kiosk->delete();

    fromKiosk($token)->getJson('/kiosk/state')->assertStatus(401)->assertCookieExpired(AuthenticateKiosk::COOKIE);
});

// ── Signing in ───────────────────────────────────────────────────────────────

test('a number and PIN that match name the person and say what they can punch', function () {
    kioskWorker();
    [, $token] = kioskDevice(kioskOffice());

    kioskSignIn($token)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('must_change_pin', false)
        ->assertJsonPath('person.first_name', Employee::query()->first()->first_name)
        ->assertJsonPath('day.next_expected', 'clock_in')
        ->assertJsonPath('day.allowed', ['clock_in'])
        ->assertJsonPath('day.kiosk_allowed', true)
        ->assertJsonPath('day.photo_required', false)
        ->assertJsonMissingPath('person.photo')
        ->assertJsonStructure(['ticket', 'expires_in', 'day' => ['shift', 'punches']]);
});

test('a wrong PIN and a number nobody holds get the same answer', function () {
    kioskWorker();
    [, $token] = kioskDevice(kioskOffice());

    $wrongPin = kioskSignIn($token, '42', '000999')->assertStatus(422)->json('message');
    $nobody = kioskSignIn($token, '777', KIOSK_TEST_PIN)->assertStatus(422)->json('message');

    expect($wrongPin)->toBe($nobody)->toBe('That number and PIN don’t match. Check both and try again.');
});

test('the digits of a number find it, and only it', function () {
    kioskWorker('EMP-00042');
    kioskWorker('EMP-00142');
    [, $token] = kioskDevice(kioskOffice());

    $fortyTwo = Employee::query()->where('employee_no', 'EMP-00042')->firstOrFail();

    kioskSignIn($token, '42')->assertOk()->assertJsonPath('person.full_name', $fortyTwo->full_name);
    kioskSignIn($token, '00042')->assertOk()->assertJsonPath('person.full_name', $fortyTwo->full_name);
    kioskSignIn($token, 'emp-00042')->assertOk()->assertJsonPath('person.full_name', $fortyTwo->full_name);
    kioskSignIn($token, '0')->assertStatus(422);
});

test('five wrong PINs lock it, even against the right one, and the lock is logged', function () {
    $employee = kioskWorker();
    [, $token] = kioskDevice(kioskOffice());

    foreach (range(1, 4) as $try) {
        kioskSignIn($token, '42', '111222')->assertStatus(422)->assertJsonPath('message', 'That number and PIN don’t match. Check both and try again.');
    }

    kioskSignIn($token, '42', '111222')->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'This PIN is locked'));
    kioskSignIn($token)->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'This PIN is locked'));

    expect($employee->kioskPin()->first()->status())->toBe('locked')
        ->and(ActivityLog::query()->where('description', 'like', 'Kiosk PIN locked%')->count())->toBe(1);

    $this->travel(EmployeeKioskPin::LOCK_MINUTES + 1)->minutes();
    kioskSignIn($token)->assertOk();
});

test('a kiosk pauses after twenty wrong tries, whoever they were for', function () {
    [, $token] = kioskDevice(kioskOffice());
    kioskWorker();

    foreach (range(1, KioskPins::KIOSK_FAILURES) as $number) {
        kioskSignIn($token, (string) (1000 + $number), '135792')->assertStatus(422);
    }

    kioskSignIn($token)->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Too many wrong tries at this kiosk'));
});

test('somebody who has left, or is archived, is nobody at a kiosk', function () {
    $employee = kioskWorker();
    [, $token] = kioskDevice(kioskOffice());

    $employee->forceFill(['employment_status' => 'resigned'])->save();
    kioskSignIn($token)->assertStatus(422);

    $employee->forceFill(['employment_status' => 'active'])->save();
    $employee->delete();
    kioskSignIn($token)->assertStatus(422);
});

test('a kiosk only knows its own company’s people', function () {
    kioskWorker();

    $other = Organization::factory()->create();
    app(Tenancy::class)->set($other);
    [, $token] = kioskDevice();

    kioskSignIn($token)->assertStatus(422);
});

// ── Choosing a PIN ───────────────────────────────────────────────────────────

test('the PIN HR issues works once, to choose one’s own', function () {
    $employee = dayShiftWorker();
    $employee->forceFill(['employee_no' => 'EMP-00042'])->save();
    $issued = KioskPins::issue($employee, User::factory()->create());
    [, $token] = kioskDevice(kioskOffice());

    $ticket = kioskSignIn($token, '42', $issued)->assertOk()->assertJsonPath('must_change_pin', true)->json('ticket');

    fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_in'])
        ->assertStatus(422)->assertJsonPath('reason', 'must_change_pin');

    $choose = fn (string $pin) => fromKiosk($token)->postJson('/kiosk/pin', ['ticket' => $ticket, 'pin' => $pin, 'pin_confirmation' => $pin]);

    $choose('777777')->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'one digit over and over'));
    $choose('345678')->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'counts straight up or down'));
    $choose($issued)->assertStatus(422)->assertJsonPath('message', 'Choose a PIN of your own, not the one HR gave you.');
    fromKiosk($token)->postJson('/kiosk/pin', ['ticket' => $ticket, 'pin' => KIOSK_TEST_PIN, 'pin_confirmation' => '482916'])->assertStatus(422);

    $choose(KIOSK_TEST_PIN)->assertOk()->assertJsonPath('must_change_pin', false);

    $record = $employee->kioskPin()->first();
    expect(Hash::check(KIOSK_TEST_PIN, $record->pin_hash))->toBeTrue()
        ->and($record->status())->toBe('active')
        ->and(ActivityLog::query()->where('description', 'Chose their own kiosk PIN')->exists())->toBeTrue();

    fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_in'])->assertOk();
    kioskSignIn($token, '42', $issued)->assertStatus(422);
});

test('weak PINs are never issued', function () {
    expect(KioskPins::weakness('000000'))->not->toBeNull()
        ->and(KioskPins::weakness('987654'))->not->toBeNull()
        ->and(KioskPins::weakness('482915'))->toBeNull()
        ->and(KioskPins::weakness('112233'))->toBeNull();

    $employee = Employee::factory()->create();

    foreach (range(1, 25) as $round) {
        expect(KioskPins::weakness(KioskPins::issue($employee, User::factory()->create())))->toBeNull();
    }
});

// ── Punching ─────────────────────────────────────────────────────────────────

test('a kiosk punch goes through the engine, at the kiosk’s site, and spends its ticket', function () {
    $employee = kioskWorker();
    [$kiosk, $token] = kioskDevice(kioskOffice());

    $ticket = kioskSignIn($token)->json('ticket');

    fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_in'])
        ->assertOk()
        ->assertJsonPath('message', 'Clocked in')
        ->assertJsonPath('day.next_expected', 'clock_out')
        ->assertJsonPath('day.allowed', ['break_start', 'clock_out']);

    $punch = AttendancePunch::query()->where('employee_id', $employee->id)->sole();

    expect($punch->source)->toBe('kiosk')
        ->and($punch->attendance_kiosk_id)->toBe($kiosk->id)
        ->and($punch->work_location_id)->toBe(kioskOffice()->id)
        ->and($punch->distance_meters)->toBe(0)
        ->and($punch->within_geofence)->toBeTrue()
        ->and($punch->latitude)->toBeNull()
        // Open until they clock out.
        ->and(AttendanceRecord::query()->where('employee_id', $employee->id)->value('status'))->toBe('incomplete')
        ->and(ActivityLog::query()->where('description', 'Clocked in at the kiosk “Front gate”')->value('subject_label'))->toBe($employee->full_name);

    // Spent: the next person has to sign in.
    fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_out'])
        ->assertStatus(422)->assertJsonPath('reason', 'expired');
});

test('the day’s order holds at a kiosk, and a refused punch keeps the ticket', function () {
    kioskWorker();
    [, $token] = kioskDevice(kioskOffice());

    $ticket = kioskSignIn($token)->json('ticket');

    fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_out'])
        ->assertStatus(422)->assertJsonPath('message', 'You need to clock in first.');

    fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_in'])->assertOk();
});

test('a ticket is good only at the kiosk that issued it, and only for two minutes', function () {
    kioskWorker();
    [, $front] = kioskDevice(kioskOffice());
    [, $back] = kioskDevice(kioskOffice(), 'Back door');

    $ticket = kioskSignIn($front)->json('ticket');

    fromKiosk($back)->postJson('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_in'])->assertStatus(422)->assertJsonPath('reason', 'expired');

    $this->travel(3)->minutes();
    fromKiosk($front)->postJson('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_in'])->assertStatus(422)->assertJsonPath('reason', 'expired');
});

test('walking away drops the ticket', function () {
    kioskWorker();
    [, $token] = kioskDevice(kioskOffice());
    $ticket = kioskSignIn($token)->json('ticket');

    fromKiosk($token)->postJson('/kiosk/done', ['ticket' => $ticket])->assertNoContent();
    fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_in'])->assertJsonPath('reason', 'expired');
});

test('a PIN HR withdraws or reissues stops the ticket it bought', function () {
    $employee = kioskWorker();
    [, $token] = kioskDevice(kioskOffice());
    $ticket = kioskSignIn($token)->json('ticket');

    $this->travel(5)->seconds();
    KioskPins::issue($employee, User::factory()->create());

    fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_in'])->assertJsonPath('reason', 'expired');
});

test('a kiosk at somebody else’s site is off theirs: flagged, or refused under block', function (string $mode) {
    kioskWorker('EMP-00042', kioskCapture(['geofence' => $mode]));
    kioskWarehouse();
    [, $token] = kioskDevice(kioskWarehouse(), 'Warehouse gate');

    $response = fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => kioskSignIn($token)->json('ticket'), 'type' => 'clock_in']);

    if ($mode === 'block') {
        $response->assertStatus(422)->assertJsonPath('message', 'This kiosk is at Pasig Warehouse, 6.0 km from Makati Office, where you’re based. Punch at a kiosk on your own site.');
        expect(AttendancePunch::query()->count())->toBe(0);

        return;
    }

    $response->assertOk();
    $punch = AttendancePunch::query()->sole();

    expect($punch->within_geofence)->toBeFalse()
        ->and($punch->work_location_id)->toBe(kioskOffice()->id)
        ->and($punch->distance_meters)->toBeGreaterThan(5900)
        ->and($punch->record->flags)->toContain('outside_geofence');
})->with(['flag', 'block']);

test('a kiosk with no site places nobody, which a fence-checking policy notices', function () {
    kioskWorker('EMP-00042', kioskCapture(['geofence' => 'block']));
    [, $token] = kioskDevice();

    fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => kioskSignIn($token)->json('ticket'), 'type' => 'clock_in'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your attendance policy needs punches made on site, and this kiosk isn’t set to a site yet. Ask HR to set where it stands.');
});

test('a policy that doesn’t allow kiosks says so before the punch, and refuses it', function () {
    kioskWorker('EMP-00042', kioskCapture(['allowed_sources' => ['mobile', 'manual']]));
    [, $token] = kioskDevice(kioskOffice());

    $signedIn = kioskSignIn($token)->assertOk()->assertJsonPath('day.kiosk_allowed', false);

    fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => $signedIn->json('ticket'), 'type' => 'clock_in'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your attendance policy does not allow punching from a kiosk. Punch from the mobile app or entry by HR instead.');
});

test('a policy that needs a photo needs the kiosk’s camera, and a refused punch keeps no photo', function () {
    Storage::fake('uploads');
    $employee = kioskWorker('EMP-00042', kioskCapture(['selfie_required' => true, 'geofence' => 'block']));
    [, $token] = kioskDevice(kioskOffice());

    $ticket = kioskSignIn($token)->assertJsonPath('day.photo_required', true)->json('ticket');

    fromKiosk($token)->postJson('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_in'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your attendance policy needs a photo with every punch. Face the camera and try again, or ask HR if this kiosk has none.');

    // Refused for another reason: the photo taken for it is not kept.
    fromKiosk($token)->post('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_out', 'photo' => UploadedFile::fake()->image('face.jpg')], ['Accept' => 'application/json'])
        ->assertStatus(422);
    expect(Storage::disk('uploads')->allFiles())->toBe([]);

    fromKiosk($token)->post('/kiosk/punch', ['ticket' => $ticket, 'type' => 'clock_in', 'photo' => UploadedFile::fake()->image('face.jpg')], ['Accept' => 'application/json'])
        ->assertOk();

    $photo = AttendancePunch::query()->where('employee_id', $employee->id)->value('photo');
    expect($photo)->toStartWith('attendance/punches/');
    Storage::disk('uploads')->assertExists($photo);
});

test('a kiosk’s heartbeat is when it was last seen, without an edit in the log', function () {
    [$kiosk, $token] = kioskDevice(kioskOffice());
    $this->travel(10)->minutes();

    fromKiosk($token)->getJson('/kiosk/state')->assertOk()->assertJsonStructure(['kiosk' => ['now', 'timezone']]);

    expect($kiosk->refresh()->last_seen_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and($kiosk->isOnline())->toBeTrue();
});

// ── Managing kiosks and PINs ─────────────────────────────────────────────────

test('the kiosks screen is for whoever may manage kiosks, and holds no secret', function () {
    kioskWorker();
    [$kiosk] = kioskDevice(kioskOffice());

    actingAsUserWith(['attendance.view']);
    $this->get('/attendance/kiosks')->assertForbidden();

    actingAsUserWith(['attendance.kiosks.manage']);
    $response = $this->get('/attendance/kiosks')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('attendance/kiosks')
        ->has('kiosks', 1)
        ->where('kiosks.0.hashid', $kiosk->hashid)
        ->where('kiosks.0.status', 'paired')
        ->where('kiosks.0.device_label', 'Chrome on Android')
        ->where('kiosks.0.location.name', 'Makati Office')
        ->has('pins', 1)
        ->where('pins.0.status', 'active')
        ->where('kioskUrl', route('kiosk.show')));

    expect($response->getContent())->not->toContain('device_token_hash')->not->toContain('pin_hash');
});

test('somebody who only looks after kiosks lands on them from Attendance', function () {
    actingAsUserWith(['attendance.kiosks.manage']);

    $this->get('/attendance')->assertRedirect('/attendance/kiosks');
});

test('HR adds, renames, signs out and removes a kiosk', function () {
    actingAsUserWith(['attendance.kiosks.manage']);
    $site = kioskOffice();

    $this->post('/attendance/kiosks', ['name' => 'Front gate', 'work_location_id' => $site->id])->assertRedirect();
    assertToast('success', 'Front gate');

    $kiosk = AttendanceKiosk::query()->sole();
    expect($kiosk->status())->toBe('unpaired');

    $this->post("/attendance/kiosks/{$kiosk->hashid}", ['name' => 'Main entrance', 'work_location_id' => null])->assertRedirect();
    expect($kiosk->refresh()->name)->toBe('Main entrance')->and($kiosk->work_location_id)->toBeNull();

    $token = $kiosk->pair('Chrome on Android');
    $this->delete("/attendance/kiosks/{$kiosk->hashid}/device")->assertRedirect();
    expect($kiosk->refresh()->status())->toBe('unpaired');

    $hr = auth()->user();
    fromKiosk($token)->getJson('/kiosk/state')->assertStatus(401);

    $this->actingAs($hr);
    $this->delete("/attendance/kiosks/{$kiosk->hashid}")->assertRedirect();
    expect(AttendanceKiosk::query()->count())->toBe(0)
        ->and(AttendanceKiosk::withTrashed()->count())->toBe(1);
});

test('a kiosk can only stand at one of this company’s sites', function () {
    actingAsUserWith(['attendance.kiosks.manage']);

    $theirs = app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => kioskWarehouse());

    $this->post('/attendance/kiosks', ['name' => 'Front gate', 'work_location_id' => $theirs->id])
        ->assertSessionHasErrors('work_location_id');
});

test('HR issues PINs to a crew at once, sees each once, and can withdraw one', function () {
    $hr = actingAsUserWith(['attendance.kiosks.manage']);
    $first = Employee::factory()->create(['employee_no' => 'EMP-00042']);
    $second = Employee::factory()->create(['employee_no' => 'EMP-00043']);
    $gone = Employee::factory()->create(['employment_status' => 'resigned']);

    $this->postJson('/attendance/kiosks/pins', ['employee_ids' => [$gone->id]])->assertStatus(422);

    $issued = $this->postJson('/attendance/kiosks/pins', ['employee_ids' => [$first->id, $second->id]])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json('issued');

    expect($issued)->toHaveCount(2)
        ->and(collect($issued)->pluck('pin')->every(fn (string $pin) => preg_match('/^\d{6}$/', $pin) === 1))->toBeTrue()
        ->and(EmployeeKioskPin::query()->where('must_change', true)->count())->toBe(2)
        ->and(EmployeeKioskPin::query()->where('issued_by', $hr->id)->count())->toBe(2);

    $pin = $first->kioskPin()->firstOrFail();
    expect(Hash::check(collect($issued)->firstWhere('employee_no', 'EMP-00042')['pin'], $pin->pin_hash))->toBeTrue();

    // Reissuing unlocks and replaces.
    $pin->forceFill(['locked_until' => now()->addMinutes(10)])->save();
    $again = $this->postJson('/attendance/kiosks/pins', ['employee_ids' => [$first->id]])->assertOk()->json('issued.0');
    expect($again['replaced'])->toBeTrue()->and($pin->refresh()->status())->toBe('new');

    $this->delete("/attendance/kiosks/pins/{$pin->id}")->assertRedirect();
    expect($first->kioskPin()->exists())->toBeFalse()
        ->and(ActivityLog::query()->where('description', 'Withdrew the kiosk PIN')->exists())->toBeTrue();
});

test('managing kiosks needs its permission', function () {
    actingAsUserWith(['attendance.manage', 'attendance.view']);
    $kiosk = AttendanceKiosk::create(['name' => 'Front gate']);
    $employee = Employee::factory()->create();

    $this->post('/attendance/kiosks', ['name' => 'Back door'])->assertForbidden();
    $this->postJson("/attendance/kiosks/{$kiosk->hashid}/pairing-code")->assertForbidden();
    $this->postJson('/attendance/kiosks/pins', ['employee_ids' => [$employee->id]])->assertForbidden();
});

// ── Policies ─────────────────────────────────────────────────────────────────

test('the kiosk is a way to punch again, and a policy with nothing set allows it', function () {
    expect(AttendancePunch::CAPTURE_SOURCES)->toBe(['web', 'mobile', 'kiosk', 'manual'])
        ->and(AttendancePolicySettings::fallback()->allowedSources)->toContain('kiosk')
        // Scanners stay gone: a stored scanner reads as nothing.
        ->and(AttendancePolicySettings::fromArray(['capture' => ['allowed_sources' => ['biometric']]])->allowedSources)
        ->toBe(AttendancePunch::CAPTURE_SOURCES);
});

test('the kiosks screen names the policies that would turn kiosk punches away', function () {
    AttendancePolicy::create([
        'name' => 'Office staff',
        'settings' => AttendancePolicySettings::fromArray(kioskCapture(['allowed_sources' => ['web', 'mobile']]))->toArray(),
        'settings_version' => AttendancePolicySettings::VERSION,
    ]);
    AttendancePolicy::create([
        'name' => 'Warehouse',
        'settings' => AttendancePolicySettings::fallback()->toArray(),
        'settings_version' => AttendancePolicySettings::VERSION,
    ]);

    actingAsUserWith(['attendance.kiosks.manage']);

    $this->get('/attendance/kiosks')->assertInertia(fn (Assert $page) => $page->where('policiesWithoutKiosk', ['Office staff']));
});
