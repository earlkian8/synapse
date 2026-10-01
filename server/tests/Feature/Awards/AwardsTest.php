<?php

use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\Organization;
use App\Support\Tenancy;

/*
| The Awards screens' writes, now that they run through AwardWorkflow — the
| same path the assistant takes.
*/

test('HR gives, edits and removes a recognition', function () {
    $user = actingAsSuperAdmin();
    $type = AwardType::create(['name' => 'Spot Award', 'is_active' => true]);
    $employee = Employee::factory()->create();

    $this->post(route('awards.store'), [
        'employee_id' => $employee->id,
        'award_type_id' => $type->id,
        'awarded_on' => today()->toDateString(),
        'reason' => 'Rescued the release.',
    ])->assertRedirect();
    assertToast('success', 'Recognition given.');

    $award = EmployeeAward::query()->firstOrFail();

    expect($award->awarded_by)->toBe($user->id);

    $this->patch(route('awards.update', $award), [
        'award_type_id' => $type->id,
        'awarded_on' => today()->toDateString(),
        'reason' => 'Rescued the release, twice.',
    ])->assertRedirect();
    assertToast('success', 'Recognition updated.');

    expect($award->refresh()->reason)->toBe('Rescued the release, twice.');

    $this->delete(route('awards.destroy', $award))->assertRedirect();
    assertToast('success', 'Recognition removed.');

    expect(EmployeeAward::query()->count())->toBe(0);
});

test('an award type that is no longer given out is refused, but an award that has it can still be edited', function () {
    actingAsSuperAdmin();
    $retired = AwardType::create(['name' => 'Old Award', 'is_active' => false]);
    $employee = Employee::factory()->create();

    $this->post(route('awards.store'), [
        'employee_id' => $employee->id,
        'award_type_id' => $retired->id,
        'awarded_on' => today()->toDateString(),
    ])->assertRedirect();
    assertToast('warning', 'no longer given out');

    $award = EmployeeAward::create(['employee_id' => $employee->id, 'award_type_id' => $retired->id, 'awarded_on' => '2025-01-10']);

    $this->patch(route('awards.update', $award), [
        'award_type_id' => $retired->id,
        'awarded_on' => '2025-01-10',
        'reason' => 'Kept on the record.',
    ])->assertRedirect();
    assertToast('success', 'Recognition updated.');
});

test('another workspace’s employee or award type is not accepted', function () {
    actingAsSuperAdmin();
    $type = AwardType::create(['name' => 'Spot Award', 'is_active' => true]);
    $employee = Employee::factory()->create();

    [$stranger, $theirType] = app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => [
        Employee::factory()->create(),
        AwardType::create(['name' => 'Their Award', 'is_active' => true]),
    ]);

    $this->post(route('awards.store'), ['employee_id' => $stranger->id, 'award_type_id' => $type->id, 'awarded_on' => today()->toDateString()])
        ->assertSessionHasErrors('employee_id');

    $this->post(route('awards.store'), ['employee_id' => $employee->id, 'award_type_id' => $theirType->id, 'awarded_on' => today()->toDateString()])
        ->assertSessionHasErrors('award_type_id');

    expect(EmployeeAward::query()->count())->toBe(0);
});
