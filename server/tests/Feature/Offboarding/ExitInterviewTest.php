<?php

use App\Models\ActivityLog;
use App\Models\ClearanceItem;
use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use App\Support\Offboarding\ExitInterviews;
use Illuminate\Support\Str;

/*
| The exit interview (ADR 0076): sent with an exit (not a termination), answered
| by the leaver — which signs off the checklist's exit-interview item — reminded
| about once, waived by HR, and read together on Exit interviews.
*/

/**
 * A leaver with an account and an exit in clearance.
 *
 * @return array{0: User, 1: Employee}
 */
function interviewLeaver(): array
{
    $role = makeRole('leaver-'.Str::random(8), ['offboarding.resign']);
    $user = User::factory()->create(['is_active' => true]);
    $user->roles()->attach($role);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'employment_status' => 'active', 'middle_name' => null, 'suffix' => null]);

    return [$user, $employee];
}

/** @return array<string, int|string> */
function interviewAnswers(array $override = []): array
{
    $answers = ['reason' => 'career', 'recommend' => 'yes', 'return' => 'maybe', 'improve' => 'More feedback.'];

    foreach (array_keys(ExitInterviewQuestionnaire::RATINGS) as $key) {
        $answers["rating_{$key}"] = 4;
    }

    return [...$answers, ...$override];
}

test('starting an exit sends the survey and tells the leaver; a termination does not', function () {
    [$user, $employee] = interviewLeaver();
    actingAsSuperAdmin();

    $this->post(route('offboarding.store'), ['employee_id' => $employee->id, 'type' => 'retirement'])->assertRedirect();

    $interview = ExitInterview::query()->sole();

    expect($interview->status)->toBe('pending')
        ->and($interview->questions)->toBe(ExitInterviewQuestionnaire::questions())
        ->and($user->notifications()->first()->data['title'])->toBe('Tell us about your time here');

    [, $fired] = interviewLeaver();
    $this->post(route('offboarding.store'), ['employee_id' => $fired->id, 'type' => 'termination'])->assertRedirect();

    expect(ExitInterview::count())->toBe(1);

    // HR can send one by hand, once.
    $case = $fired->offboardingCase()->firstOrFail();
    $this->post(route('offboarding.interview.send', $case))->assertRedirect();
    assertToast('success', 'Exit interview sent.');
    $this->post(route('offboarding.interview.send', $case))->assertRedirect();
    assertToast('warning', 'already sent');
});

test('the leaver answers: answers are kept, the exit-interview item is signed off, HR is told', function () {
    [$user, $employee] = interviewLeaver();
    $hr = actingAsUserWith(['offboarding.view', 'offboarding.manage']);
    $case = OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'initiated', 'type' => 'resignation']);
    $item = ClearanceItem::create(['offboarding_case_id' => $case->id, 'item' => 'Conduct exit interview', 'status' => 'pending', 'sort_order' => 1]);
    app(ExitInterviews::class)->send($case);

    $this->actingAs($user);

    $this->get(route('offboarding.me.interview'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('offboarding/exit-interview')
        ->where('interview.can_answer', true)
        ->has('interview.questions', count(ExitInterviewQuestionnaire::questions())));

    $this->post(route('offboarding.me.interview.answer'), ['answers' => interviewAnswers(['rating_pay' => null])])
        ->assertSessionHasErrors('answers.rating_pay');
    $this->post(route('offboarding.me.interview.answer'), ['answers' => interviewAnswers(['reason' => 'boredom'])])
        ->assertSessionHasErrors(['answers.reason' => 'Pick one of the reasons listed.']);
    $this->post(route('offboarding.me.interview.answer'), ['answers' => interviewAnswers(['recommend' => null])])
        ->assertSessionHasErrors(['answers.recommend' => 'Pick yes, maybe or no.']);

    $this->post(route('offboarding.me.interview.answer'), ['answers' => interviewAnswers(['comments' => '   '])])
        ->assertRedirect(route('offboarding.me'));

    $interview = $case->exitInterview()->first();

    expect($interview->status)->toBe('submitted')
        ->and($interview->answers['reason'])->toBe('career')
        ->and($interview->answers)->not->toHaveKey('comments')
        ->and($item->refresh()->status)->toBe('cleared')
        ->and($item->cleared_by)->toBe($user->id)
        ->and($case->refresh()->status)->toBe('clearance')
        ->and($hr->notifications()->first()->data['title'])->toBe('Exit interview answered')
        // The trail says it was answered, never what it said.
        ->and(ActivityLog::query()->where('description', 'like', '%answered their exit interview')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('description', 'like', '%career%')->exists())->toBeFalse();

    $this->post(route('offboarding.me.interview.answer'), ['answers' => interviewAnswers()])->assertRedirect();
    assertToast('warning', 'already answered');
});

