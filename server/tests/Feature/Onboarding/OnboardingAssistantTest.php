<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingProgram;
use App\Models\OnboardingProgramTask;
use App\Models\OnboardingTask;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Assistant\Modules\OnboardingModule;
use App\Services\Assistant\ToolResult;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Notification;

/*
| The onboarding capability of the agentic assistant. Gemini decides *which* tool
| to call; these tests exercise what happens once it has — the module runs each
| action deterministically, permission-checked, validated, logged and (for a
| nudge) notified. No model call is made.
| See App\Services\Assistant\Modules\OnboardingModule.
*/

/** Run one assistant tool as the given user. */
function onboardingAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(OnboardingModule::class)->run($user, $tool, $args);
}

/** The tool names the module advertises to this user. */
function onboardingToolNames(User $user): array
{
    return array_column(app(OnboardingModule::class)->tools($user), 'name');
}

/** A user holding every onboarding permission (but nothing else). */
function onboarder(): User
{
    return actingAsUserWith(['onboarding.view', 'onboarding.manage', 'onboarding.manage-programs']);
}

/**
 * An active user with a predictable full name — the factory otherwise sprinkles
 * middle names in, which the name-matching assertions here care about.
 */
function onboardingUser(string $first, string $last): User
{
    return User::factory()->create([
        'first_name' => $first,
        'middle_name' => null,
        'last_name' => $last,
        'suffix' => null,
        'is_active' => true,
    ]);
}

/** An employee with an onboarding case and a checklist. */
function caseFor(string $first, string $last, array $tasks = []): OnboardingCase
{
    $employee = Employee::factory()->create([
        'first_name' => $first,
        'middle_name' => null,
        'last_name' => $last,
        'suffix' => null,
    ]);
    $case = OnboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'pending']);

    foreach ($tasks as $index => $task) {
        OnboardingTask::factory()->create([
            'onboarding_case_id' => $case->id,
            'sort_order' => $index,
            ...$task,
        ]);
    }

    return $case->load('employee');
}

// ── Tool surface & permissions ────────────────────────────────────────────────

test('the module advertises only the tools the user may run', function () {
    $viewer = actingAsUserWith(['onboarding.view']);
    $names = onboardingToolNames($viewer);

    expect($names)->toContain('find_onboarding_cases', 'find_onboarding_tasks', 'find_onboarding_programs', 'onboarding_summary')
        ->not->toContain('start_onboarding')
        ->not->toContain('set_task_status')
        ->not->toContain('nudge_onboarding_task')
        ->not->toContain('create_onboarding_program');

    // Managing checklists does not grant editing the templates behind them.
    expect(onboardingToolNames(actingAsUserWith(['onboarding.view', 'onboarding.manage'])))
        ->toContain('start_onboarding', 'set_task_status')
        ->not->toContain('create_onboarding_program');

    expect(onboardingToolNames(onboarder()))->toHaveCount(16);
});

test('every mutating tool is refused without its permission', function (string $tool, array $args) {
    $viewer = actingAsUserWith(['onboarding.view']);
    caseFor('Maria', 'Santos', [['title' => 'Sign contract']]);
    OnboardingProgram::factory()->create(['name' => 'Standard Onboarding']);

    $result = onboardingAgent($viewer, $tool, $args);

    expect($result->failed())->toBeTrue()
        ->and($result->detail)->toContain('permission');
})->with([
    ['start_onboarding', ['employee' => 'Maria Santos']],
    ['update_onboarding_case', ['employee' => 'Maria Santos', 'notes' => 'Hi']],
    ['set_onboarding_status', ['employee' => 'Maria Santos', 'action' => 'complete']],
    ['delete_onboarding_case', ['employee' => 'Maria Santos']],
    ['add_onboarding_task', ['employee' => 'Maria Santos', 'title' => 'Nope']],
    ['update_onboarding_task', ['employee' => 'Maria Santos', 'task' => 'Sign', 'title' => 'Nope']],
    ['set_task_status', ['employee' => 'Maria Santos', 'task' => 'Sign', 'status' => 'done']],
    ['remove_onboarding_task', ['employee' => 'Maria Santos', 'task' => 'Sign']],
    ['nudge_onboarding_task', ['employee' => 'Maria Santos']],
    ['create_onboarding_program', ['name' => 'Nope']],
    ['update_onboarding_program', ['program' => 'Standard Onboarding', 'name' => 'Nope']],
    ['delete_onboarding_program', ['program' => 'Standard Onboarding']],
]);

