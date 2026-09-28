<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\WorkSchedule;
use App\Support\Attendance\ScheduleAssigner;

/*
| Company Setup → Work Schedule & Holidays, on the screen: every write goes
| through the workflows the assistant shares, with the screen's toasts and
| audit lines.
*/

function scheduleDays(string $start = '08:00', string $end = '17:00'): array
{
    return array_map(fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => $i >= 5 ? [] : [['start' => $start, 'end' => $end]],
        'required_minutes' => $i >= 5 ? 0 : 480,
    ], range(0, 6));
}

test('a holiday is added, edited, archived, restored and permanently deleted', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.schedule.holidays.store'), ['name' => 'Founders Day', 'date' => '2026-11-03', 'type' => 'regular', 'is_recurring' => true])
        ->assertSessionHasNoErrors();
    assertToast('success', 'Holiday added.');

    $holiday = Holiday::query()->where('name', 'Founders Day')->firstOrFail();

    $this->post(route('setup.schedule.holidays.update', $holiday->hashid), ['name' => 'Founders’ Day', 'date' => '2026-11-03', 'type' => 'special_non_working'])
        ->assertSessionHasNoErrors();

    expect($holiday->fresh()->type)->toBe('special_non_working')
        ->and($holiday->fresh()->is_recurring)->toBeFalse();

    $this->delete(route('setup.schedule.holidays.destroy', $holiday->hashid));
    expect(Holiday::query()->count())->toBe(0);

    $this->patch(route('setup.schedule.holidays.restore', $holiday->hashid));
    expect(Holiday::query()->count())->toBe(1);

    $this->delete(route('setup.schedule.holidays.destroy', $holiday->hashid));
    $this->delete(route('setup.schedule.holidays.force-delete', $holiday->hashid));
    assertToast('success', 'permanently deleted');

    expect(Holiday::withTrashed()->count())->toBe(0)
        ->and(ActivityLog::query()->where('log_name', 'company-setup')->pluck('description')->all())->toBe([
            'Added holiday "Founders Day"',
            'Updated holiday "Founders’ Day"',
            'Archived holiday "Founders’ Day"',
            'Restored holiday "Founders’ Day"',
            'Archived holiday "Founders’ Day"',
            'Permanently deleted holiday "Founders’ Day"',
        ]);
});

test('a schedule is created with its pattern, and one people are on is never permanently deleted', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.schedule.work-schedules.store'), [
        'name' => 'Day Shift', 'type' => 'fixed', 'cycle_length_days' => 7, 'grace_minutes' => 5, 'days' => scheduleDays(),
    ])->assertSessionHasNoErrors();
    assertToast('success', 'Work schedule created.');

    $schedule = WorkSchedule::query()->where('name', 'Day Shift')->firstOrFail();
    app(ScheduleAssigner::class)->assign(Employee::factory()->create(), $schedule, '2026-09-01');

    expect($schedule->days()->count())->toBe(7)
        ->and($schedule->work_days)->toBe(['Mon', 'Tue', 'Wed', 'Thu', 'Fri']);

    $this->delete(route('setup.schedule.work-schedules.destroy', $schedule->hashid));
    $this->delete(route('setup.schedule.work-schedules.force-delete', $schedule->hashid));

    assertToast('warning', 'assigned to employees');
    expect(WorkSchedule::withTrashed()->whereKey($schedule->id)->exists())->toBeTrue();

    $this->patch(route('setup.schedule.work-schedules.restore', $schedule->hashid));
    expect(WorkSchedule::query()->whereKey($schedule->id)->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('log_name', 'company-setup')->pluck('description')->all())->toBe([
            'Created work schedule "Day Shift"',
            'Archived work schedule "Day Shift"',
            'Restored work schedule "Day Shift"',
        ]);
});

test('an unassigned archived schedule can be permanently deleted', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.schedule.work-schedules.store'), [
        'name' => 'Spare', 'type' => 'fixed', 'cycle_length_days' => 7, 'grace_minutes' => 0, 'days' => scheduleDays(),
    ]);

    $schedule = WorkSchedule::query()->where('name', 'Spare')->firstOrFail();

    $this->delete(route('setup.schedule.work-schedules.destroy', $schedule->hashid));
    $this->delete(route('setup.schedule.work-schedules.force-delete', $schedule->hashid));

    assertToast('success', 'permanently deleted');
    expect(WorkSchedule::withTrashed()->whereKey($schedule->id)->exists())->toBeFalse();
});
