<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\User;
use App\Services\Assistant\Modules\TrainingModule;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Tenancy;

/*
| The training capability of the assistant, driven the way the model drives it:
| by tool name and loosely-worded arguments. Gemini is never called — these
| exercise the layer that decides what actually happens.
*/

function trainingAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(TrainingModule::class)->run($user, $tool, $args);
}

function trainingAgentTools(User $user): array
{
    return array_column(app(TrainingModule::class)->tools($user), 'name');
}

/** A program with the given window (days from today) and capacity. */
function trainingProgram(string $name, ?int $startsIn = -5, ?int $endsIn = 20, ?int $capacity = null): TrainingProgram
{
    return TrainingProgram::create([
        'name' => $name,
        'provider' => 'Acme Learning',
        'start_date' => $startsIn === null ? null : today()->addDays($startsIn),
        'end_date' => $endsIn === null ? null : today()->addDays($endsIn),
        'capacity' => $capacity,
    ]);
}

function trainee(string $first, string $last, string $status = 'active'): Employee
{
    return Employee::factory()->create(['first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix' => null, 'employment_status' => $status]);
}

// ── Permissions ──────────────────────────────────────────────────────────────

test('the module is closed without training.view', function () {
    expect(app(TrainingModule::class)->isAvailable(actingAsUserWith([])))->toBeFalse();
});

test('a viewer is offered only the reads; a manager the writes too', function () {
    $viewer = actingAsUserWith(['training.view']);

    expect(trainingAgentTools($viewer))->toEqualCanonicalizing([
        'find_training_programs', 'get_training_program', 'find_training_enrollments', 'training_summary',
    ]);

    $manager = actingAsUserWith(['training.view', 'training.manage']);

    expect(trainingAgentTools($manager))->toContain('create_training_program', 'enroll_in_training', 'remove_from_training');
});

test('a write is refused at run time without training.manage, even if called', function () {
    actingAsSuperAdmin();
    $program = trainingProgram('Excel Basics');
    trainee('Maria', 'Santos');

    $viewer = actingAsUserWith(['training.view']);
    $result = trainingAgent($viewer, 'enroll_in_training', ['program' => 'Excel Basics', 'employees' => ['Maria Santos']]);

    expect($result->failed())->toBeTrue()
        ->and($result->detail)->toContain('permission')
        ->and($program->enrollments()->count())->toBe(0);
});

test('removing someone and archiving a program are what wait for confirmation', function () {
    $module = app(TrainingModule::class);

    expect($module->requiresConfirmation('remove_from_training'))->toBeTrue()
        ->and($module->requiresConfirmation('archive_training_program'))->toBeTrue()
        ->and($module->requiresConfirmation('enroll_in_training'))->toBeFalse()
        ->and($module->isReadOnly('training_summary'))->toBeTrue()
        ->and($module->isReadOnly('update_training_enrollment'))->toBeFalse();
});

// ── Reading ──────────────────────────────────────────────────────────────────

test('a program read-out carries its seats, outcomes and who is at risk', function () {
    $user = actingAsSuperAdmin();
    $program = trainingProgram('Safety Refresher', startsIn: -30, endsIn: -2, capacity: 10);

    foreach ([['Ana', 'completed', 90], ['Ben', 'enrolled', null], ['Cara', 'dropped', null]] as [$first, $status, $score]) {
        $program->enrollments()->create(['employee_id' => trainee($first, 'Reyes')->id, 'status' => $status, 'score' => $score]);
    }

    $card = trainingAgent($user, 'get_training_program', ['program' => 'safety refresher'])->cards[0];

    expect($card['badge'])->toBe('Completed')
        ->and($card['tone'])->toBe('warning')
        ->and(implode(' | ', $card['meta']))
        ->toContain('2 of 10 seats taken')
        ->toContain('1 completed, 1 in progress, 1 dropped')
        ->toContain('Average score 90')
        ->toContain('Still enrolled after it ended: Ben Reyes')
        ->toContain('Dropped: Cara Reyes');
});

test('a status filter follows the derived lifecycle', function () {
    $user = actingAsSuperAdmin();
    trainingProgram('Running Now', startsIn: -3, endsIn: 3);
    trainingProgram('Next Month', startsIn: 30, endsIn: 40);
    trainingProgram('Last Year', startsIn: -400, endsIn: -380);

    expect(array_column(trainingAgent($user, 'find_training_programs', ['status' => 'upcoming'])->cards, 'title'))->toBe(['Next Month'])
        ->and(array_column(trainingAgent($user, 'find_training_programs', ['status' => 'ongoing'])->cards, 'title'))->toBe(['Running Now']);
});

