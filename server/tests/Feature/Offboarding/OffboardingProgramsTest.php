<?php

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\OffboardingProgram;
use App\Models\Organization;
use App\Support\Tenancy;

/*
| Company Setup → Offboarding Programs, on the screen: every write goes through
| the workflow the assistant shares, with the screen's toasts and audit lines.
*/

test('a clearance template is created with its sign-offs, edited, and deleted', function () {
    actingAsSuperAdmin();
    $it = Department::factory()->create(['name' => 'IT', 'code' => 'IT']);

    $this->post(route('setup.offboarding.store'), [
        'name' => 'Standard exit',
        'is_default' => true,
        'is_active' => true,
        'items' => [
            ['item' => 'Return laptop', 'department_id' => $it->id, 'use_employee_department' => false, 'sort_order' => 0],
            ['item' => 'Hand over files', 'department_id' => null, 'use_employee_department' => true, 'sort_order' => 1],
        ],
    ])->assertSessionHasNoErrors();
    assertToast('success', 'Template created.');

    $template = OffboardingProgram::query()->where('name', 'Standard exit')->firstOrFail();

    expect($template->is_default)->toBeTrue()
        ->and($template->items()->pluck('item')->all())->toBe(['Return laptop', 'Hand over files']);

    $this->post(route('setup.offboarding.update', $template->hashid), [
        'name' => 'Standard exit',
        'is_default' => true,
        'is_active' => true,
        'items' => [['item' => 'Hand over files', 'department_id' => null, 'use_employee_department' => true]],
    ])->assertSessionHasNoErrors();

    expect($template->items()->pluck('item')->all())->toBe(['Hand over files']);

    $this->delete(route('setup.offboarding.destroy', $template->hashid));

    expect(OffboardingProgram::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('log_name', 'offboarding')->pluck('description')->all())->toBe([
            'Created clearance template "Standard exit"',
            'Updated clearance template "Standard exit"',
            'Deleted clearance template "Standard exit"',
        ]);
});

test('a sign-off owned by a department of another workspace is refused', function () {
    actingAsSuperAdmin();
    $theirs = app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => Department::factory()->create(['code' => 'X']));

    $this->post(route('setup.offboarding.store'), [
        'name' => 'Leaky',
        'is_active' => true,
        'items' => [['item' => 'Sign', 'department_id' => $theirs->id, 'use_employee_department' => false]],
    ])->assertSessionHasErrors('items.0.department_id');
});
