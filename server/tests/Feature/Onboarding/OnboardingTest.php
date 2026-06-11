<?php

use App\Models\Applicant;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\OnboardingCase;
use App\Models\OnboardingProgram;
use App\Models\OnboardingProgramTask;
use App\Models\OnboardingTask;
use App\Models\Organization;
use App\Models\Position;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A default program with a few blueprint tasks in the current tenant.
 */
function seedProgram(int $tasks = 3): OnboardingProgram
{
    return OnboardingProgram::factory()
        ->has(OnboardingProgramTask::factory()->count($tasks), 'tasks')
        ->default()
        ->create();
}

// ── Overview ────────────────────────────────────────────────────────────────

test('the onboarding overview renders', function () {
    actingAsSuperAdmin();
    OnboardingCase::factory()->count(2)->create();

    $this->get(route('onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/index')
            ->has('cases', 2)
            ->has('stats')
            ->has('options')
            ->has('can')
            ->has('filters'));
});

test('it filters cases by status', function () {
    actingAsSuperAdmin();
    OnboardingCase::factory()->create(['status' => 'in_progress']);
    OnboardingCase::factory()->completed()->create();

    $this->get(route('onboarding.index', ['status' => 'completed']))
        ->assertInertia(fn (Assert $page) => $page->has('cases', 1));
});

// ── Starting onboarding ──────────────────────────────────────────────────────

test('it starts onboarding for an employee and seeds the checklist', function () {
    actingAsSuperAdmin();
    $program = seedProgram(4);
    $employee = Employee::factory()->create();

    $this->post(route('onboarding.store'), [
        'employee_id' => $employee->id,
        'program_id' => $program->id,
    ])->assertSessionHasNoErrors();

    $case = OnboardingCase::where('employee_id', $employee->id)->first();
    expect($case)->not->toBeNull()
        ->and($case->tasks()->count())->toBe(4);
});

test('it will not start a second onboarding for the same employee', function () {
    actingAsSuperAdmin();
    seedProgram();
    $employee = Employee::factory()->create();
    OnboardingCase::factory()->create(['employee_id' => $employee->id]);

    $this->post(route('onboarding.store'), ['employee_id' => $employee->id])
        ->assertSessionHasNoErrors();

    expect(OnboardingCase::where('employee_id', $employee->id)->count())->toBe(1);
});

// ── The case + its checklist ─────────────────────────────────────────────────

test('the case board renders with its tasks', function () {
    actingAsSuperAdmin();
    $case = OnboardingCase::factory()->create();
    OnboardingTask::factory()->count(3)->create(['onboarding_case_id' => $case->id]);

    $this->get(route('onboarding.show', $case))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/case')
            ->has('case')
            ->has('case.tasks', 3)
            ->has('options.assignees')
            ->has('can'));
});

test('it adds an ad-hoc task to a case', function () {
    actingAsSuperAdmin();
    $case = OnboardingCase::factory()->create();

    $this->post(route('onboarding.tasks.store', $case), [
        'title' => 'Order ID badge',
        'category' => 'access',
    ])->assertSessionHasNoErrors();

    expect($case->tasks()->where('title', 'Order ID badge')->exists())->toBeTrue();
});

test('completing a task stamps it and nudges the case to in progress', function () {
    actingAsSuperAdmin();
    $case = OnboardingCase::factory()->create(['status' => 'pending']);
    $task = OnboardingTask::factory()->create([
        'onboarding_case_id' => $case->id,
        'status' => 'pending',
    ]);

    $this->patch(route('onboarding.tasks.status', $task), ['status' => 'done'])
        ->assertSessionHasNoErrors();

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('done')
        ->and($fresh->completed_at)->not->toBeNull()
        ->and($case->fresh()->status)->toBe('in_progress');
});

test('it edits and deletes a task', function () {
    actingAsSuperAdmin();
    $task = OnboardingTask::factory()->create();

    $this->post(route('onboarding.tasks.update', $task), [
        'title' => 'Renamed task',
        'category' => 'training',
    ])->assertSessionHasNoErrors();

    expect($task->fresh()->title)->toBe('Renamed task');

    $this->delete(route('onboarding.tasks.destroy', $task))->assertSessionHasNoErrors();
    expect(OnboardingTask::find($task->id))->toBeNull();
});

test('it completes, cancels and reopens a case', function () {
    actingAsSuperAdmin();
    $case = OnboardingCase::factory()->create(['status' => 'in_progress']);

    $this->patch(route('onboarding.status', $case), ['action' => 'complete'])
        ->assertSessionHasNoErrors();
    expect($case->fresh()->status)->toBe('completed')
        ->and($case->fresh()->completed_at)->not->toBeNull();

    $this->patch(route('onboarding.status', $case), ['action' => 'reopen']);
    expect($case->fresh()->status)->toBe('in_progress');

    $this->patch(route('onboarding.status', $case), ['action' => 'cancel']);
    expect($case->fresh()->status)->toBe('cancelled');
});