// ── Cases ─────────────────────────────────────────────────────────────────────

test('it finds cases by status, department and slipped work', function () {
    $user = onboarder();
    $engineering = Department::factory()->create(['name' => 'Engineering']);

    $slipping = caseFor('Maria', 'Santos', [['title' => 'Sign contract', 'due_date' => now()->subWeek(), 'status' => 'pending']]);
    $slipping->employee->update(['department_id' => $engineering->id]);

    caseFor('Ben', 'Cruz', [['title' => 'Laptop setup', 'due_date' => now()->addWeek()]]);
    OnboardingCase::factory()->completed()->create();

    expect(onboardingAgent($user, 'find_onboarding_cases', ['status' => 'active'])->cards)->toHaveCount(2)
        ->and(onboardingAgent($user, 'find_onboarding_cases', ['status' => 'completed'])->cards)->toHaveCount(1)
        ->and(onboardingAgent($user, 'find_onboarding_cases', ['department' => 'engineering'])->cards)->toHaveCount(1);

    $overdue = onboardingAgent($user, 'find_onboarding_cases', ['overdue' => true]);
    expect($overdue->cards)->toHaveCount(1)
        ->and($overdue->cards[0]['title'])->toBe('Maria Santos')
        ->and(implode(' ', $overdue->cards[0]['meta']))->toContain('1 overdue');
});

test('it starts onboarding from a named program and refuses a second case', function () {
    $user = onboarder();
    $employee = Employee::factory()->create(['first_name' => 'New', 'last_name' => 'Hire', 'date_hired' => now()->subDay()]);
    $program = OnboardingProgram::factory()->create(['name' => 'Engineering Onboarding']);
    OnboardingProgramTask::factory()->create([
        'onboarding_program_id' => $program->id,
        'title' => 'Issue laptop',
        'category' => 'equipment',
        'due_offset_days' => 3,
        'sort_order' => 0,
    ]);

    $result = onboardingAgent($user, 'start_onboarding', [
        'employee' => 'New Hire',
        'program' => 'engineering onboarding',
    ]);

    $case = OnboardingCase::where('employee_id', $employee->id)->first();

    expect($result->failed())->toBeFalse()
        ->and($case)->not->toBeNull()
        ->and($case->onboarding_program_id)->toBe($program->id)
        ->and($case->tasks()->count())->toBe(1)
        ->and($case->tasks()->first()->title)->toBe('Issue laptop');

    expect(onboardingAgent($user, 'start_onboarding', ['employee' => 'New Hire'])->failed())->toBeTrue();
});

test('it will not invent a program that does not exist', function () {
    $user = onboarder();
    Employee::factory()->create(['first_name' => 'New', 'last_name' => 'Hire']);

    $result = onboardingAgent($user, 'start_onboarding', ['employee' => 'New Hire', 'program' => 'Imaginary Program']);

    expect($result->failed())->toBeTrue()
        ->and(OnboardingCase::count())->toBe(0);
});

