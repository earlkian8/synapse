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

// ── Overview: the programs ──────────────────────────────────────────────────

test('the overview lists every program with how its onboarding is going', function () {
    actingAsSuperAdmin();
    $program = seedProgram();
    $quiet = OnboardingProgram::factory()->create(['name' => 'Quiet program']);

    $active = OnboardingCase::factory()->create(['onboarding_program_id' => $program->id, 'status' => 'in_progress']);
    OnboardingTask::factory()->done()->create(['onboarding_case_id' => $active->id]);
    OnboardingTask::factory()->create(['onboarding_case_id' => $active->id, 'status' => 'pending', 'due_date' => now()->subWeek()->toDateString()]);
    OnboardingTask::factory()->create(['onboarding_case_id' => $active->id, 'status' => 'pending', 'due_date' => now()->addWeek()->toDateString()]);
    OnboardingTask::factory()->create(['onboarding_case_id' => $active->id, 'status' => 'skipped']);
    // A finished case counts as completed, and its tasks are no longer progress.
    $done = OnboardingCase::factory()->completed()->create(['onboarding_program_id' => $program->id]);
    OnboardingTask::factory()->create(['onboarding_case_id' => $done->id, 'status' => 'pending', 'due_date' => now()->subWeek()->toDateString()]);

    $this->get(route('onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/index')
            ->has('programs', 2)
            ->where('programs.0.hashid', $program->hashid)
            ->where('programs.0.is_default', true)
            ->where('programs.0.tasks_count', 3)
            ->where('programs.0.cases', ['total' => 2, 'active' => 1, 'completed' => 1])
            ->where('programs.0.progress', 50)
            ->where('programs.0.overdue', 1)
            ->where('programs.1.name', $quiet->name)
            ->where('programs.1.cases.total', 0)
            ->where('programs.1.progress', null)
            ->has('stats')
            ->has('options.programs')
            ->has('options.employees')
            ->has('can')
            ->where('filters.search', ''));
});

