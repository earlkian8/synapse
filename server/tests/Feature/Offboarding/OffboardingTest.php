<?php

use App\Models\ActivityLog;
use App\Models\ClearanceItem;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\OffboardingProgram;
use App\Models\Organization;
use App\Support\Tenancy;

/*
| The Offboarding screens' writes, now that they run through OffboardingWorkflow
| — the same path the assistant takes: the lifecycle is guarded, every change
| is audited, and ids from another workspace are refused.
*/

function webExit(array $attributes = []): OffboardingCase
{
    return OffboardingCase::factory()->create(['status' => 'clearance', ...$attributes]);
}

test('HR starts an exit, and cannot start a second one or one for somebody who has left', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create(['employment_status' => 'active']);

    $response = $this->post(route('offboarding.store'), ['employee_id' => $employee->id, 'type' => 'resignation']);
    $case = $employee->offboardingCase()->firstOrFail();

    $response->assertRedirect(route('offboarding.show', $case));
    assertToast('success', 'Offboarding started.');

    $this->post(route('offboarding.store'), ['employee_id' => $employee->id, 'type' => 'resignation'])->assertRedirect();
    assertToast('warning', 'That employee is already being offboarded.');

    $gone = Employee::factory()->create(['employment_status' => 'resigned']);

    $this->post(route('offboarding.store'), ['employee_id' => $gone->id, 'type' => 'resignation'])->assertRedirect();
    assertToast('warning', 'has already left');
});

test('a last working day before the notice date is refused', function () {
    actingAsSuperAdmin();
    $case = webExit();

    $this->post(route('offboarding.update', $case), ['type' => 'resignation', 'notice_date' => '2026-09-10', 'last_working_day' => '2026-09-01'])
        ->assertSessionHasErrors('last_working_day');

    $this->post(route('offboarding.update', $case), ['type' => 'termination', 'notice_date' => '2026-09-01', 'last_working_day' => '2026-09-30'])->assertRedirect();
    assertToast('success', 'Offboarding updated.');

    expect($case->refresh()->type)->toBe('termination')
        ->and(ActivityLog::query()->where('log_name', 'offboarding')->where('description', 'like', 'Updated offboarding%')->exists())->toBeTrue();
});

test('the lifecycle moves the employee, reads properly, and is guarded', function () {
    actingAsSuperAdmin();
    $case = webExit(['type' => 'termination']);
    $employee = $case->employee;

    $this->patch(route('offboarding.status', $case), ['action' => 'complete'])->assertRedirect();
    assertToast('success', 'Offboarding completed.');

    expect($employee->refresh()->employment_status)->toBe('terminated');

    $this->patch(route('offboarding.status', $case), ['action' => 'complete'])->assertRedirect();
    assertToast('warning', 'This exit is already completed.');

    $this->patch(route('offboarding.status', $case), ['action' => 'reopen'])->assertRedirect();
    assertToast('success', 'Offboarding reopened.');

    $this->patch(route('offboarding.status', $case), ['action' => 'cancel'])->assertRedirect();
    assertToast('success', 'Offboarding cancelled.');

    expect($employee->refresh()->employment_status)->toBe('active')
        // The audit line used to read "Canceld" and "Reopend".
        ->and(ActivityLog::query()->where('log_name', 'offboarding')->pluck('description')->implode('|'))
        ->toContain('Cancelled offboarding')->toContain('Reopened offboarding');
});

test('clearance writes are audited, and a department must be this workspace’s', function () {
    $user = actingAsSuperAdmin();
    $case = webExit(['status' => 'initiated']);
    $stranger = app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => Department::factory()->create());

    $this->post(route('offboarding.clearance.store', $case), ['item' => 'Return badge', 'department_id' => $stranger->id])
        ->assertSessionHasErrors('department_id');

    $this->post(route('offboarding.clearance.store', $case), ['item' => 'Return badge'])->assertRedirect();
    $item = ClearanceItem::query()->where('item', 'Return badge')->firstOrFail();

    $this->patch(route('offboarding.clearance.status', $item), ['status' => 'cleared'])->assertRedirect();

    expect($item->refresh()->cleared_by)->toBe($user->id)
        ->and($case->refresh()->status)->toBe('clearance')
        ->and(ActivityLog::query()->where('log_name', 'offboarding')->where('description', 'like', 'Added “Return badge”%')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('log_name', 'offboarding')->where('description', 'like', 'Signed off clearance item “Return badge”%')->exists())->toBeTrue();

    $this->delete(route('offboarding.clearance.destroy', $item))->assertRedirect();

    expect(ActivityLog::query()->where('log_name', 'offboarding')->where('description', 'like', 'Removed clearance item%')->exists())->toBeTrue();
});

test('templates and bulk sign-off keep their messages, and another workspace’s template is refused', function () {
    actingAsSuperAdmin();
    $case = webExit();
    $case->clearanceItems()->create(['item' => 'Return laptop', 'status' => 'pending', 'sort_order' => 0]);

    $program = OffboardingProgram::create(['name' => 'Standard exit', 'is_active' => true]);
    $program->items()->create(['item' => 'Return laptop', 'sort_order' => 0]);
    $program->items()->create(['item' => 'Exit interview', 'sort_order' => 1]);

    $theirs = app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => OffboardingProgram::create(['name' => 'Theirs', 'is_active' => true]));

    $this->post(route('offboarding.clearance.apply-program', $case), ['offboarding_program_id' => $theirs->id])
        ->assertSessionHasErrors('offboarding_program_id');

    $this->post(route('offboarding.clearance.apply-program', $case), ['offboarding_program_id' => $program->id])->assertRedirect();
    assertToast('success', '1 item added from the template.');

    $this->post(route('offboarding.clearance.apply-program', $case), ['offboarding_program_id' => $program->id])->assertRedirect();
    assertToast('warning', 'Every item in that template is already on the checklist.');

    $this->patch(route('offboarding.clearance.bulk-clear', $case), ['scope' => 'all'])->assertRedirect();
    assertToast('success', '2 items cleared.');

    $this->patch(route('offboarding.clearance.bulk-clear', $case), ['scope' => 'all'])->assertRedirect();
    assertToast('warning', 'Nothing pending to clear there.');
});