test('it retargets a case and records notes', function () {
    $user = onboarder();
    $case = caseFor('Maria', 'Santos');

    $result = onboardingAgent($user, 'update_onboarding_case', [
        'employee' => 'Maria Santos',
        'target_end_date' => now()->addMonth()->toDateString(),
        'notes' => 'Extended while waiting on clearance.',
    ]);

    $case->refresh();

    expect($result->failed())->toBeFalse()
        ->and($case->target_end_date->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and($case->notes)->toBe('Extended while waiting on clearance.');

    expect(onboardingAgent($user, 'update_onboarding_case', ['employee' => 'Maria Santos'])->failed())->toBeTrue();
});

test('it completes, cancels and reopens a case, saying what was left unresolved', function () {
    $user = onboarder();
    $case = caseFor('Maria', 'Santos', [
        ['title' => 'Sign contract', 'status' => 'done'],
        ['title' => 'Collect ID', 'status' => 'pending'],
    ]);

    $completed = onboardingAgent($user, 'set_onboarding_status', ['employee' => 'Maria Santos', 'action' => 'complete']);

    expect($completed->failed())->toBeFalse()
        ->and($completed->detail)->toContain('1 task left unresolved')
        ->and($case->fresh()->status)->toBe('completed')
        ->and($case->fresh()->completed_at)->not->toBeNull();

    onboardingAgent($user, 'set_onboarding_status', ['employee' => 'Maria Santos', 'action' => 'reopen']);
    expect($case->fresh()->status)->toBe('in_progress')
        ->and($case->fresh()->completed_at)->toBeNull();

    onboardingAgent($user, 'set_onboarding_status', ['employee' => 'Maria Santos', 'action' => 'cancel']);
    expect($case->fresh()->status)->toBe('cancelled');
});

test('it deletes a case with its checklist', function () {
    $user = onboarder();
    $case = caseFor('Maria', 'Santos', [['title' => 'Sign contract']]);

    expect(onboardingAgent($user, 'delete_onboarding_case', ['employee' => 'Maria Santos'])->failed())->toBeFalse()
        ->and(OnboardingCase::find($case->id))->toBeNull();
});

test('it says so when an employee has no case at all', function () {
    $user = onboarder();
    Employee::factory()->create(['first_name' => 'Not', 'last_name' => 'Onboarding']);

    $result = onboardingAgent($user, 'set_onboarding_status', ['employee' => 'Not Onboarding', 'action' => 'complete']);

    expect($result->failed())->toBeTrue()
        ->and($result->detail)->toContain('no onboarding case');

    expect(onboardingAgent($user, 'set_onboarding_status', ['employee' => 'Nobody At All', 'action' => 'complete'])->failed())->toBeTrue();
});

// ── Checklist ─────────────────────────────────────────────────────────────────

test('it lists checklist items by owner, overdue state and category', function () {
    $user = onboarder();
    $case = caseFor('Maria', 'Santos', [
        ['title' => 'Sign contract', 'category' => 'paperwork', 'due_date' => now()->subWeek(), 'status' => 'pending'],
        ['title' => 'Issue laptop', 'category' => 'equipment', 'due_date' => now()->addWeek(), 'status' => 'pending'],
        ['title' => 'Grant VPN', 'category' => 'access', 'due_date' => now()->subDay(), 'status' => 'done'],
    ]);
    $case->tasks()->where('title', 'Issue laptop')->update(['assigned_to' => $user->id]);

    expect(onboardingAgent($user, 'find_onboarding_tasks', ['employee' => 'Maria Santos'])->cards)->toHaveCount(3)
        ->and(onboardingAgent($user, 'find_onboarding_tasks', ['overdue' => true])->cards)->toHaveCount(1)
        ->and(onboardingAgent($user, 'find_onboarding_tasks', ['category' => 'equipment'])->cards)->toHaveCount(1)
        ->and(onboardingAgent($user, 'find_onboarding_tasks', ['mine' => true])->cards)->toHaveCount(1)
        ->and(onboardingAgent($user, 'find_onboarding_tasks', ['status' => 'done'])->cards)->toHaveCount(1);
});

test('it adds an ad-hoc task, notifying the person it lands on', function () {
    Notification::fake();
    $user = onboarder();
    $owner = onboardingUser('Ben', 'Cruz');
    $case = caseFor('Maria', 'Santos');

    $result = onboardingAgent($user, 'add_onboarding_task', [
        'employee' => 'Maria Santos',
        'title' => 'Order a monitor',
        'category' => 'equipment',
        'assignee' => 'Ben Cruz',
        'due_date' => now()->addDays(5)->toDateString(),
    ]);

    $task = $case->tasks()->where('title', 'Order a monitor')->first();

    expect($result->failed())->toBeFalse()
        ->and($task)->not->toBeNull()
        ->and($task->category)->toBe('equipment')
        ->and($task->assigned_to)->toBe($owner->id)
        ->and($task->status)->toBe('pending');

    Notification::assertSentTo($owner, SystemNotification::class);
});

test('it refuses to assign a task to somebody who does not exist', function () {
    $user = onboarder();
    caseFor('Maria', 'Santos');

    $result = onboardingAgent($user, 'add_onboarding_task', [
        'employee' => 'Maria Santos',
        'title' => 'Order a monitor',
        'assignee' => 'Nobody Here',
    ]);

    expect($result->failed())->toBeTrue()
        ->and(OnboardingTask::where('title', 'Order a monitor')->exists())->toBeFalse();
});

test('it edits and reassigns a task, and refuses an empty edit', function () {
    Notification::fake();
    $user = onboarder();
    $owner = onboardingUser('Ben', 'Cruz');
    $case = caseFor('Maria', 'Santos', [['title' => 'Issue laptop', 'category' => 'equipment']]);

    $result = onboardingAgent($user, 'update_onboarding_task', [
        'employee' => 'Maria Santos',
        'task' => 'laptop',
        'assignee' => 'Ben Cruz',
        'due_date' => now()->addDays(2)->toDateString(),
    ]);

    $task = $case->tasks()->first();

    expect($result->failed())->toBeFalse()
        ->and($task->assigned_to)->toBe($owner->id)
        ->and($task->due_date->toDateString())->toBe(now()->addDays(2)->toDateString())
        ->and($task->title)->toBe('Issue laptop');

    Notification::assertSentTo($owner, SystemNotification::class);

    expect(onboardingAgent($user, 'update_onboarding_task', ['employee' => 'Maria Santos', 'task' => 'laptop'])->failed())->toBeTrue();
    expect(onboardingAgent($user, 'update_onboarding_task', ['employee' => 'Maria Santos', 'task' => 'nothing like this'])->failed())->toBeTrue();
});

test('ticking a task off stamps it and nudges the case into progress', function () {
    $user = onboarder();
    $case = caseFor('Maria', 'Santos', [
        ['title' => 'Sign contract'],
        ['title' => 'Issue laptop'],
    ]);

    $result = onboardingAgent($user, 'set_task_status', [
        'employee' => 'Maria Santos',
        'task' => 'Sign contract',
        'status' => 'done',
    ]);

    $task = $case->tasks()->where('title', 'Sign contract')->first();

    expect($result->failed())->toBeFalse()
        ->and($result->detail)->toBe('1 of 2 done')
        ->and($task->status)->toBe('done')
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->completed_by)->toBe($user->id)
        ->and($case->fresh()->status)->toBe('in_progress');

    // Skipping counts as resolved but clears the completion stamp.
    onboardingAgent($user, 'set_task_status', ['employee' => 'Maria Santos', 'task' => 'Issue laptop', 'status' => 'skipped']);
    $skipped = $case->tasks()->where('title', 'Issue laptop')->first();
    expect($skipped->status)->toBe('skipped')
        ->and($skipped->completed_at)->toBeNull();
});

