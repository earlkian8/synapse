<?php

use App\Models\Employee;
use App\Models\Organization;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Support\Tenancy;

/*
| The Training screens' writes, now that they run through TrainingWorkflow —
| the same path the assistant takes. The toasts are the screens' contract and
| must read exactly as they did.
*/

function webProgram(array $attributes = []): TrainingProgram
{
    return TrainingProgram::create([
        'name' => 'Excel Basics',
        'start_date' => today()->subDays(2),
        'end_date' => today()->addDays(10),
        ...$attributes,
    ]);
}

test('HR creates, edits and archives a program', function () {
    actingAsSuperAdmin();

    $this->post(route('training.store'), ['name' => 'Data Literacy', 'capacity' => 10])->assertRedirect();
    assertToast('success', 'Training program created.');

    $program = TrainingProgram::query()->where('name', 'Data Literacy')->firstOrFail();

    $this->post(route('training.update', $program), ['name' => 'Data Literacy II', 'capacity' => 12])->assertRedirect();
    assertToast('success', 'Training program updated.');

    expect($program->refresh()->name)->toBe('Data Literacy II');

    $this->delete(route('training.destroy', $program))->assertRedirect(route('training.index'));

    expect(TrainingProgram::find($program->id))->toBeNull();
});

test('enrolling fills the seats that remain and says who was left out', function () {
    actingAsSuperAdmin();
    $program = webProgram(['capacity' => 2]);
    $people = Employee::factory()->count(3)->create(['employment_status' => 'active']);

    $this->post(route('training.enrollments.store', $program), ['employee_ids' => $people->pluck('id')->all()])->assertRedirect();
    assertToast('warning', '2 employees enrolled. 1 left out — the program is now full.');

    $this->post(route('training.enrollments.store', $program), ['employee_ids' => [$people[2]->id]])->assertRedirect();
    assertToast('warning', 'This program is full.');

    $this->post(route('training.enrollments.store', $program), ['employee_ids' => [$people[0]->id]])->assertRedirect();
    assertToast('warning', 'No new employees to enroll');
});

test('an employee of another workspace cannot be enrolled', function () {
    actingAsSuperAdmin();
    $program = webProgram();
    $stranger = app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => Employee::factory()->create());

    $this->post(route('training.enrollments.store', $program), ['employee_ids' => [$stranger->id]])
        ->assertSessionHasErrors('employee_ids.0');

    expect($program->enrollments()->count())->toBe(0);
});

test('the completion stamp follows the status, singly and in bulk', function () {
    actingAsSuperAdmin();
    $program = webProgram();
    $enrollment = $program->enrollments()->create(['employee_id' => Employee::factory()->create()->id, 'status' => 'enrolled']);
    $other = $program->enrollments()->create(['employee_id' => Employee::factory()->create()->id, 'status' => 'enrolled']);

    $this->patch(route('training.enrollments.update', $enrollment), ['status' => 'completed', 'score' => 91])->assertRedirect();

    expect($enrollment->refresh()->completed_at)->not->toBeNull();

    $this->patch(route('training.enrollments.update', $enrollment), ['status' => 'dropped'])->assertRedirect();

    expect($enrollment->refresh()->completed_at)->toBeNull();

    $this->patch(route('training.enrollments.bulk'), ['action' => 'complete', 'enrollment_ids' => [$enrollment->id, $other->id]])->assertRedirect();
    assertToast('success', '2 enrollments marked completed.');

    expect(TrainingEnrollment::query()->whereNotNull('completed_at')->count())->toBe(2);

    $this->patch(route('training.enrollments.bulk'), ['action' => 'remove', 'enrollment_ids' => [$other->id]])->assertRedirect();
    assertToast('success', '1 enrollment removed.');

    $this->delete(route('training.enrollments.destroy', $enrollment))->assertRedirect();
    assertToast('success', 'Enrollment removed.');

    expect($program->enrollments()->count())->toBe(0);
});