test('it updates case notes and target date, and deletes the case', function () {
    actingAsSuperAdmin();
    $case = OnboardingCase::factory()->create();

    $this->post(route('onboarding.update', $case), [
        'notes' => 'Awaiting signed contract',
        'target_end_date' => now()->addMonth()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect($case->fresh()->notes)->toBe('Awaiting signed contract');

    $this->delete(route('onboarding.destroy', $case))->assertSessionHasNoErrors();
    expect(OnboardingCase::find($case->id))->toBeNull();
});

test('a past-due unresolved task surfaces as overdue', function () {
    actingAsSuperAdmin();
    $case = OnboardingCase::factory()->create();
    OnboardingTask::factory()->create([
        'onboarding_case_id' => $case->id,
        'status' => 'pending',
        'due_date' => now()->subWeek()->toDateString(),
    ]);
    OnboardingTask::factory()->done()->create([
        'onboarding_case_id' => $case->id,
        'due_date' => now()->subWeek()->toDateString(),
    ]);

    $this->get(route('onboarding.show', $case))
        ->assertInertia(fn (Assert $page) => $page->where('case.progress.overdue', 1));
});

// ── Programs ─────────────────────────────────────────────────────────────────

test('the programs page renders', function () {
    actingAsSuperAdmin();
    seedProgram();

    $this->get(route('onboarding.programs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/programs')
            ->has('programs', 1)
            ->has('options.departments'));
});

test('it creates a program with blueprint tasks', function () {
    actingAsSuperAdmin();

    $this->post(route('onboarding.programs.store'), [
        'name' => 'Sales Onboarding',
        'is_active' => true,
        'tasks' => [
            ['title' => 'CRM walkthrough', 'category' => 'training', 'due_offset_days' => 3],
            ['title' => 'Shadow a rep', 'category' => 'orientation', 'due_offset_days' => 5],
        ],
    ])->assertSessionHasNoErrors();

    $program = OnboardingProgram::where('name', 'Sales Onboarding')->first();
    expect($program)->not->toBeNull()
        ->and($program->tasks()->count())->toBe(2);
});

test('updating a program replaces its tasks and keeps a single default', function () {
    actingAsSuperAdmin();
    $existingDefault = seedProgram();
    $program = OnboardingProgram::factory()->has(OnboardingProgramTask::factory()->count(2), 'tasks')->create();

    $this->post(route('onboarding.programs.update', $program), [
        'name' => $program->name,
        'is_default' => true,
        'is_active' => true,
        'tasks' => [
            ['title' => 'Only task', 'category' => 'paperwork', 'due_offset_days' => 1],
        ],
    ])->assertSessionHasNoErrors();

    expect($program->fresh()->tasks()->count())->toBe(1)
        ->and($program->fresh()->is_default)->toBeTrue()
        ->and($existingDefault->fresh()->is_default)->toBeFalse();
});

test('it deletes a program', function () {
    actingAsSuperAdmin();
    $program = seedProgram();

    $this->delete(route('onboarding.programs.destroy', $program))->assertSessionHasNoErrors();
    expect(OnboardingProgram::find($program->id))->toBeNull();
});

// ── The recruitment → onboarding bridge ──────────────────────────────────────

test('hiring an applicant starts their onboarding', function () {
    actingAsSuperAdmin();
    seedProgram(5);
    $department = Department::factory()->create();
    $position = Position::factory()->create(['department_id' => $department->id]);
    $posting = JobPosting::factory()->create([
        'department_id' => $department->id,
        'position_id' => $position->id,
        'openings' => 1,
        'status' => 'open',
    ]);
    $applicant = Applicant::factory()->create();
    $application = JobApplication::factory()->create([
        'job_posting_id' => $posting->id,
        'applicant_id' => $applicant->id,
        'stage' => 'offer',
    ]);

    $this->post(route('recruitment.applications.hire', $application))
        ->assertSessionHasNoErrors();

    $employee = $application->fresh()->hiredEmployee;
    expect($employee)->not->toBeNull()
        ->and($employee->onboardingCase)->not->toBeNull()
        ->and($employee->onboardingCase->tasks()->count())->toBe(5);
});

// ── Authorization & isolation ────────────────────────────────────────────────

test('onboarding routes are permission gated', function () {
    actingAsUserWith([]);

    $this->get(route('onboarding.index'))->assertForbidden();
});

test('viewing does not grant managing', function () {
    actingAsUserWith(['onboarding.view']);
    $employee = Employee::factory()->create();

    $this->get(route('onboarding.index'))->assertOk();
    $this->post(route('onboarding.store'), ['employee_id' => $employee->id])->assertForbidden();
});

test('managing programs is gated separately', function () {
    actingAsUserWith(['onboarding.view', 'onboarding.manage']);

    $this->get(route('onboarding.programs.index'))->assertForbidden();
});

test('cases are isolated per organisation', function () {
    actingAsSuperAdmin();
    OnboardingCase::factory()->count(2)->create();

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => OnboardingCase::factory()->count(3)->create());

    $this->get(route('onboarding.index'))
        ->assertInertia(fn (Assert $page) => $page->has('cases', 2));
});