test('it prefers the still-open task when two share a title', function () {
    $user = onboarder();
    $case = caseFor('Maria', 'Santos', [
        ['title' => 'Orientation session', 'status' => 'done'],
        ['title' => 'Orientation session', 'status' => 'pending'],
    ]);

    onboardingAgent($user, 'set_task_status', [
        'employee' => 'Maria Santos',
        'task' => 'Orientation session',
        'status' => 'done',
    ]);

    expect($case->tasks()->where('status', 'done')->count())->toBe(2);
});

test('it removes a task from the checklist', function () {
    $user = onboarder();
    $case = caseFor('Maria', 'Santos', [['title' => 'Issue laptop']]);

    expect(onboardingAgent($user, 'remove_onboarding_task', ['employee' => 'Maria Santos', 'task' => 'laptop'])->failed())->toBeFalse()
        ->and($case->tasks()->count())->toBe(0);
});

// ── Chasing ───────────────────────────────────────────────────────────────────

test('it chases one named task and every overdue one, grouped per owner', function () {
    Notification::fake();
    $user = onboarder();
    $ben = onboardingUser('Ben', 'Cruz');
    $ana = onboardingUser('Ana', 'Lim');

    $case = caseFor('Maria', 'Santos', [
        ['title' => 'Sign contract', 'due_date' => now()->subWeek(), 'assigned_to' => $ben->id],
        ['title' => 'Issue laptop', 'due_date' => now()->subDays(3), 'assigned_to' => $ben->id],
        ['title' => 'Grant VPN', 'due_date' => now()->subDay(), 'assigned_to' => $ana->id],
        ['title' => 'Book orientation', 'due_date' => now()->addWeek(), 'assigned_to' => $ana->id],
    ]);

    $one = onboardingAgent($user, 'nudge_onboarding_task', ['employee' => 'Maria Santos', 'task' => 'Sign contract']);
    expect($one->failed())->toBeFalse()
        ->and($one->label)->toContain('Ben Cruz');
    Notification::assertSentToTimes($ben, SystemNotification::class, 1);

    // No task named → every overdue item, one reminder per person (Ben has two).
    $all = onboardingAgent($user, 'nudge_onboarding_task', ['employee' => 'Maria Santos']);
    expect($all->failed())->toBeFalse()
        ->and($all->label)->toContain('Reminded 2 people');
    Notification::assertSentToTimes($ben, SystemNotification::class, 2);
    Notification::assertSentToTimes($ana, SystemNotification::class, 1);

    // Nothing overdue and assigned → nobody to chase.
    $case->tasks()->update(['status' => 'done']);
    expect(onboardingAgent($user, 'nudge_onboarding_task', ['employee' => 'Maria Santos'])->failed())->toBeTrue();
});