test('HR reads the answers on the exit, and the insights add them up', function () {
    [, $employee] = interviewLeaver();
    $case = OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'completed', 'type' => 'resignation']);
    ExitInterview::factory()->create(['offboarding_case_id' => $case->id, 'employee_id' => $employee->id, 'status' => 'submitted', 'submitted_at' => now(), 'answers' => interviewAnswers()]);
    [, $second] = interviewLeaver();
    $other = OffboardingCase::factory()->create(['employee_id' => $second->id, 'status' => 'clearance']);
    ExitInterview::factory()->create(['offboarding_case_id' => $other->id, 'employee_id' => $second->id]);

    actingAsUserWith(['offboarding.view']);

    $this->get(route('offboarding.show', ['case' => $case, 'tab' => 'interview']))->assertOk()->assertInertia(fn ($page) => $page
        ->where('tab', 'interview')
        ->where('interview.status', 'submitted')
        ->where('interview.answers.reason', 'career')
        ->where('interview.average', 4));

    $this->get(route('offboarding.interviews.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('offboarding/interviews')
        ->where('summary.sent', 2)
        ->where('summary.responses', 1)
        ->where('summary.waiting', 1)
        ->where('summary.response_rate', 0.5)
        ->where('summary.reasons.0.key', 'career')
        ->where('summary.recommend.yes', 1)
        ->has('interviews', 2));
});

test('HR waives a waiting survey; the leaver can no longer answer it', function () {
    [$user, $employee] = interviewLeaver();
    $case = OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'clearance', 'type' => 'resignation']);
    app(ExitInterviews::class)->send($case);
    actingAsUserWith(['offboarding.view', 'offboarding.manage']);

    $this->patch(route('offboarding.interview.waive', $case))->assertRedirect();
    assertToast('success', 'Exit interview waived.');

    $this->actingAs($user)->post(route('offboarding.me.interview.answer'), ['answers' => interviewAnswers()])->assertRedirect();
    assertToast('warning', 'no longer needs');
});

test('a waiting survey is reminded about once, after three days, while the exit is in progress', function () {
    [$user, $employee] = interviewLeaver();
    $case = OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'clearance']);
    ExitInterview::factory()->create(['offboarding_case_id' => $case->id, 'employee_id' => $employee->id, 'sent_at' => now()->subDays(4)]);
    [$fresh, $other] = interviewLeaver();
    $recent = OffboardingCase::factory()->create(['employee_id' => $other->id, 'status' => 'clearance']);
    ExitInterview::factory()->create(['offboarding_case_id' => $recent->id, 'employee_id' => $other->id, 'sent_at' => now()->subDay()]);

    $this->artisan('offboarding:exit-interview-reminders')->assertSuccessful();
    $this->artisan('offboarding:exit-interview-reminders')->assertSuccessful();

    expect($user->notifications()->get()->filter(fn ($n) => $n->data['title'] === 'Your exit interview is waiting')->count())->toBe(1)
        ->and($fresh->notifications()->count())->toBe(0);
});

test('the questionnaire adds answers up by key', function () {
    $summary = ExitInterviewQuestionnaire::summarize(collect([
        new ExitInterview(['answers' => interviewAnswers(['reason' => 'pay', 'rating_pay' => 2])]),
        new ExitInterview(['answers' => interviewAnswers(['reason' => 'pay', 'recommend' => 'no'])]),
        new ExitInterview(['answers' => interviewAnswers()]),
    ]));

    $pay = collect($summary['ratings'])->firstWhere('key', 'pay');

    expect($summary['responses'])->toBe(3)
        ->and($summary['reasons'][0])->toMatchArray(['key' => 'pay', 'count' => 2])
        ->and($pay['average'])->toEqual(3.3)
        ->and($pay['distribution'][2])->toBe(1)
        ->and($summary['recommend'])->toBe(['yes' => 2, 'maybe' => 0, 'no' => 1]);
});
