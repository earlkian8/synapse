<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\OffboardingCase;
use App\Models\ResignationRequest;
use App\Models\User;
use App\Services\Assistant\Modules\OffboardingModule;
use App\Services\Assistant\ToolResult;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
| The assistant's side of ADR 0076, driven by tool name the way the model
| drives it: HR's resignations and exit interviews, and the user's own exit.
| Gemini is never called.
*/

function exitAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(OffboardingModule::class)->run($user, $tool, $args);
}

/**
 * @return array{0: User, 1: Employee}
 */
function chatLeaver(string $first, string $last, array $permissions = ['offboarding.resign']): array
{
    $role = makeRole('chat-'.Str::random(8), $permissions);
    $user = User::factory()->create(['is_active' => true]);
    $user->roles()->attach($role);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix' => null, 'employment_status' => 'active']);

    return [$user, $employee];
}

function chatDay(int $days): string
{
    return CarbonImmutable::parse(OrganizationClock::today())->addDays($days)->toDateString();
}

test('someone who may only resign is offered their own exit and nothing else', function () {
    [$user] = chatLeaver('Lara', 'Quinto');
    $this->actingAs($user);
    $module = app(OffboardingModule::class);

    expect($module->isAvailable($user))->toBeTrue()
        ->and(array_column($module->tools($user), 'name'))->toEqualCanonicalizing(['get_my_exit', 'file_my_resignation', 'withdraw_my_resignation'])
        ->and(exitAgent($user, 'find_resignations')->detail)->toContain('permission');

    foreach (['decide_resignation', 'waive_exit_interview', 'file_my_resignation', 'withdraw_my_resignation'] as $tool) {
        expect($module->requiresConfirmation($tool))->toBeTrue("{$tool} runs without confirmation");
    }

    expect($module->requiresConfirmation('send_exit_interview'))->toBeFalse();
});

test('the user files, reads and withdraws their own resignation in chat', function () {
    [$user] = chatLeaver('Lara', 'Quinto');
    $this->actingAs($user);

    expect(exitAgent($user, 'file_my_resignation', ['proposed_last_day' => '2000-01-01'])->detail)->toContain('can’t be in the past');

    $filed = exitAgent($user, 'file_my_resignation', ['proposed_last_day' => chatDay(10), 'reason' => 'Moving to Cebu.']);

    expect($filed->failed())->toBeFalse()
        ->and($filed->detail)->toContain('less than the 30')
        ->and(ResignationRequest::query()->sole()->status)->toBe('pending')
        ->and(ActivityLog::query()->where('description', 'like', '%filed a resignation%via assistant')->exists())->toBeTrue();

    expect(exitAgent($user, 'file_my_resignation', ['proposed_last_day' => chatDay(40)])->detail)->toContain('already have a resignation waiting')
        ->and(exitAgent($user, 'get_my_exit')->detail)->toBe('Your resignation is waiting for HR.');

    exitAgent($user, 'withdraw_my_resignation');

    expect(ResignationRequest::query()->sole()->status)->toBe('withdrawn')
        ->and(exitAgent($user, 'withdraw_my_resignation')->detail)->toContain('no resignation waiting');
});