test('it will not chase an unassigned or already-finished task', function () {
    Notification::fake();
    $user = onboarder();
    caseFor('Maria', 'Santos', [
        ['title' => 'Sign contract', 'due_date' => now()->subWeek(), 'assigned_to' => null],
        ['title' => 'Issue laptop', 'status' => 'done', 'assigned_to' => $user->id],
    ]);

    $unassigned = onboardingAgent($user, 'nudge_onboarding_task', ['employee' => 'Maria Santos', 'task' => 'Sign contract']);
    expect($unassigned->failed())->toBeTrue()
        ->and($unassigned->detail)->toContain('Nobody is assigned');

    $finished = onboardingAgent($user, 'nudge_onboarding_task', ['employee' => 'Maria Santos', 'task' => 'Issue laptop']);
    expect($finished->failed())->toBeTrue()
        ->and($finished->detail)->toContain('already Done');

    Notification::assertNothingSent();
});

// ── Programs ──────────────────────────────────────────────────────────────────

test('it lists programs and previews which one a hire would get', function () {
    $user = onboarder();
    $engineering = Department::factory()->create(['name' => 'Engineering']);

    OnboardingProgram::factory()->default()->create(['name' => 'Standard Onboarding']);
    $engineeringProgram = OnboardingProgram::factory()->create([
        'name' => 'Engineering Onboarding',
        'department_id' => $engineering->id,
    ]);
    OnboardingProgram::factory()->create(['name' => 'Retired Program', 'is_active' => false]);

    $active = onboardingAgent($user, 'find_onboarding_programs');
    expect($active->cards)->toHaveCount(2)
        ->and($active->cards[0]['title'])->toBe('Standard Onboarding')
        ->and($active->cards[0]['badge'])->toBe('Default');

    expect(onboardingAgent($user, 'find_onboarding_programs', ['include_inactive' => true])->cards)->toHaveCount(3);

    $engineer = Employee::factory()->create([
        'first_name' => 'Dev',
        'last_name' => 'Person',
        'department_id' => $engineering->id,
    ]);

    $match = onboardingAgent($user, 'find_onboarding_programs', ['for_employee' => 'Dev Person']);
    expect($match->cards[0]['title'])->toBe($engineeringProgram->name)
        ->and($match->cards[0]['badge'])->toBe('Best match')
        ->and($engineer->id)->toBeInt();
});

test('it creates a program with a blueprint and keeps a single default', function () {
    $user = onboarder();
    $existing = OnboardingProgram::factory()->default()->create(['name' => 'Standard Onboarding']);

    $result = onboardingAgent($user, 'create_onboarding_program', [
        'name' => 'Field Staff Onboarding',
        'employment_type' => 'contractual',
        'is_default' => true,
        'tasks' => [
            ['title' => 'Sign contract', 'category' => 'paperwork', 'due_offset_days' => 0],
            ['title' => 'Safety briefing', 'category' => 'compliance', 'due_offset_days' => 2],
        ],
    ]);

    $program = OnboardingProgram::where('name', 'Field Staff Onboarding')->first();

    expect($result->failed())->toBeFalse()
        ->and($program->employment_type)->toBe('contractual')
        ->and($program->is_default)->toBeTrue()
        ->and($program->tasks()->count())->toBe(2)
        ->and($program->tasks()->first()->due_offset_days)->toBe(0)
        ->and($existing->fresh()->is_default)->toBeFalse();
});

