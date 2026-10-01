<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assistant\Modules\LeaveTypesModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Setup\LeaveTypeWorkflow;
use App\Support\Tenancy;

/*
| The leave-types capability of the assistant: the kinds of leave the company
| grants, changed by the Leave Types screen's own rules — a unique, upper-cased
| code — with the reach of an edit on its card. Gemini is never called.
*/

beforeEach(function () {
    $this->travelTo('2026-09-28 10:00:00');
});

function leaveTypesAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(LeaveTypesModule::class)->run($user, $tool, $args);
}

function kindOfLeave(string $name, string $code, float $days = 15): LeaveType
{
    return LeaveType::create(['name' => $name, 'code' => $code, 'color' => '#0ABFBF', 'default_days' => $days, 'is_paid' => true, 'allow_half_day' => true, 'requires_approval' => true, 'is_active' => true]);
}

test('a viewer reads; a manager changes; an edit and an archive wait for a confirm', function () {
    $tools = fn (User $user): array => array_column(app(LeaveTypesModule::class)->tools($user), 'name');

    expect($tools(actingAsUserWith(['setup.leave-types.view'])))->toEqualCanonicalizing(['find_leave_types', 'get_leave_type'])
        ->and($tools(actingAsUserWith(['setup.leave-types.view', 'setup.leave-types.manage'])))->not->toContain('delete_leave_type');

    $module = app(LeaveTypesModule::class);

    expect($module->requiresConfirmation('update_leave_type'))->toBeTrue()
        ->and($module->requiresConfirmation('archive_leave_type'))->toBeTrue()
        ->and($module->requiresConfirmation('create_leave_type'))->toBeFalse();

    $viewer = actingAsUserWith(['setup.leave-types.view']);

    expect(leaveTypesAgent($viewer, 'create_leave_type', ['name' => 'Birthday', 'code' => 'BL', 'default_days' => 1])->detail)->toContain('permission')
        ->and(LeaveType::query()->count())->toBe(0);
});

test('a type is created by the screen’s rules, in a colour not yet worn', function () {
    $user = actingAsSuperAdmin();
    kindOfLeave('Vacation Leave', 'VL');

    $created = leaveTypesAgent($user, 'create_leave_type', ['name' => 'Birthday Leave', 'code' => ' bl ', 'default_days' => 1]);
    $sameCode = leaveTypesAgent($user, 'create_leave_type', ['name' => 'Bereavement', 'code' => 'vl', 'default_days' => 3]);
    $sameName = leaveTypesAgent($user, 'create_leave_type', ['name' => 'vacation leave', 'code' => 'VAC', 'default_days' => 3]);
    $tooMany = leaveTypesAgent($user, 'create_leave_type', ['name' => 'Forever', 'code' => 'FV', 'default_days' => 400]);

    $birthday = LeaveType::query()->where('code', 'BL')->firstOrFail();

    expect($created->failed())->toBeFalse()
        ->and($birthday->color)->toBe(LeaveTypeWorkflow::PALETTE[1])
        ->and($birthday->is_paid && $birthday->allow_half_day && $birthday->requires_approval)->toBeTrue()
        ->and($sameCode->detail)->toContain('code')
        ->and($sameName->detail)->toContain('already a leave type called')
        ->and($tooMany->detail)->toContain('365')
        ->and(ActivityLog::query()->where('description', 'Created leave type "Birthday Leave" via assistant')->exists())->toBeTrue();
});

test('an edit says what it changed, and its card says whom it reaches', function () {
    $user = actingAsSuperAdmin();
    $vacation = kindOfLeave('Vacation Leave', 'VL', 15);
    [$ana, $ben] = Employee::factory()->count(2)->create()->all();
    LeaveBalance::create(['employee_id' => $ana->id, 'leave_type_id' => $vacation->id, 'year' => 2026, 'entitled_days' => 20]);
    LeaveRequest::factory()->create(['employee_id' => $ben->id, 'leave_type_id' => $vacation->id, 'status' => 'pending', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'days' => 1]);

    $line = app(LeaveTypesModule::class)->consequence($user, 'update_leave_type', ['leave_type' => 'VL']);
    $edited = leaveTypesAgent($user, 'update_leave_type', ['leave_type' => 'vacation', 'default_days' => 18, 'half_day' => false]);

    expect($line)->toContain('balance for 1 person without one of their own')->toContain('1 request under it is pending')
        ->and($edited->cards[0]['meta'])->toBe(['Default: 15 days → 18 days', 'Half days: no'])
        ->and((float) $vacation->fresh()->default_days)->toBe(18.0);
});

test('a type reads out this year’s use of it', function () {
    $user = actingAsSuperAdmin();
    $sick = kindOfLeave('Sick Leave', 'SL', 5);
    $employee = Employee::factory()->create();
    LeaveRequest::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $sick->id, 'status' => 'approved', 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'days' => 2]);

    $meta = implode(' | ', leaveTypesAgent($user, 'get_leave_type', ['leave_type' => 'SL'])->cards[0]['meta']);

    expect($meta)->toContain('Default: 5 days a year')->toContain('2026: 1 approved request (2 days taken), 0 pending');
});

test('a partial name two types share is refused, and a restore whose code was reused is refused', function () {
    $user = actingAsSuperAdmin();
    kindOfLeave('Sick Leave', 'SL');
    kindOfLeave('Service Incentive Leave', 'SIL');

    expect(leaveTypesAgent($user, 'get_leave_type', ['leave_type' => 'leave'])->detail)->toContain('More than one leave type matches');

    $old = kindOfLeave('Old Paternity', 'PL');
    $old->delete();
    kindOfLeave('Paternity Leave', 'PL');

    expect(leaveTypesAgent($user, 'restore_leave_type', ['leave_type' => 'Old Paternity'])->detail)->toBe('Another leave type already uses the code "PL".');
});

test('a question about leave types reads the catalogue, and another workspace’s never shows', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();
    kindOfLeave('Vacation Leave', 'VL', 15);

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => kindOfLeave('Their Leave', 'TL'));
    app(Tenancy::class)->set($mine);

    $prompt = app(Retriever::class)->retrieve($user, 'what leave types do we offer?')?->toPrompt();

    expect($prompt)->toContain('Vacation Leave (VL, 15 days; paid, half days allowed, needs approval)')
        ->not->toContain('Their Leave')
        ->and(leaveTypesAgent($user, 'get_leave_type', ['leave_type' => 'TL'])->failed())->toBeTrue();
});
