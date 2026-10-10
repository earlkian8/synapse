<?php

use App\Models\Employee;
use App\Models\TrainingBudget;
use App\Models\TrainingProgram;
use App\Models\User;
use App\Services\Assistant\Modules\TrainingModule;
use App\Services\Assistant\ToolResult;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| The assistant's side of ADRs 0074 / 0075, driven by tool name and loosely
| worded arguments the way the model drives it: a participant's own training and
| joining, HR's requests, sessions and attendance, certificates and money.
| Gemini is never called.
*/

function tsaRun(User $user, string $tool, array $args = []): ToolResult
{
    return app(TrainingModule::class)->run($user, $tool, $args);
}

function tsaProgram(string $name, array $attributes = []): TrainingProgram
{
    return TrainingProgram::create([
        'name' => $name,
        'start_date' => today()->addDays(5),
        'end_date' => today()->addDays(9),
        ...$attributes,
    ]);
}

function tsaPerson(string $first, string $last, ?User $user = null): Employee
{
    return Employee::factory()->create([
        'first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix' => null,
        'employment_status' => 'active', 'user_id' => $user?->id,
    ]);
}

beforeEach(function () {
    Notification::fake();
});

test('someone who only takes part is offered their own training and nothing else', function () {
    $user = actingAsUserWith(['training.participate']);

    expect(app(TrainingModule::class)->isAvailable($user))->toBeTrue()
        ->and(array_column(app(TrainingModule::class)->tools($user), 'name'))
        ->toEqualCanonicalizing(['find_my_training', 'find_open_trainings', 'join_training', 'leave_training']);

    expect(tsaRun($user, 'find_training_programs')->detail)->toContain('permission');
});

test('a participant finds what is open, joins one and asks to join another', function () {
    $user = actingAsUserWith(['training.participate']);
    tsaPerson('Lara', 'Quinto', $user);
    tsaProgram('Fire Safety', ['enrollment_mode' => 'open', 'issues_certificate' => true]);
    tsaProgram('Power BI', ['enrollment_mode' => 'approval']);
    tsaProgram('HR Only');

    $open = tsaRun($user, 'find_open_trainings');

    expect(array_column($open->cards, 'title'))->toEqualCanonicalizing(['Fire Safety', 'Power BI']);

    $joined = tsaRun($user, 'join_training', ['program' => 'fire safety']);
    $asked = tsaRun($user, 'join_training', ['program' => 'Power BI']);
    $closed = tsaRun($user, 'join_training', ['program' => 'HR Only']);

    expect($joined->label)->toBe('Joined Fire Safety')
        ->and($asked->label)->toBe('Asked to join Power BI')
        ->and($closed->failed())->toBeTrue()
        ->and($closed->detail)->toContain('HR enrolls people');

    $mine = tsaRun($user, 'find_my_training');

    expect($mine->detail)->toContain('2 programs')
        ->and(collect($mine->cards)->pluck('badge')->all())->toContain('Enrolled', 'Requested');

    $left = tsaRun($user, 'leave_training', ['program' => 'Power BI']);

    expect($left->label)->toBe('Withdrew your request to join Power BI');
});

test('HR lists the requests waiting and decides one; the person is told', function () {
    $hr = actingAsSuperAdmin();
    $program = tsaProgram('Power BI', ['enrollment_mode' => 'approval', 'capacity' => 5]);
    $program->enrollments()->create(['employee_id' => tsaPerson('Ana', 'Reyes')->id, 'status' => 'requested']);
    $program->enrollments()->create(['employee_id' => tsaPerson('Ben', 'Cruz')->id, 'status' => 'requested']);

    expect(tsaRun($hr, 'find_training_requests')->cards)->toHaveCount(2);

    $approved = tsaRun($hr, 'decide_training_request', ['program' => 'Power BI', 'employee' => 'Ana Reyes', 'decision' => 'approve']);
    $declined = tsaRun($hr, 'decide_training_request', ['program' => 'Power BI', 'employee' => 'Ben Cruz', 'decision' => 'decline', 'note' => 'Next run']);
    $again = tsaRun($hr, 'decide_training_request', ['program' => 'Power BI', 'employee' => 'Ben Cruz', 'decision' => 'approve']);

    expect($approved->label)->toBe("Approved Ana Reyes's request to join Power BI")
        ->and($declined->label)->toBe("Declined Ben Cruz's request to join Power BI")
        ->and($again->failed())->toBeTrue()
        ->and($program->enrollments()->pluck('status')->sort()->values()->all())->toBe(['declined', 'enrolled']);
});

