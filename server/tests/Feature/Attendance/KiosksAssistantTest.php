<?php

use App\Models\ActivityLog;
use App\Models\AttendanceKiosk;
use App\Models\Employee;
use App\Models\EmployeeKioskPin;
use App\Models\User;
use App\Models\WorkLocation;
use App\Services\Assistant\Modules\KiosksModule;
use App\Services\Assistant\ToolResult;
use App\Support\Attendance\KioskPins;

/*
| The kiosks capability of the assistant (ADR 0092): it reads the kiosks and
| who holds a PIN, signs a device out and withdraws a PIN — both held for
| Confirm — and never hands out a pairing code or a PIN, which would sit in the
| chat's history. Gemini is never called.
*/

function kiosksAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(KiosksModule::class)->run($user, $tool, $args);
}

function kioskAgentPerson(string $first, string $last, string $number): Employee
{
    return Employee::factory()->create(['first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix' => null, 'employee_no' => $number]);
}

test('only somebody who looks after kiosks gets the tools, and no tool hands out a secret', function () {
    expect(app(KiosksModule::class)->isAvailable(actingAsUserWith(['attendance.view', 'attendance.manage'])))->toBeFalse();

    $manager = actingAsUserWith(['attendance.kiosks.manage']);
    $module = app(KiosksModule::class);
    $names = array_column($module->tools($manager), 'name');

    expect($names)->toEqualCanonicalizing(['find_kiosks', 'find_kiosk_pins', 'sign_out_kiosk', 'withdraw_kiosk_pin'])
        ->and(json_encode($module->tools($manager)))->not->toContain('issue')->not->toContain('pairing_code')
        ->and($module->requiresConfirmation('sign_out_kiosk'))->toBeTrue()
        ->and($module->requiresConfirmation('withdraw_kiosk_pin'))->toBeTrue()
        ->and($module->isReadOnly('find_kiosks'))->toBeTrue()
        ->and($module->guidance($manager))->toContain('never given in chat');

    $viewer = actingAsUserWith(['attendance.view']);
    expect(kiosksAgent($viewer, 'find_kiosks')->status)->toBe('error');
});

test('kiosks are read with their site, their device and their day', function () {
    $user = actingAsUserWith(['attendance.kiosks.manage']);
    $site = WorkLocation::create(['name' => 'Makati Office', 'latitude' => 14.5547, 'longitude' => 121.0244, 'radius_meters' => 100]);
    $kiosk = AttendanceKiosk::create(['name' => 'Front gate', 'work_location_id' => $site->id]);
    $kiosk->pair('Chrome on Android');
    AttendanceKiosk::create(['name' => 'Back door']);

    $cards = collect(kiosksAgent($user, 'find_kiosks')->cards)->keyBy('title');

    expect($cards['Front gate']['badge'])->toBe('On line')
        ->and($cards['Front gate']['subtitle'])->toBe('At Makati Office')
        ->and(implode(' | ', $cards['Front gate']['meta']))->toContain('Chrome on Android, on line')->toContain('0 punches today')
        ->and($cards['Back door']['badge'])->toBe('No device');
});

test('PIN holders are read by person or by state, and never with their PIN', function () {
    $user = actingAsUserWith(['attendance.kiosks.manage']);
    $juan = kioskAgentPerson('Juan', 'Dela Cruz', 'EMP-00042');
    $ana = kioskAgentPerson('Ana', 'Reyes', 'EMP-00043');
    kioskAgentPerson('Lito', 'Lim', 'EMP-00044');

    $issued = KioskPins::issue($juan, $user);
    KioskPins::issue($ana, $user);
    $ana->kioskPin()->first()->forceFill(['locked_until' => now()->addMinutes(10)])->save();

    $one = kiosksAgent($user, 'find_kiosk_pins', ['employee' => 'Juan Dela Cruz']);
    expect($one->cards[0]['badge'])->toBe('Not chosen yet')
        ->and(json_encode($one->cards))->not->toContain($issued);

    $locked = kiosksAgent($user, 'find_kiosk_pins', ['status' => 'locked']);
    expect(collect($locked->cards)->pluck('title')->all())->toBe(['Ana Reyes']);

    expect(kiosksAgent($user, 'find_kiosk_pins', ['employee' => 'Lito Lim'])->detail)->toContain('has no kiosk PIN');
});

test('signing a device out says what it stops, then goes through the workflow', function () {
    $user = actingAsUserWith(['attendance.kiosks.manage']);
    $kiosk = AttendanceKiosk::create(['name' => 'Front gate']);
    $kiosk->pair('Chrome on Android');

    expect(app(KiosksModule::class)->consequence($user, 'sign_out_kiosk', ['kiosk' => 'front gate']))
        ->toContain('“Front gate” stops clocking anybody in at once');

    expect(kiosksAgent($user, 'sign_out_kiosk', ['kiosk' => 'front gate'])->status)->toBe('done')
        ->and($kiosk->refresh()->status())->toBe('unpaired')
        ->and(ActivityLog::query()->where('description', 'Signed the device out of the kiosk “Front gate” via assistant')->exists())->toBeTrue();

    expect(kiosksAgent($user, 'sign_out_kiosk', ['kiosk' => 'Front gate'])->detail)->toBe('“Front gate” has no device paired with it.');
});

test('withdrawing a PIN goes through the workflow', function () {
    $user = actingAsUserWith(['attendance.kiosks.manage']);
    $juan = kioskAgentPerson('Juan', 'Dela Cruz', 'EMP-00042');
    KioskPins::issue($juan, $user);

    expect(app(KiosksModule::class)->consequence($user, 'withdraw_kiosk_pin', ['employee' => 'EMP-00042']))
        ->toContain('Juan Dela Cruz can no longer clock in at any kiosk');

    expect(kiosksAgent($user, 'withdraw_kiosk_pin', ['employee' => 'EMP-00042'])->status)->toBe('done')
        ->and(EmployeeKioskPin::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('description', 'Withdrew the kiosk PIN via assistant')->value('subject_label'))->toBe('Juan Dela Cruz');
});
