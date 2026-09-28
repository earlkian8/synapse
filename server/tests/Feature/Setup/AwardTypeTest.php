<?php

use App\Models\ActivityLog;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;

/*
| Company Setup → Award Types, on the screen: every write goes through the
| workflow the assistant shares, with the screen's toasts and audit lines.
*/

test('an award type is created, edited, archived and restored', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.award-types.store'), ['name' => 'Perfect Attendance', 'is_active' => true])->assertSessionHasNoErrors();
    assertToast('success', 'Award type created.');

    $type = AwardType::query()->where('name', 'Perfect Attendance')->firstOrFail();

    $this->post(route('setup.award-types.update', $type->hashid), ['name' => 'Perfect Attendance', 'description' => 'No absences all quarter', 'is_active' => false])->assertSessionHasNoErrors();
    expect($type->fresh()->is_active)->toBeFalse();

    $this->delete(route('setup.award-types.destroy', $type->hashid));
    $this->patch(route('setup.award-types.restore', $type->hashid));

    expect(AwardType::query()->count())->toBe(1)
        ->and(ActivityLog::query()->where('log_name', 'company-setup')->pluck('description')->all())->toBe([
            'Created award type "Perfect Attendance"',
            'Updated award type "Perfect Attendance"',
            'Archived award type "Perfect Attendance"',
            'Restored award type "Perfect Attendance"',
        ]);
});

test('a type that has been given out is never permanently deleted', function () {
    $user = actingAsSuperAdmin();
    $type = AwardType::create(['name' => 'Star', 'is_active' => true]);
    EmployeeAward::create(['employee_id' => Employee::factory()->create()->id, 'award_type_id' => $type->id, 'awarded_on' => '2026-09-01', 'awarded_by' => $user->id]);

    $this->delete(route('setup.award-types.destroy', $type->hashid));
    $this->delete(route('setup.award-types.force-delete', $type->hashid));

    assertToast('warning', 'has been given out');
    expect(AwardType::withTrashed()->whereKey($type->id)->exists())->toBeTrue();
});