test('an ambiguous program name is refused rather than guessed', function () {
    $user = actingAsSuperAdmin();
    trainingProgram('Excel Basics');
    trainingProgram('Excel Advanced');

    $result = trainingAgent($user, 'get_training_program', ['program' => 'Excel']);

    expect($result->failed())->toBeTrue()
        ->and($result->detail)->toContain('More than one program')
        ->and(trainingAgent($user, 'get_training_program', ['program' => 'excel advanced'])->failed())->toBeFalse();
});

test('another workspace’s programs are invisible', function () {
    $user = actingAsSuperAdmin();

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => trainingProgram('Their Secret Course'));

    expect(trainingAgent($user, 'get_training_program', ['program' => 'Their Secret Course'])->failed())->toBeTrue()
        ->and(trainingAgent($user, 'find_training_programs', ['query' => 'Secret'])->cards)->toBe([]);
});

// ── Doing ────────────────────────────────────────────────────────────────────

test('enrolling respects capacity and eligibility, and says who was left out', function () {
    $user = actingAsSuperAdmin();
    $program = trainingProgram('Leadership 101', capacity: 2);
    trainee('Ana', 'Cruz');
    trainee('Ben', 'Cruz');
    trainee('Cara', 'Cruz');
    trainee('Dino', 'Cruz', 'resigned');

    $result = trainingAgent($user, 'enroll_in_training', [
        'program' => 'Leadership 101',
        'employees' => ['Ana Cruz', 'Ben Cruz', 'Cara Cruz', 'Dino Cruz'],
    ]);

    expect($result->failed())->toBeFalse()
        ->and($program->enrollments()->count())->toBe(2)
        ->and($result->cards[0]['tone'])->toBe('warning')
        ->and($result->detail)->toContain('1 left out — the program is now full')
        ->and(ActivityLog::query()->where('log_name', 'training')->latest('id')->value('description'))->toContain('via assistant');

    $full = trainingAgent($user, 'enroll_in_training', ['program' => 'Leadership 101', 'employees' => ['Cara Cruz']]);

    expect($full->failed())->toBeTrue()->and($full->detail)->toBe('This program is full.');
});

test('one unknown name in a list enrolls nobody', function () {
    $user = actingAsSuperAdmin();
    $program = trainingProgram('Excel Basics');
    trainee('Ana', 'Cruz');

    $result = trainingAgent($user, 'enroll_in_training', ['program' => 'Excel Basics', 'employees' => ['Ana Cruz', 'Nobody Atall']]);

    expect($result->failed())->toBeTrue()
        ->and($result->detail)->toContain('Nobody Atall')
        ->and($program->enrollments()->count())->toBe(0);
});

test('grading stamps completion and checks the score against the screen’s own rules', function () {
    $user = actingAsSuperAdmin();
    $program = trainingProgram('Excel Basics');
    $maria = trainee('Maria', 'Santos');
    $program->enrollments()->create(['employee_id' => $maria->id, 'status' => 'enrolled']);

    $bad = trainingAgent($user, 'update_training_enrollment', ['program' => 'Excel Basics', 'employee' => 'Maria Santos', 'score' => 140]);

    expect($bad->failed())->toBeTrue()->and($bad->detail)->toContain('100');

    $done = trainingAgent($user, 'update_training_enrollment', ['program' => 'Excel Basics', 'employee' => 'Maria Santos', 'status' => 'completed', 'score' => 92.5]);
    $enrollment = TrainingEnrollment::query()->where('employee_id', $maria->id)->first();

    expect($done->failed())->toBeFalse()
        ->and($enrollment->status)->toBe('completed')
        ->and((float) $enrollment->score)->toBe(92.5)
        ->and($enrollment->completed_at)->not->toBeNull();

    // A remark alone leaves the completion stamp where it was.
    trainingAgent($user, 'update_training_enrollment', ['program' => 'Excel Basics', 'employee' => 'Maria Santos', 'remarks' => 'Strong finish']);

    expect($enrollment->refresh()->completed_at)->not->toBeNull()
        ->and($enrollment->remarks)->toBe('Strong finish');
});