test('HR schedules a run of sessions and takes attendance by name', function () {
    $hr = actingAsSuperAdmin();
    // Sessions are judged on the organisation's clock, not the server's.
    $today = CarbonImmutable::parse(OrganizationClock::today());
    $program = tsaProgram('Leadership Lab', ['start_date' => $today->subDays(2), 'end_date' => $today->addDays(10)]);

    foreach ([['Ana', 'Reyes'], ['Ben', 'Cruz'], ['Cara', 'Diaz']] as [$first, $last]) {
        $program->enrollments()->create(['employee_id' => tsaPerson($first, $last)->id, 'status' => 'enrolled']);
    }

    $scheduled = tsaRun($hr, 'schedule_training_session', [
        'program' => 'Leadership Lab',
        'date' => $today->toDateString(),
        'starts_at' => '09:00',
        'ends_at' => '12:00',
        'repeat_until' => $today->addDays(2)->toDateString(),
    ]);

    expect($scheduled->label)->toBe('Scheduled 3 sessions of Leadership Lab')
        ->and($program->sessions()->count())->toBe(3);

    $outside = tsaRun($hr, 'schedule_training_session', ['program' => 'Leadership Lab', 'date' => $today->addMonths(3)->toDateString(), 'starts_at' => '09:00', 'ends_at' => '10:00']);
    expect($outside->failed())->toBeTrue()->and($outside->detail)->toContain('within the program’s dates');

    $taken = tsaRun($hr, 'record_training_attendance', [
        'program' => 'Leadership Lab',
        'date' => $today->toDateString(),
        'absent' => ['Ben Cruz'],
        'everyone_else' => 'present',
    ]);

    expect($taken->label)->toBe('Took attendance at Leadership Lab')
        ->and($taken->detail)->toBe('3 people marked')
        ->and($program->sessions()->chronological()->first()->attendances()->pluck('status')->sort()->values()->all())->toBe(['absent', 'present', 'present']);

    $future = tsaRun($hr, 'record_training_attendance', ['program' => 'Leadership Lab', 'date' => $today->addDay()->toDateString(), 'everyone_else' => 'present']);
    expect($future->failed())->toBeTrue()->and($future->detail)->toContain('from the day of the session');

    $sessions = tsaRun($hr, 'find_training_sessions', ['program' => 'Leadership Lab']);
    expect($sessions->cards)->toHaveCount(3)
        ->and($sessions->cards[0]['meta'])->toContain('2 attended, 1 absent');
});

test('turning certificates on in chat issues them, and the register is read by standing', function () {
    $hr = actingAsSuperAdmin();
    $program = tsaProgram('First Aid', ['start_date' => today()->subMonths(23), 'end_date' => today()->subMonths(23)->addDays(2)]);
    $program->enrollments()->create(['employee_id' => tsaPerson('Ana', 'Reyes')->id, 'status' => 'completed', 'completed_at' => today()->subMonths(23)->addDays(2)]);

    $updated = tsaRun($hr, 'update_training_program', ['program' => 'First Aid', 'certificate_validity_months' => 24]);

    expect($updated->detail)->toContain('1 certificate issued to people who had completed it')
        ->and($program->refresh()->issues_certificate)->toBeTrue();

    $expiring = tsaRun($hr, 'find_training_certificates', ['standing' => 'expiring']);

    expect($expiring->cards)->toHaveCount(1)
        ->and($expiring->cards[0]['badge'])->toBe('Expiring soon')
        ->and($expiring->cards[0]['subtitle'])->toContain('Ana Reyes');

    expect(tsaRun($hr, 'find_training_certificates', ['standing' => 'expired'])->cards)->toBe([]);
});

test('HR records a cost, sets the year’s budget and reads the money back', function () {
    $hr = actingAsSuperAdmin();
    tsaProgram('Sales Bootcamp', ['budget' => 10000]);

    $recorded = tsaRun($hr, 'record_training_cost', ['program' => 'Sales Bootcamp', 'amount' => 12500, 'category' => 'venue']);

    expect($recorded->label)->toBe('Recorded ₱12,500.00 on Sales Bootcamp')
        ->and($recorded->cards[0]['tone'])->toBe('warning');

    expect(tsaRun($hr, 'record_training_cost', ['program' => 'Sales Bootcamp', 'amount' => -1, 'category' => 'venue'])->failed())->toBeTrue();

    $year = (int) now()->year;
    tsaRun($hr, 'set_training_budget', ['year' => $year, 'amount' => 50000]);

    expect((float) TrainingBudget::query()->where('year', $year)->value('amount'))->toBe(50000.0);

    $read = tsaRun($hr, 'get_training_budget', ['year' => $year]);

    expect($read->cards[0]['subtitle'])->toBe('Spent ₱12,500.00 of ₱50,000.00 (25%)');

    $program = tsaRun($hr, 'get_training_budget', ['program' => 'Sales Bootcamp']);

    expect($program->cards[0]['badge'])->toBe('Over budget')
        ->and(implode(' | ', $program->cards[0]['meta']))->toContain('₱2,500.00 over');
});

test('a program read-out says who can join, its sessions, its certificate and its spend', function () {
    $hr = actingAsSuperAdmin();
    $program = tsaProgram('Fire Safety', ['enrollment_mode' => 'approval', 'issues_certificate' => true, 'certificate_validity_months' => 24, 'budget' => 85000]);
    $program->enrollments()->create(['employee_id' => tsaPerson('Ana', 'Reyes')->id, 'status' => 'requested']);
    $program->sessions()->create(['session_date' => today()->addDays(5), 'starts_at' => '09:00', 'ends_at' => '12:00']);
    $program->costs()->create(['category' => 'trainer_fee', 'amount' => 48000, 'incurred_on' => today()]);

    $meta = implode(' | ', tsaRun($hr, 'get_training_program', ['program' => 'Fire Safety'])->cards[0]['meta']);

    expect($meta)->toContain('Anyone can ask to join; HR decides; 1 request waiting')
        ->toContain('1 session, 0 held')
        ->toContain('Earns a certificate, valid 24 months')
        ->toContain('Spent ₱48,000.00 of a ₱85,000.00 budget');
});

test('without an employee record, the participant tools say so', function () {
    $user = actingAsUserWith(['training.participate']);
    tsaProgram('Fire Safety', ['enrollment_mode' => 'open']);

    expect(tsaRun($user, 'find_my_training')->detail)->toContain('not linked to an employee')
        ->and(tsaRun($user, 'join_training', ['program' => 'Fire Safety'])->detail)->toContain('not linked to an employee');
});
