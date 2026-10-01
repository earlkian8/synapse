<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Assistant\Modules\LeaveModule;
use App\Services\Assistant\ToolResult;
use Illuminate\Support\Facades\Notification;

/*
| `leave.request` is self-service: a member of staff files, edits and cancels
| their own leave — somebody else's takes `leave.manage` (ADR 0059). The web
| routes used to accept any employee; the assistant now serves staff the same
| way the phone app does.
*/

beforeEach(fn () => Notification::fake());

function leaveAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(LeaveModule::class)->run($user, $tool, $args);
}

/** A member of staff, linked to their own roster line. @return array{0: User, 1: Employee} */
function staffMember(string $first = 'Sam', string $last = 'Staff'): array
{
    $user = actingAsUserWith(['leave.request', 'attendance.clock']);
    $employee = Employee::factory()->create(['first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix' => null, 'user_id' => $user->id]);

    return [$user, $employee];
}

test('staff cannot file, edit or cancel a colleague’s leave on the web', function () {
    testOrganization();
    $type = LeaveType::factory()->create(['name' => 'Vacation Leave', 'requires_approval' => false]);
    $colleague = Employee::factory()->create();
    $theirs = LeaveRequest::factory()->create(['employee_id' => $colleague->id, 'leave_type_id' => $type->id, 'status' => 'approved']);
    [, $mine] = staffMember();

    $this->post(route('leave.store'), ['employee_id' => $colleague->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05'])
        ->assertSessionHasErrors(['employee_id' => 'You can only file leave for yourself.']);
    $this->patch(route('leave.cancel', $theirs))->assertForbidden();
    $this->post(route('leave.update', $theirs), ['employee_id' => $mine->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05'])->assertForbidden();

    $this->post(route('leave.store'), ['employee_id' => $mine->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05'])->assertSessionHasNoErrors();

    expect(LeaveRequest::query()->where('employee_id', $colleague->id)->count())->toBe(1)
        ->and($theirs->fresh()->status)->toBe('approved')
        ->and(LeaveRequest::query()->where('employee_id', $mine->id)->count())->toBe(1);
});

test('staff get their own leave in chat — and only their own', function () {
    testOrganization();
    $type = LeaveType::factory()->create(['name' => 'Vacation Leave', 'code' => 'VL', 'default_days' => 15]);
    $colleague = Employee::factory()->create(['first_name' => 'Cora', 'middle_name' => null, 'last_name' => 'Colleague', 'suffix' => null]);
    LeaveRequest::factory()->create(['employee_id' => $colleague->id, 'leave_type_id' => $type->id, 'status' => 'pending']);
    [$user, $mine] = staffMember();
    $module = app(LeaveModule::class);

    $filed = leaveAgent($user, 'file_leave_request', ['employee' => 'me', 'leave_type' => 'VL', 'start_date' => '2026-10-05']);
    $forThem = leaveAgent($user, 'file_leave_request', ['employee' => 'Cora Colleague', 'leave_type' => 'VL', 'start_date' => '2026-10-06']);
    $cancelThem = leaveAgent($user, 'cancel_leave_request', ['employee' => 'Cora Colleague']);
    $listed = leaveAgent($user, 'find_leave_requests', ['query' => 'Cora']);
    $balances = leaveAgent($user, 'get_leave_balances');
    $theirBalances = leaveAgent($user, 'get_leave_balances', ['employee' => 'Cora Colleague']);

    expect($module->isAvailable($user))->toBeTrue()
        ->and(array_column($module->tools($user), 'name'))->not->toContain('review_leave_request', 'set_leave_entitlement')
        ->and($filed->failed())->toBeFalse()
        ->and(LeaveRequest::query()->where('employee_id', $mine->id)->exists())->toBeTrue()
        ->and($forThem->detail)->toBe('You can only file leave for yourself.')
        ->and($cancelThem->detail)->toBe('You can only cancel your own leave.')
        ->and(collect($listed->cards)->pluck('title')->unique()->all())->toBe(['Sam Staff'])
        ->and($balances->cards[0]['meta'][0])->toBe('Vacation Leave: 15 left of 15, 1 pending')
        ->and($theirBalances->detail)->toContain("don't have permission");
});

test('an ambiguous name acts on nobody', function () {
    $user = actingAsSuperAdmin();
    $type = LeaveType::factory()->create(['code' => 'VL']);
    Employee::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
    Employee::factory()->create(['first_name' => 'Maria', 'last_name' => 'Reyes']);

    $result = leaveAgent($user, 'file_leave_request', ['employee' => 'Maria', 'leave_type' => 'VL', 'start_date' => '2026-10-05']);

    expect($result->detail)->toContain('More than one person matches')
        ->and(LeaveRequest::query()->count())->toBe(0);
});

test('an entitlement is set through the balances screen’s path, and the card says what is left', function () {
    $user = actingAsSuperAdmin();
    $type = LeaveType::factory()->create(['name' => 'Sick Leave', 'code' => 'SL', 'default_days' => 10]);
    $ana = Employee::factory()->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes', 'suffix' => null]);
    LeaveRequest::factory()->create(['employee_id' => $ana->id, 'leave_type_id' => $type->id, 'status' => 'approved', 'days' => 2, 'start_date' => now()->startOfYear()->addDays(10), 'end_date' => now()->startOfYear()->addDays(11)]);
    $module = app(LeaveModule::class);

    expect($module->requiresConfirmation('set_leave_entitlement'))->toBeTrue()
        ->and($module->consequence($user, 'set_leave_entitlement', ['employee' => 'Ana Reyes', 'leave_type' => 'SL', 'days' => 12]))
        ->toBe("Ana Reyes's Sick Leave for ".now()->year.' would go from 10 to 12 days; with 2 used, 10 would be left.');

    $set = leaveAgent($user, 'set_leave_entitlement', ['employee' => 'Ana Reyes', 'leave_type' => 'SL', 'days' => 12]);
    $bad = leaveAgent($user, 'set_leave_entitlement', ['employee' => 'Ana Reyes', 'leave_type' => 'SL', 'days' => 400]);

    expect($set->detail)->toBe('10 left after 2 used.')
        ->and((float) LeaveBalance::query()->sole()->entitled_days)->toBe(12.0)
        ->and($bad->failed())->toBeTrue()
        ->and(ActivityLog::query()->where('description', 'Set '.now()->year.' leave entitlements for Ana Reyes via assistant')->exists())->toBeTrue();
});

test('a review in chat goes through the inbox’s path — recorded, told to the employee, the note held to the same rules', function () {
    $user = actingAsSuperAdmin();
    $type = LeaveType::factory()->create(['name' => 'Vacation Leave']);
    $linked = User::factory()->create();
    $ana = Employee::factory()->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes', 'suffix' => null, 'user_id' => $linked->id]);
    $leave = LeaveRequest::factory()->create(['employee_id' => $ana->id, 'leave_type_id' => $type->id, 'status' => 'pending']);

    $tooLong = leaveAgent($user, 'review_leave_request', ['employee' => 'Ana Reyes', 'action' => 'approve', 'review_note' => str_repeat('x', 2001)]);
    $approved = leaveAgent($user, 'review_leave_request', ['employee' => 'Ana Reyes', 'action' => 'approve', 'review_note' => 'Enjoy']);

    expect($tooLong->failed())->toBeTrue()
        ->and($approved->failed())->toBeFalse()
        ->and($approved->cards[0]['avatar']['name'])->toBe('Ana Reyes')
        ->and($leave->fresh()->status)->toBe('approved')
        ->and($leave->fresh()->review_note)->toBe('Enjoy')
        ->and(ActivityLog::query()->where('description', 'Approved Vacation Leave for Ana Reyes via assistant')->exists())->toBeTrue()
        ->and(Notification::sent($linked, SystemNotification::class)->count())->toBe(1);
});
