<?php

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Permission;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePolicySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Biometric scanners and the key-driven devices are gone (ADR 0054): no screen,
| no device API, no permission, no table. What they left behind — punches that
| name them, policies that listed them — still reads. The kiosk came back on
| its own terms, paired and PIN-protected (ADR 0092; see KiosksTest).
*/

test('the device screen and the device API are gone', function () {
    actingAsSuperAdmin();

    $this->get('/setup/devices')->assertNotFound();
    $this->postJson('/api/devices/punches', ['punches' => []])->assertNotFound();
    $this->postJson('/api/devices/kiosk/lookup', ['reference' => 'EMP-00001'])->assertNotFound();
});

test('the permission, the table and the columns are gone', function () {
    seedPermissions();

    expect(Permission::query()->where('name', 'setup.devices.manage')->exists())->toBeFalse()
        ->and(Schema::hasTable('attendance_devices'))->toBeFalse()
        ->and(Schema::hasColumn('attendance_punches', 'attendance_device_id'))->toBeFalse()
        ->and(Schema::hasColumn('employees', 'device_enrollment_id'))->toBeFalse()
        // A phone's queued punch is still recognised by its own id.
        ->and(Schema::hasColumn('attendance_punches', 'external_id'))->toBeTrue();
});

test('a policy that allowed only scanners reads as allowing every way there is', function () {
    expect(AttendancePolicySettings::fromArray(['capture' => ['allowed_sources' => ['biometric']]])->allowedSources)
        ->toBe(AttendancePunch::CAPTURE_SOURCES)
        ->and(AttendancePolicySettings::fromArray(['capture' => ['allowed_sources' => ['biometric', 'mobile']]])->allowedSources)
        ->toBe(['mobile'])
        ->and(AttendancePunch::CAPTURE_SOURCES)->not->toContain('biometric');
});

test('a punch a kiosk recorded before keeps its source, and its day still judges cleanly', function () {
    $employee = dayShiftWorker();
    $clock = app(AttendanceClock::class);

    $this->travelTo('2026-09-21 08:00:00');
    $record = $clock->punch($employee, 'clock_in', ['source' => 'manual']);

    // As the removal migration leaves such a punch: its source names a kiosk.
    DB::table('attendance_punches')->where('attendance_record_id', $record->id)->update(['source' => 'kiosk']);

    $this->travelTo('2026-09-21 17:00:00');
    $clock->punch($employee, 'clock_out', ['source' => 'manual']);

    $day = AttendanceRecord::query()->findOrFail($record->id);

    expect($day->punches()->orderBy('punched_at')->pluck('source')->all())->toBe(['kiosk', 'manual'])
        ->and($day->flags ?? [])->not->toContain('source_not_allowed')
        ->and($day->worked_minutes)->toBeGreaterThan(0);
});