test('HR lists waiting resignations, declines one with a note and accepts another', function () {
    [, $lara] = chatLeaver('Lara', 'Quinto');
    [, $paolo] = chatLeaver('Paolo', 'Reyes');
    ResignationRequest::factory()->create(['employee_id' => $lara->id, 'proposed_last_day' => chatDay(30), 'status' => 'pending']);
    ResignationRequest::factory()->create(['employee_id' => $paolo->id, 'proposed_last_day' => chatDay(45), 'status' => 'pending']);
    $hr = actingAsUserWith(['offboarding.view', 'offboarding.manage']);

    expect(exitAgent($hr, 'find_resignations')->cards)->toHaveCount(2)
        ->and(exitAgent($hr, 'decide_resignation', ['employee' => 'Lara Quinto', 'decision' => 'decline'])->detail)->toContain('Say why');

    exitAgent($hr, 'decide_resignation', ['employee' => 'Lara Quinto', 'decision' => 'decline', 'note' => 'Let us talk first.']);

    $accepted = exitAgent($hr, 'decide_resignation', ['employee' => 'Paolo Reyes', 'decision' => 'accept']);
    $case = OffboardingCase::query()->where('employee_id', $paolo->id)->sole();

    expect($accepted->failed())->toBeFalse()
        ->and(ResignationRequest::query()->where('employee_id', $lara->id)->value('status'))->toBe('declined')
        ->and($case->type)->toBe('resignation')
        ->and($case->last_working_day->toDateString())->toBe(chatDay(45))
        ->and(ExitInterview::query()->where('offboarding_case_id', $case->id)->exists())->toBeTrue()
        ->and(exitAgent($hr, 'find_resignations', ['status' => 'all'])->cards)->toHaveCount(2)
        ->and(exitAgent($hr, 'decide_resignation', ['employee' => 'Paolo Reyes', 'decision' => 'accept'])->detail)->toContain('no resignation waiting');
});

test('HR reads a leaver\'s answers, audited, and what leavers say together', function () {
    [, $lara] = chatLeaver('Lara', 'Quinto');
    $case = OffboardingCase::factory()->create(['employee_id' => $lara->id, 'status' => 'completed', 'type' => 'resignation']);
    $answers = ['reason' => 'pay', 'recommend' => 'yes', 'return' => 'maybe', 'improve' => 'Better pay.'];
    foreach (array_keys(ExitInterviewQuestionnaire::RATINGS) as $key) {
        $answers["rating_{$key}"] = 3;
    }
    ExitInterview::factory()->create(['offboarding_case_id' => $case->id, 'employee_id' => $lara->id, 'status' => 'submitted', 'sent_at' => now()->subDays(5), 'submitted_at' => now(), 'answers' => $answers]);
    $hr = actingAsUserWith(['offboarding.view']);

    $read = exitAgent($hr, 'get_exit_interview', ['employee' => 'Lara Quinto']);
    $meta = implode("\n", $read->cards[0]['meta']);

    expect($read->failed())->toBeFalse()
        ->and($meta)->toContain('Average rating 3 of 5')
        ->and($meta)->toContain('Pay and benefits')
        ->and($meta)->toContain('Better pay.')
        ->and(ActivityLog::query()->where('description', 'Viewed the exit interview of Lara Quinto via assistant')->exists())->toBeTrue();

    $summary = implode("\n", exitAgent($hr, 'exit_interview_summary', ['months' => 6])->cards[0]['meta']);

    expect($summary)->toContain('1 sent, 1 answered')
        ->and($summary)->toContain('Pay and benefits 1 (100%)')
        ->and($summary)->toContain('Would recommend us: Yes 1, Maybe 0, No 0');
});

test('HR sends a survey for a termination and waives a waiting one', function () {
    [, $fired] = chatLeaver('Nico', 'Lim');
    $case = OffboardingCase::factory()->create(['employee_id' => $fired->id, 'status' => 'clearance', 'type' => 'termination']);
    $hr = actingAsUserWith(['offboarding.view', 'offboarding.manage']);

    expect(exitAgent($hr, 'waive_exit_interview', ['employee' => 'Nico Lim'])->detail)->toContain('No exit interview was sent');

    exitAgent($hr, 'send_exit_interview', ['employee' => 'Nico Lim']);

    expect($case->exitInterview()->value('status'))->toBe('pending')
        ->and(exitAgent($hr, 'send_exit_interview', ['employee' => 'Nico Lim'])->detail)->toContain('already sent');

    exitAgent($hr, 'waive_exit_interview', ['employee' => 'Nico Lim']);

    expect($case->exitInterview()->value('status'))->toBe('waived');
});
