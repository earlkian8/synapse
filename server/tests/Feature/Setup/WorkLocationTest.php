<?php

use App\Models\ActivityLog;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Support\Attendance\AttendanceClock;

/*
| Company Setup → Locations, on the screen: every write goes through the
| workflow the assistant shares, with the screen's toasts and audit lines.
*/

function siteForm(array $overrides = []): array
{
    return ['name' => 'Makati Office', 'address' => 'Ayala Ave', 'latitude' => 14.5547, 'longitude' => 121.0244, 'radius_meters' => 150, ...$overrides];
}

test('a site is created, edited, given its people, archived and restored', function () {
    actingAsSuperAdmin();
    [$ana, $ben] = Employee::factory()->count(2)->create()->all();

    $this->post(route('setup.locations.store'), siteForm())->assertSessionHasNoErrors();
    assertToast('success', 'Location created');

    $site = WorkLocation::query()->where('name', 'Makati Office')->firstOrFail();

    $this->post(route('setup.locations.update', $site->hashid), siteForm(['radius_meters' => 300]))->assertSessionHasNoErrors();
    expect($site->fresh()->radius_meters)->toBe(300);

    $this->put(route('setup.locations.people', $site->hashid), ['employee_ids' => [$ana->id, $ben->id], 'primary_ids' => [$ana->id]])->assertSessionHasNoErrors();
    assertToast('success', '2 people are based here.');

    expect($site->employees()->count())->toBe(2)
        ->and($site->employees()->wherePivot('is_primary', true)->pluck('employees.id')->all())->toBe([$ana->id]);

    $this->delete(route('setup.locations.destroy', $site->hashid));
    expect(WorkLocation::query()->count())->toBe(0);

    $this->patch(route('setup.locations.restore', $site->hashid));
    expect(WorkLocation::query()->count())->toBe(1)
        ->and(ActivityLog::query()->where('log_name', 'company-setup')->pluck('description')->all())->toBe([
            'Created work location "Makati Office" (150 m fence)',
            'Updated work location "Makati Office"',
            'Set 2 people as based at "Makati Office"',
            'Archived work location "Makati Office"',
            'Restored work location "Makati Office"',
        ]);
});

test('a site punches name is kept, and one nobody punched at can go', function () {
    actingAsSuperAdmin();
    $employee = dayShiftWorker();

    $this->post(route('setup.locations.store'), siteForm());
    $site = WorkLocation::query()->firstOrFail();

    $this->travelTo('2026-09-21 08:00:00');
    app(AttendanceClock::class)->punch($employee, 'clock_in', ['source' => 'web', 'latitude' => 14.5547, 'longitude' => 121.0244, 'accuracy' => 5]);

    expect(AttendancePunch::query()->value('work_location_id'))->toBe($site->id);

    $this->delete(route('setup.locations.destroy', $site->hashid));
    $this->delete(route('setup.locations.force-delete', $site->hashid));

    assertToast('warning', 'Punches were made at this location');
    expect(WorkLocation::withTrashed()->whereKey($site->id)->exists())->toBeTrue();

    $this->post(route('setup.locations.store'), siteForm(['name' => 'Annex', 'latitude' => 10.3157, 'longitude' => 123.8854]));
    $annex = WorkLocation::query()->where('name', 'Annex')->firstOrFail();

    $this->delete(route('setup.locations.destroy', $annex->hashid));
    $this->delete(route('setup.locations.force-delete', $annex->hashid));

    assertToast('success', 'permanently deleted');
    expect(WorkLocation::withTrashed()->whereKey($annex->id)->exists())->toBeFalse();
});