test('creating checks dates, and an update is checked against the program as it would be', function () {
    $user = actingAsSuperAdmin();

    expect(trainingAgent($user, 'create_training_program', ['name' => 'Bad Dates', 'start_date' => '2026-13-40'])->failed())->toBeTrue();

    $created = trainingAgent($user, 'create_training_program', [
        'name' => 'Data Literacy', 'provider' => 'In-house', 'start_date' => '2026-11-02', 'end_date' => '2026-11-20', 'capacity' => 12,
    ]);

    expect($created->failed())->toBeFalse()
        ->and(TrainingProgram::query()->where('name', 'Data Literacy')->value('capacity'))->toBe(12)
        // The same name twice is a second click, not a second program.
        ->and(trainingAgent($user, 'create_training_program', ['name' => 'data literacy'])->failed())->toBeTrue();

    $backwards = trainingAgent($user, 'update_training_program', ['program' => 'Data Literacy', 'end_date' => '2026-10-01']);

    expect($backwards->failed())->toBeTrue()
        ->and(TrainingProgram::query()->where('name', 'Data Literacy')->first()->end_date->toDateString())->toBe('2026-11-20');

    trainingAgent($user, 'update_training_program', ['program' => 'Data Literacy', 'capacity' => 0]);

    expect(TrainingProgram::query()->where('name', 'Data Literacy')->value('capacity'))->toBeNull();
});

test('removing and archiving go through the workflow', function () {
    $user = actingAsSuperAdmin();
    $program = trainingProgram('Excel Basics');
    $program->enrollments()->create(['employee_id' => trainee('Maria', 'Santos')->id, 'status' => 'enrolled']);

    expect(trainingAgent($user, 'remove_from_training', ['program' => 'Excel Basics', 'employee' => 'Maria Santos'])->failed())->toBeFalse()
        ->and($program->enrollments()->count())->toBe(0);

    expect(trainingAgent($user, 'archive_training_program', ['program' => 'Excel Basics'])->failed())->toBeFalse()
        ->and(TrainingProgram::find($program->id))->toBeNull()
        ->and(TrainingProgram::withTrashed()->find($program->id))->not->toBeNull();
});

// ── Retrieval ────────────────────────────────────────────────────────────────

test('a person’s brief lists their trainings, and needs training.view', function () {
    actingAsSuperAdmin();
    $maria = trainee('Maria', 'Santos');
    trainingProgram('Excel Basics')->enrollments()->create(['employee_id' => $maria->id, 'status' => 'completed', 'score' => 88, 'completed_at' => now()]);

    $viewer = actingAsUserWith(['training.view']);
    $section = app(TrainingModule::class)->contextFor($viewer, RetrievedSubject::employee($maria));

    expect($section?->toPrompt())->toContain('1 completed')->toContain('Excel Basics: completed');

    $outsider = actingAsUserWith(['employees.view']);

    expect(app(TrainingModule::class)->contextFor($outsider, RetrievedSubject::employee($maria)))->toBeNull();
});

test('a question about training, naming nobody, reads the training picture', function () {
    $user = actingAsSuperAdmin();
    trainingProgram('Running Now', startsIn: -3, endsIn: 3, capacity: 5);
    $ended = trainingProgram('Old Course', startsIn: -60, endsIn: -30);
    $ended->enrollments()->create(['employee_id' => trainee('Ben', 'Reyes')->id, 'status' => 'enrolled']);

    $brief = app(Retriever::class)->retrieve($user, 'how are our trainings going?');

    expect($brief?->isAboutWorkspace())->toBeTrue()
        ->and($brief->toPrompt())
        ->toContain('Running now: Running Now')
        ->toContain('Needs follow-up (ended, people still enrolled): Old Course 1');
});

test('an exact full name wins over a longer name that contains it; a bare first name does not', function () {
    $user = actingAsSuperAdmin();
    $program = trainingProgram('Excel Basics');
    $maria = trainee('Maria', 'Santos');
    trainee('Maria', 'Santos-Cruz');

    expect(trainingAgent($user, 'enroll_in_training', ['program' => 'Excel Basics', 'employees' => ['Maria Santos']])->failed())->toBeFalse()
        ->and($program->enrollments()->pluck('employee_id')->all())->toBe([$maria->id])
        ->and(trainingAgent($user, 'enroll_in_training', ['program' => 'Excel Basics', 'employees' => ['Maria']])->detail)->toContain('More than one person');
});