test('cases on no program are listed under Unassigned, only when there are any', function () {
    actingAsSuperAdmin();
    seedProgram();

    $this->get(route('onboarding.index'))
        ->assertInertia(fn (Assert $page) => $page->has('programs', 1));

    OnboardingCase::factory()->count(2)->create(['onboarding_program_id' => null]);

    $this->get(route('onboarding.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('programs', 2)
            ->where('programs.1.hashid', null)
            ->where('programs.1.name', 'Unassigned')
            ->where('programs.1.cases.active', 2));
});

test('the overview searches programs by name', function () {
    actingAsSuperAdmin();
    OnboardingProgram::factory()->create(['name' => 'Sales Onboarding']);
    OnboardingProgram::factory()->create(['name' => 'Engineering Onboarding']);

    $this->get(route('onboarding.index', ['search' => 'sales']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('programs', 1)
            ->where('programs.0.name', 'Sales Onboarding')
            ->where('filters.search', 'sales'));
});

test('starting onboarding only offers people on the roster who have no case', function () {
    actingAsSuperAdmin();
    $free = Employee::factory()->create(['employment_status' => 'active']);
    Employee::factory()->create(['employment_status' => 'resigned']);
    OnboardingCase::factory()->create();

    $this->get(route('onboarding.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('options.employees', fn ($employees) => collect($employees)->pluck('id')->all() === [$free->id]));
});

// ── A program: the people it is onboarding ───────────────────────────────────

test('a program lists the people it is onboarding, and no one else', function () {
    actingAsSuperAdmin();
    $program = seedProgram();
    $mine = OnboardingCase::factory()->count(2)->create(['onboarding_program_id' => $program->id]);
    OnboardingCase::factory()->completed()->create(['onboarding_program_id' => $program->id]);
    OnboardingCase::factory()->create(['onboarding_program_id' => OnboardingProgram::factory()->create()->id]);
    OnboardingCase::factory()->create(['onboarding_program_id' => null]);

    $this->get(route('onboarding.programs.show', $program))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/program')
            ->where('program.hashid', $program->hashid)
            ->where('program.id', $program->id)
            ->where('program.tasks_count', 3)
            // Every status by default — the finished case included.
            ->has('cases.data', 3)
            ->where('cases.meta.total', 3)
            ->where('cases.data.0.program.hashid', $program->hashid)
            ->where('stats.active', 2)
            ->where('filters.status', 'all')
            ->has('options.departments')
            ->has('options.employees'));

    $this->get(route('onboarding.programs.show', [$program, 'status' => 'completed']))
        ->assertInertia(fn (Assert $page) => $page->has('cases.data', 1)->where('filters.status', 'completed'));

    expect($mine)->toHaveCount(2);
});

test('the unassigned page lists the cases on no program', function () {
    actingAsSuperAdmin();
    OnboardingCase::factory()->count(2)->create(['onboarding_program_id' => null]);
    OnboardingCase::factory()->create(['onboarding_program_id' => seedProgram()->id]);

    $this->get(route('onboarding.programs.unassigned'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/program')
            ->where('program', null)
            ->has('cases.data', 2)
            ->where('stats.active', 2));
});

test('a program\'s people are searched, filtered, sorted and paged', function () {
    actingAsSuperAdmin();
    $program = seedProgram();
    $sales = Department::factory()->create();
    $case = fn (array $employee) => OnboardingCase::factory()->create([
        'onboarding_program_id' => $program->id,
        'employee_id' => Employee::factory()->create($employee)->id,
    ]);
    $case(['first_name' => 'Zed', 'last_name' => 'Ramos']);
    $case(['first_name' => 'Amy', 'last_name' => 'Cruz', 'department_id' => $sales->id]);
    $case(['first_name' => 'Mia', 'last_name' => 'Reyes']);

    $this->get(route('onboarding.programs.show', [$program, 'search' => 'cruz']))
        ->assertInertia(fn (Assert $page) => $page->has('cases.data', 1)->where('cases.data.0.employee.full_name', fn ($n) => str_starts_with($n, 'Amy')));

    $this->get(route('onboarding.programs.show', [$program, 'department' => $sales->id]))
        ->assertInertia(fn (Assert $page) => $page->has('cases.data', 1));

    $this->get(route('onboarding.programs.show', [$program, 'sort' => 'employee', 'direction' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cases.data.0.employee.full_name', fn ($n) => str_starts_with($n, 'Zed'))
            ->where('cases.data.2.employee.full_name', fn ($n) => str_starts_with($n, 'Amy'))
            ->where('filters.sort', 'employee')
            ->where('filters.direction', 'desc'));

    $this->get(route('onboarding.programs.show', [$program, 'per_page' => 10, 'page' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('cases.meta.per_page', 10)->where('filters.per_page', 10));
});

test('another organisation\'s program cannot be opened', function () {
    actingAsSuperAdmin();
    $theirs = app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => seedProgram());

    $this->get(route('onboarding.programs.show', $theirs->hashid))->assertNotFound();
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
    assertToast('success', 'Onboarding completed.');
    expect($case->fresh()->status)->toBe('completed')
        ->and($case->fresh()->completed_at)->not->toBeNull();

    $this->patch(route('onboarding.status', $case), ['action' => 'reopen']);
    assertToast('success', 'Onboarding reopened.');
    expect($case->fresh()->status)->toBe('in_progress');

    $this->patch(route('onboarding.status', $case), ['action' => 'cancel']);
    assertToast('success', 'Onboarding cancelled.');
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

test('deleting a case returns to the people it was listed with', function () {
    actingAsSuperAdmin();
    $program = seedProgram();
    $onProgram = OnboardingCase::factory()->create(['onboarding_program_id' => $program->id]);
    $onNone = OnboardingCase::factory()->create(['onboarding_program_id' => null]);

    $this->delete(route('onboarding.destroy', $onProgram))
        ->assertRedirect(route('onboarding.programs.show', $program));
    $this->delete(route('onboarding.destroy', $onNone))
        ->assertRedirect(route('onboarding.programs.unassigned'));
});

test('the checklist page knows its program, for the way back', function () {
    actingAsSuperAdmin();
    $program = seedProgram();
    $case = OnboardingCase::factory()->create(['onboarding_program_id' => $program->id]);

    $this->get(route('onboarding.show', $case))
        ->assertInertia(fn (Assert $page) => $page
            ->where('case.program.hashid', $program->hashid)
            ->where('case.program.name', $program->name));
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

    $this->get(route('setup.onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('setup/onboarding')
            ->has('programs', 1)
            ->has('options.departments'));
});

test('it creates a program with blueprint tasks', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.onboarding.store'), [
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

    $this->post(route('setup.onboarding.update', $program), [
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

    $this->delete(route('setup.onboarding.destroy', $program))->assertSessionHasNoErrors();
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
    $application = JobApplication::factory()->stage('offer')->create([
        'job_posting_id' => $posting->id,
        'applicant_id' => $applicant->id,
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
    $program = seedProgram();

    $this->get(route('onboarding.index'))->assertForbidden();
    $this->get(route('onboarding.programs.show', $program))->assertForbidden();
    $this->get(route('onboarding.programs.unassigned'))->assertForbidden();
});

test('viewing does not grant managing', function () {
    actingAsUserWith(['onboarding.view']);
    $employee = Employee::factory()->create();

    $this->get(route('onboarding.index'))->assertOk();
    $this->post(route('onboarding.store'), ['employee_id' => $employee->id])->assertForbidden();
});

test('managing programs is gated separately', function () {
    actingAsUserWith(['onboarding.view', 'onboarding.manage']);

    $this->get(route('setup.onboarding.index'))->assertForbidden();
});

test('cases are isolated per organisation', function () {
    actingAsSuperAdmin();
    OnboardingCase::factory()->count(2)->create();

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => OnboardingCase::factory()->count(3)->create());

    $this->get(route('onboarding.programs.unassigned'))
        ->assertInertia(fn (Assert $page) => $page->has('cases.data', 2)->where('stats.active', 2));
    $this->get(route('onboarding.index'))
        ->assertInertia(fn (Assert $page) => $page->where('programs.0.cases.total', 2)->where('stats.active', 2));
});
