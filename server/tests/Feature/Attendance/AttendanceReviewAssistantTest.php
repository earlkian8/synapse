<?php

use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\Assistant\Modules\AttendanceModule;
use App\Services\Assistant\ToolResult;

/*
| Signing days off and re-applying rules in chat (ADR 0059): the Attendance
| screen's own path, each waiting for a Confirm, and nobody signing off their
| own day.
*/

function dtrAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(AttendanceModule::class)->run($user, $tool, $args);
}

function pendingDay(Employee $employee, string $date, int $overtime = 90): AttendanceRecord
{
    return AttendanceRecord::factory()->create([
        'employee_id' => $employee->id, 'work_date' => $date, 'status' => 'present',
        'overtime_minutes' => $overtime, 'approval_status' => 'pending',
    ]);
}

test('pending days are listed and signed off, one or all — never your own', function () {
    $manager = actingAsUserWith(['attendance.view', 'attendance.manage']);
    $ana = Employee::factory()->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes', 'suffix' => null]);
    $mine = Employee::factory()->create(['user_id' => $manager->id]);
    pendingDay($ana, '2026-09-21');
    pendingDay($ana, '2026-09-22', 60);
    pendingDay($mine, '2026-09-22');
    $module = app(AttendanceModule::class);

    $pending = dtrAgent($manager, 'find_pending_sign_offs');

    expect($pending->detail)->toBe('3 days, 4h of overtime in all')
        ->and($module->requiresConfirmation('sign_off_attendance'))->toBeTrue()
        ->and($module->requiresConfirmation('sign_off_pending_attendance'))->toBeTrue()
        ->and($module->consequence($manager, 'sign_off_pending_attendance', ['employee' => 'Ana Reyes']))->toStartWith('2 days awaiting sign-off would be signed off');

    $one = dtrAgent($manager, 'sign_off_attendance', ['employee' => 'Ana Reyes', 'date' => '2026-09-21']);
    $all = dtrAgent($manager, 'sign_off_pending_attendance', []);

    expect($one->failed())->toBeFalse()
        ->and($all->label)->toBe('Signed off 1 day')
        ->and($all->detail)->toContain('1 left — your own days')
        ->and(AttendanceRecord::query()->where('employee_id', $mine->id)->value('approved_by'))->toBeNull()
        ->and(ActivityLog::query()->where('description', 'like', 'Signed off attendance for Ana Reyes on Sep 21 via assistant')->exists())->toBeTrue();
});

test('rules are re-applied to a range within the screen’s limit', function () {
    $manager = actingAsUserWith(['attendance.view', 'attendance.manage']);
    $ana = Employee::factory()->create();
    pendingDay($ana, '2026-09-21');

    $tooLong = dtrAgent($manager, 'reapply_attendance_rules', ['from' => '2026-01-01', 'to' => '2026-09-01']);
    $done = dtrAgent($manager, 'reapply_attendance_rules', ['from' => '2026-09-20', 'to' => '2026-09-22']);
    $viewer = dtrAgent(actingAsUserWith(['attendance.view']), 'reapply_attendance_rules', ['from' => '2026-09-20', 'to' => '2026-09-22']);

    expect($tooLong->detail)->toContain('at most 62 days')
        ->and($done->label)->toBe('Re-applied the rules to 1 day')
        ->and($viewer->detail)->toContain("don't have permission")
        ->and(ActivityLog::query()->where('description', 'like', '%via assistant')->where('log_name', 'attendance')->exists())->toBeTrue();
});