test('it refuses a program targeted at an unknown department', function () {
    $user = onboarder();

    $result = onboardingAgent($user, 'create_onboarding_program', [
        'name' => 'Ghost Program',
        'department' => 'Department of Mystery',
    ]);

    expect($result->failed())->toBeTrue()
        ->and(OnboardingProgram::where('name', 'Ghost Program')->exists())->toBeFalse();
});

test('editing a program only touches the blueprint when tasks are supplied', function () {
    $user = onboarder();
    $program = OnboardingProgram::factory()->create(['name' => 'Standard Onboarding', 'is_active' => true]);
    OnboardingProgramTask::factory()->create([
        'onboarding_program_id' => $program->id,
        'title' => 'Original task',
        'category' => 'paperwork',
        'due_offset_days' => 1,
    ]);

    onboardingAgent($user, 'update_onboarding_program', [
        'program' => 'Standard Onboarding',
        'name' => 'Standard Onboarding (2026)',
        'is_active' => false,
    ]);

    $program->refresh();
    expect($program->name)->toBe('Standard Onboarding (2026)')
        ->and($program->is_active)->toBeFalse()
        ->and($program->tasks()->count())->toBe(1)
        ->and($program->tasks()->first()->title)->toBe('Original task');

    onboardingAgent($user, 'update_onboarding_program', [
        'program' => 'Standard Onboarding (2026)',
        'tasks' => [
            ['title' => 'Replacement task', 'category' => 'orientation', 'due_offset_days' => 5],
        ],
    ]);

    expect($program->fresh()->tasks()->count())->toBe(1)
        ->and($program->fresh()->tasks()->first()->title)->toBe('Replacement task');
});

test('it deletes a program while in-flight checklists keep their tasks', function () {
    $user = onboarder();
    $program = OnboardingProgram::factory()->create(['name' => 'Standard Onboarding']);
    $case = OnboardingCase::factory()->create(['onboarding_program_id' => $program->id]);
    OnboardingTask::factory()->create(['onboarding_case_id' => $case->id, 'title' => 'Already seeded']);

    expect(onboardingAgent($user, 'delete_onboarding_program', ['program' => 'Standard Onboarding'])->failed())->toBeFalse()
        ->and(OnboardingProgram::find($program->id))->toBeNull()
        ->and($case->fresh()->tasks()->count())->toBe(1);
});

// ── Decision support ──────────────────────────────────────────────────────────

test('it summarises onboarding org-wide and per employee', function () {
    $user = onboarder();
    $owner = onboardingUser('Ben', 'Cruz');
    $case = caseFor('Maria', 'Santos', [
        ['title' => 'Sign contract', 'status' => 'done', 'due_date' => now()->subWeek()],
        ['title' => 'Issue laptop', 'status' => 'pending', 'due_date' => now()->subDay(), 'assigned_to' => $owner->id],
        ['title' => 'Book orientation', 'status' => 'pending', 'due_date' => now()->addWeek()],
    ]);
    $case->update(['target_end_date' => now()->addDays(10)]);

    $overall = onboardingAgent($user, 'onboarding_summary');
    expect($overall->failed())->toBeFalse()
        ->and($overall->cards[0]['title'])->toBe('Onboarding overview')
        ->and($overall->cards[0]['kind'])->toBe('insight')
        ->and($overall->cards[0]['subtitle'])->toContain('1 active case')
        ->and($overall->cards[0]['subtitle'])->toContain('1 overdue task')
        ->and(implode(' ', $overall->cards[0]['meta']))->toContain('unassigned');

    $personal = onboardingAgent($user, 'onboarding_summary', ['employee' => 'Maria Santos']);
    $meta = implode(' | ', $personal->cards[0]['meta']);

    expect($personal->cards[0]['title'])->toBe('Maria Santos')
        ->and($personal->cards[0]['subtitle'])->toContain('1 of 3 tasks done')
        ->and($meta)->toContain('1 overdue')
        ->and($meta)->toContain('target in 10d')
        ->and($meta)->toContain('Next: Issue laptop (Ben Cruz)');
});

// ── Tenancy ───────────────────────────────────────────────────────────────────

test('the agent never sees another organisation\'s onboarding', function () {
    $user = onboarder();
    caseFor('Maria', 'Santos');

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, function () {
        OnboardingCase::factory()->count(3)->create();
    });

    expect(onboardingAgent($user, 'find_onboarding_cases')->cards)->toHaveCount(1);
});
